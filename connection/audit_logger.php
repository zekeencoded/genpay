<?php

/**
 * Central, append-only audit logger.
 *
 * Storage is hardened by `database/migrations/audit_hardening.php`, which is the
 * ONLY thing allowed to touch this table's shape. It runs as root from the CLI
 * and it:
 *   - adds the columns listed in gjc_audit_required_columns()
 *   - installs BEFORE UPDATE / BEFORE DELETE triggers that SIGNAL an error, so
 *     the table is append-only at the database level, not just by convention
 *   - creates the `gjc_audit_writer` account with INSERT + SELECT on this one
 *     table and nothing else, and writes its password to
 *     connection/audit_config.php
 *
 * Nothing in the request path issues DDL any more. This file used to run seven
 * ALTER TABLEs and one UPDATE on every single page load, which meant the app
 * connection needed ALTER on the audit table — and a connection that can ALTER
 * the audit log can also rewrite it. gjc_ensure_audit_table() is now a cached
 * read-only check that shouts into the error log if the migration hasn't run.
 *
 * The connection is separate from the app's `$db` by design and has no fallback
 * to it. If the audit credentials are missing, logging stops loudly rather than
 * quietly downgrading to an account that could tamper with what it writes.
 */

/**
 * Columns `database/migrations/audit_hardening.php` is responsible for adding.
 * Used both by the migration (to know what to create) and by the runtime check
 * below (to know what to complain about).
 */
function gjc_audit_required_columns(): array
{
    return [
        'target_id',
        'outcome',
        'reason',
        'sub_role',
        'correlation_id',
        'endpoint',
        'http_method',
        'session_ref',
        'prev_hash',
        'entry_hash',
    ];
}

/**
 * Which of gjc_audit_required_columns() are not on the live table.
 *
 * Cached for the request — every page that logs anything calls this, and one
 * SHOW COLUMNS per request is the whole budget we want to spend on it.
 * Returns the sentinel ['__table_missing__'] when the table isn't there at all.
 */
function gjc_audit_schema_missing_columns(PDO $pdo, bool $refresh = false): array
{
    static $missing = null;

    if ($missing !== null && !$refresh) {
        return $missing;
    }

    try {
        $rows = $pdo->query("SHOW COLUMNS FROM systemic_audit_trail")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $missing = ['__table_missing__'];
            return $missing;
        }
        $live = array_map(static fn(array $r): string => (string) $r['Field'], $rows);
        $missing = array_values(array_diff(gjc_audit_required_columns(), $live));
    } catch (Throwable $e) {
        $missing = ['__table_missing__'];
    }

    return $missing;
}

/**
 * Read-only health check. This used to create the table and run seven ALTERs on
 * every request; it now only looks, and tells the operator to run the migration
 * if the shape is wrong. Same signature and same call sites as before, so the
 * pages that call it with the app connection keep working unchanged.
 */
function gjc_ensure_audit_table(PDO $pdo): void
{
    static $warned = false;

    $missing = gjc_audit_schema_missing_columns($pdo);
    if (!$missing || $warned) {
        return;
    }
    $warned = true;

    $what = $missing === ['__table_missing__']
        ? 'systemic_audit_trail is missing or unreadable'
        : 'systemic_audit_trail is missing columns: ' . implode(', ', $missing);

    error_log(
        '[audit] ' . $what
        . ' — run: php database/migrations/audit_hardening.php'
    );
}

/**
 * Credentials for the audit connection, in precedence order:
 * environment variables, then connection/audit_config.php.
 *
 * Returns null when neither supplies a user and a password. There is no root
 * fallback on purpose — see the note at the top of this file.
 */
function gjc_audit_credentials(): ?array
{
    static $creds = null;
    static $loaded = false;

    if ($loaded) {
        return $creds;
    }
    $loaded = true;

    $file = [];
    $path = __DIR__ . '/audit_config.php';
    if (is_file($path)) {
        try {
            $loadedFile = require $path;
            if (is_array($loadedFile)) {
                $file = $loadedFile;
            }
        } catch (Throwable $e) {
            error_log('[audit] connection/audit_config.php could not be read: ' . $e->getMessage());
        }
    }

    $pick = static function (string $env, string $key) use ($file): ?string {
        $value = getenv($env);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }
        $value = $file[$key] ?? null;
        return ($value === null || $value === '') ? null : (string) $value;
    };

    $user = $pick('AUDIT_DB_USER', 'user');
    $pass = $pick('AUDIT_DB_PASS', 'pass');

    if ($user === null || $pass === null || $pass === 'REPLACE_WITH_THE_AUDIT_WRITER_PASSWORD') {
        return null;
    }

    $creds = [
        'host' => $pick('AUDIT_DB_HOST', 'host') ?? 'localhost',
        'port' => $pick('AUDIT_DB_PORT', 'port') ?? '3306',
        'name' => $pick('AUDIT_DB_NAME', 'name') ?? 'ewallet',
        'user' => $user,
        'pass' => $pass,
    ];

    return $creds;
}

/**
 * The audit connection, or null if it cannot be opened.
 *
 * A failure is cached for the rest of the request so a dead audit database
 * costs one connection attempt per request instead of one per logged action.
 */
function gjc_audit_pdo(): ?PDO
{
    static $auditPdo = null;
    static $failed = false;

    if ($auditPdo instanceof PDO) {
        return $auditPdo;
    }
    if ($failed) {
        return null;
    }

    $creds = gjc_audit_credentials();
    if ($creds === null) {
        $failed = true;
        error_log(
            '[audit] no audit database credentials — copy connection/audit_config.example.php '
            . 'to connection/audit_config.php, or run: php database/migrations/audit_hardening.php. '
            . 'Audit logging is DISABLED until this is fixed (the app connection is never used as a fallback).'
        );
        return null;
    }

    $dsn = "mysql:host={$creds['host']};dbname={$creds['name']};charset=utf8mb4;port={$creds['port']}";

    try {
        $auditPdo = new PDO($dsn, $creds['user'], $creds['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Timestamps are written and read as UTC on this connection regardless of
        // what the server or the app connection is set to, so the hash chain
        // hashes the same string everywhere and a server tz change can't shift
        // historic rows.
        $auditPdo->exec("SET time_zone = '+00:00'");
    } catch (Throwable $e) {
        $failed = true;
        $auditPdo = null;
        error_log(
            '[audit] could not connect as "' . $creds['user'] . '": ' . $e->getMessage()
            . ' — audit logging is DISABLED for this request (the app connection is never used as a fallback).'
        );
        return null;
    }

    return $auditPdo;
}

/**
 * Recursively blank out anything that looks like a credential before it is
 * written to the log. Applied to old_value/new_value on every call, and by the
 * hardening migration to legacy rows.
 *
 * Flag-shaped values are kept: `force_password_change: 1` and
 * `password_changed: true` match the key pattern but carry no secret, and
 * redacting them would throw away the only signal those entries have. A numeric
 * PIN (`pin: 1234`) is not flag-shaped and is still redacted.
 */
function gjc_audit_redact($value)
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $child) {
            $out[$key] = gjc_audit_sensitive_key((string) $key) && !gjc_audit_flag_shaped($child)
                ? '[REDACTED]'
                : gjc_audit_redact($child);
        }
        return $out;
    }

    if (is_object($value)) {
        return gjc_audit_redact(get_object_vars($value));
    }

    return $value;
}

function gjc_audit_sensitive_key(string $key): bool
{
    return (bool) preg_match(
        '/(password|pass|pwd|pin|otp|token|secret|remember|api_key)/i',
        $key
    );
}

/** true for booleans and 0/1 — an on/off flag, never a secret. */
function gjc_audit_flag_shaped($value): bool
{
    if (is_bool($value) || $value === null) {
        return true;
    }
    if (is_int($value) || is_float($value)) {
        return (float) $value === 0.0 || (float) $value === 1.0;
    }
    return false;
}

/* ── Action types ───────────────────────────────────────────────────────────
 *
 * ONE list. It used to live in three places that had to be edited together —
 * the DB enum, a `$allowedActions` array inside logAudit(), and the filter in
 * admin/audit_log.php — and forgetting the second one made logAudit() return
 * without inserting anything, silently. The column is now VARCHAR, so this
 * constant is the only gate, and admin/audit_log.php builds its filter from it.
 */

const AUDIT_ACTION_TYPES = [
    // Authentication
    'LOGIN',
    'LOGOUT',
    'LOGIN_FAILED',          // bad or unknown credentials
    'LOGIN_BLOCKED',         // credentials were right, the account is not allowed in
    'PASSWORD_CHANGE',
    'ACCESS_DENIED',         // signed in, but not for this

    // Money
    'TRANSACTION',
    'MINT_ATTEMPT',          // every MintingGuard outcome, allowed or refused
    'MINT_PIN_SET',

    // Accounts
    'USER_ACCOUNT',
    'USER_IMPORT',
    'MERCHANT_CREATE',
    'MERCHANT_ONBOARDING',   // a merchant account born out of a stall award

    // Merchant operations
    'MENU_MUTATION',
    'PRODUCT_RESTRICTION',
    'STALL_UPDATE',
    'MERCHANT_PROFILE_UPDATE', // display name / logo on the Business Profile page

    // Parent portal
    'PARENT_CONTROL',        // freeze, spend limit, alert threshold
    'PARENT_LINK',           // link / unlink a student

    // Finance and academic administration
    'FEE_WAIVER_STATUS_CHANGE',
    'MAINTENANCE_FEE_COLLECTED',
    'SYSTEM_SETTINGS_CHANGE',
    'SCHOOL_YEAR_CREATED',
    'SCHOOL_YEAR_ROLLOVER',
    'STUDENT_GRADUATED',
    'SY_TXN_BACKFILL',
    'ENROLLMENT_STATUS_SET',
    'ROSTER_UPLOADED',
    'ROSTER_APPLIED',

    // Reading data is an action too
    'DOCUMENT_VIEW',
    'DATA_EXPORT',

    // Backup & restore (admin/backup.php, database/backup_cron.php)
    'BACKUP_CREATE',
    'BACKUP_DOWNLOAD',
    'BACKUP_RESTORE',
    'BACKUP_DELETE',

    // Written only by database/migrations/audit_hardening.php
    'MIGRATION_REDACTION',
    'MIGRATION_ROLE_RELABEL',
];

/**
 * Retired labels. Still in the table, so the viewer must be able to filter on
 * them, but rejected for new writes.
 *
 * TUITION_CREDIT belonged to admin/api/tuition_credits.php, which no longer
 * exists — fee waivers replaced it and log FEE_WAIVER_STATUS_CHANGE. Two rows
 * survive from the old feature.
 */
const AUDIT_LEGACY_ACTION_TYPES = [
    'TUITION_CREDIT',
];

/** Every label the viewer may filter on; only the non-legacy ones may be written. */
function gjc_audit_action_types(bool $includeLegacy = false): array
{
    $types = AUDIT_ACTION_TYPES;
    if ($includeLegacy) {
        $types = array_merge($types, AUDIT_LEGACY_ACTION_TYPES);
    }
    sort($types);
    return $types;
}

/* ── Display helpers (used by the audit viewer) ────────────────── */

/**
 * Format a stored audit timestamp in Asia/Manila.
 *
 * Takes a UNIX epoch, not the raw column. A MySQL TIMESTAMP is rendered in
 * whatever the reading session's time_zone happens to be — +08 on this server
 * today, UTC on the audit connection, something else after a server move. So
 * the viewer selects UNIX_TIMESTAMP(timestamp), which is tz-independent, and
 * this converts it once, explicitly, here.
 */
function gjc_audit_manila(?int $epoch, string $format = 'M j, Y g:i A'): string
{
    if ($epoch === null) {
        return '—';
    }

    try {
        return (new DateTimeImmutable('@' . $epoch))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format($format);
    } catch (Throwable $e) {
        return '—';
    }
}

/**
 * True when this entry predates the hash chain and cannot be verified.
 * Not the same as "broken" — unverifiable, and badged as such in the viewer.
 */
function gjc_audit_is_legacy_row(array $row): bool
{
    return ($row['entry_hash'] ?? null) === null;
}

/**
 * Display name for affected_table.
 *
 * Entries written before Phase 3 name "e_wallet_transactions", a table that has
 * never existed in this database — the real one is `transactions`. Those rows
 * cannot be rewritten (the log is append-only), so the viewer relabels them
 * instead of pretending the value was always right.
 */
function gjc_audit_table_label(?string $table): string
{
    $table = (string) $table;
    return $table === 'e_wallet_transactions' ? 'transactions (legacy)' : $table;
}

/**
 * Whether the audit writer is usable, for the viewer's warning banner.
 * Returns [ok, message].
 */
function gjc_audit_health(PDO $db): array
{
    $missing = gjc_audit_schema_missing_columns($db);
    if ($missing === ['__table_missing__']) {
        return [false, 'The systemic_audit_trail table is missing or unreadable. Run: php database/migrations/audit_hardening.php'];
    }
    if ($missing) {
        return [false, 'The audit table is missing these columns: ' . implode(', ', $missing)
            . '. Run: php database/migrations/audit_hardening.php'];
    }
    if (gjc_audit_credentials() === null) {
        return [false, 'No audit database credentials are configured, so nothing is being logged. '
            . 'Copy connection/audit_config.example.php to connection/audit_config.php, or run the hardening migration.'];
    }
    if (gjc_audit_pdo() === null) {
        return [false, 'The audit database connection could not be opened, so nothing is being logged. Check the PHP error log.'];
    }

    return [true, ''];
}

/* ── Request context ────────────────────────────────────────────────────── */

/**
 * One id shared by every entry this request writes, so the five rows a stall
 * award produces can be pulled back together as one action.
 */
function gjc_audit_correlation_id(): string
{
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(16));
    }
    return $id;
}

/**
 * A stable, non-reversible handle for the current session. Ties a user's
 * entries together without putting a live session id in a table that finance
 * staff can read.
 */
function gjc_audit_session_ref(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $sid = session_id();
    return ($sid === '' || $sid === false) ? null : substr(hash('sha256', $sid), 0, 16);
}

/**
 * The client address. Deliberately REMOTE_ADDR only: X-Forwarded-For is
 * attacker-controlled unless a trusted proxy is in front rewriting it, and
 * there is no such proxy here. A forgeable IP in an audit log is worse than a
 * boring one.
 */
function gjc_audit_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Map any role string the app uses onto one of the stored user_role labels.
 *
 * Every label this returns must also be accepted as INPUT: logAudit() feeds
 * gjc_audit_role_from_user()'s output straight back through here, so a label
 * that does not round-trip would be silently downgraded.
 *
 * 'parent' was missing until now and fell through to the default, so every
 * parent action — logins, password changes, allowance transfers, the wallet
 * controls — was filed under 'Student'. Filtering the audit log by Student
 * returned a guardian's activity. 30 rows were affected before this was fixed;
 * database/migrations/audit_hardening.php relabels them.
 */
function gjc_audit_role(string $role): string
{
    $role = strtolower(trim($role));

    return match ($role) {
        'gjc admin', 'admin', 'finance', 'super_admin', 'cashier' => 'Finance',
        'student' => 'Student',
        'merchant', 'merchant_admin' => 'Merchant',
        'vendor/staff', 'vendor', 'staff', 'merchant_staff' => 'Vendor/Staff',
        'parent' => 'Parent',
        'system' => 'System',
        'anonymous' => 'Anonymous',
        'unknown' => 'Unknown',
        // An unrecognised role is a bug, not a student. Guessing 'Student' here
        // put words in a real role's mouth and hid the mistake; say so instead.
        default => gjc_audit_role_unknown($role),
    };
}

function gjc_audit_role_unknown(string $role): string
{
    if ($role !== '') {
        error_log("[audit] unrecognised role '{$role}' — recorded as 'Unknown'. Add it to gjc_audit_role().");
    }

    return 'Unknown';
}

function gjc_audit_role_from_user(PDO $pdo, int $userId): string
{
    try {
        // roleID is the single source of truth for the role (users.sub_role is derived).
        $stmt = $pdo->prepare("SELECT roleID FROM users WHERE userID = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return gjc_audit_role((string) ($_SESSION['role'] ?? 'student'));
        }

        return match ((int) ($user['roleID'] ?? 0)) {
            1 => 'Student',
            2, 5 => 'Merchant',
            3, 4, 8 => 'Finance',
            6 => 'Vendor/Staff',
            7 => 'Parent',
            // Falling back to 'student' here was the same guess by another
            // route: it turned a roleID this function does not know about into
            // a student. An absent session role now reads as Unknown.
            default => gjc_audit_role((string) ($_SESSION['role'] ?? '')),
        };
    } catch (Throwable) {
        return gjc_audit_role((string) ($_SESSION['role'] ?? ''));
    }
}

/* ── Payload encoding ───────────────────────────────────────────────────── */

const AUDIT_PAYLOAD_MAX_BYTES = 16384;

/**
 * Redact, encode, and cap. Oversized payloads used to throw inside the INSERT
 * under STRICT_TRANS_TABLES and take the whole entry down with them; now the
 * entry survives and says it was cut.
 */
function gjc_audit_json($value): ?string
{
    if ($value === null) {
        return null;
    }

    $encoded = json_encode(
        gjc_audit_redact($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    if ($encoded === false) {
        return json_encode(['_unencodable' => true]);
    }

    if (strlen($encoded) > AUDIT_PAYLOAD_MAX_BYTES) {
        $encoded = json_encode([
            '_truncated'     => true,
            '_original_bytes' => strlen($encoded),
            '_preview'       => substr($encoded, 0, 4000),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return $encoded;
}

/* ── Hash chain ─────────────────────────────────────────────────────────── */

/**
 * The stored fields that are hashed, in a fixed order. Both the writer and
 * gjc_audit_verify_chain() build their canonical string from this list, so the
 * two cannot drift.
 *
 * log_id is not in it — it isn't known before the INSERT. Ordering and deletion
 * are caught by prev_hash linkage instead.
 */
function gjc_audit_chain_fields(): array
{
    return [
        'user_id', 'user_role', 'sub_role', 'action_type', 'stall_id',
        'affected_table', 'target_id', 'outcome', 'reason', 'correlation_id',
        'old_value', 'new_value', 'ip_address', 'user_agent', 'endpoint',
        'http_method', 'session_ref', 'timestamp',
    ];
}

/**
 * sha256(prev_hash | canonical JSON of this row).
 *
 * Types are normalised on both sides — user_id as int, everything else as a
 * string or null — because what PDO hands back on a read is not always what PHP
 * handed in on the write.
 */
function gjc_audit_chain_hash(?string $prevHash, array $row): string
{
    $canonical = [];
    foreach (gjc_audit_chain_fields() as $field) {
        $value = $row[$field] ?? null;
        if ($value === null) {
            $canonical[$field] = null;
            continue;
        }
        $canonical[$field] = $field === 'user_id' ? (int) $value : (string) $value;
    }

    return hash(
        'sha256',
        (string) $prevHash . '|' . json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Walk the chain and report the first row that does not add up.
 *
 * Two different breakages are detected: a row whose entry_hash no longer
 * matches its own contents (edited), and a row whose prev_hash does not match
 * the entry_hash of the row before it (something removed or reordered).
 *
 * Rows written before the chain existed have entry_hash NULL and are skipped —
 * they are unverifiable, not broken, and the viewer badges them as legacy.
 */
function gjc_audit_verify_chain(?int $fromLogId = null): array
{
    $result = [
        'ok'          => true,
        'checked'     => 0,
        'first_log_id' => null,
        'last_log_id' => null,
        'broken_log_id' => null,
        'reason'      => null,
    ];

    $auditDb = gjc_audit_pdo();
    if ($auditDb === null) {
        return ['ok' => false, 'checked' => 0, 'first_log_id' => null, 'last_log_id' => null,
                'broken_log_id' => null, 'reason' => 'Audit database is not reachable.'];
    }

    try {
        $sql = "SELECT " . implode(', ', array_merge(['log_id', 'prev_hash', 'entry_hash'], gjc_audit_chain_fields()))
             . " FROM systemic_audit_trail WHERE entry_hash IS NOT NULL";
        $params = [];
        if ($fromLogId !== null) {
            $sql .= " AND log_id >= ?";
            $params[] = $fromLogId;
        }
        $sql .= " ORDER BY log_id ASC";

        $stmt = $auditDb->prepare($sql);
        $stmt->execute($params);

        $expectedPrev = null;
        $first = true;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $logId = (int) $row['log_id'];
            $result['checked']++;
            $result['last_log_id'] = $logId;
            if ($result['first_log_id'] === null) {
                $result['first_log_id'] = $logId;
            }

            // The very first chained row links to whatever was there before it,
            // which may be nothing. After that, linkage must be exact.
            if (!$first && (string) $row['prev_hash'] !== (string) $expectedPrev) {
                $result['ok'] = false;
                $result['broken_log_id'] = $logId;
                $result['reason'] = 'prev_hash does not match the previous entry — a row was removed, inserted or reordered.';
                return $result;
            }
            $first = false;

            $recomputed = gjc_audit_chain_hash(
                $row['prev_hash'] === null ? null : (string) $row['prev_hash'],
                $row
            );
            if (!hash_equals((string) $row['entry_hash'], $recomputed)) {
                $result['ok'] = false;
                $result['broken_log_id'] = $logId;
                $result['reason'] = 'entry_hash does not match the row contents — this entry was modified after it was written.';
                return $result;
            }

            $expectedPrev = (string) $row['entry_hash'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'checked' => $result['checked'], 'first_log_id' => $result['first_log_id'],
                'last_log_id' => $result['last_log_id'], 'broken_log_id' => null,
                'reason' => 'Verification failed: ' . $e->getMessage()];
    }

    return $result;
}

/* ── Deferred writes for in-transaction callers ─────────────────────────── */

/**
 * Entries raised inside an open transaction, keyed by connection.
 *
 * The audit connection is separate and autocommitting, so an entry written
 * mid-transaction lands immediately and survives a rollback — which is how you
 * end up with an audit record of a mint that never happened
 * (CirculationEngine::logTransaction() logs at line ~1092, the commit is ~190
 * lines earlier in the call but later in time). Queue instead, and let the
 * caller's commit or rollback decide what the entry says.
 */
function &gjc_audit_queue(): array
{
    static $queues = [];
    return $queues;
}

function gjc_audit_register_shutdown(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    register_shutdown_function(static function (): void {
        $queues = &gjc_audit_queue();
        foreach ($queues as $key => $rows) {
            unset($queues[$key]);
            foreach ($rows as $row) {
                // Nobody told us how this ended. Say so rather than guessing:
                // 'unconfirmed' means the action started and the outcome was
                // never recorded, which is itself worth seeing in the log.
                $row['outcome'] = 'unconfirmed';
                $row['reason'] = $row['reason']
                    ?? 'Request ended before the transaction reported success or failure.';
                error_log(
                    '[audit] flushing unconfirmed entry at shutdown: ' . $row['action_type']
                    . ' on ' . $row['affected_table'] . ' (user_id=' . var_export($row['user_id'], true) . ')'
                );
                gjc_audit_write($row);
            }
        }
    });
}

/** Flush this connection's queued entries exactly as they were raised. */
function gjc_audit_commit(PDO $db): void
{
    $queues = &gjc_audit_queue();
    $key = spl_object_id($db);
    if (empty($queues[$key])) {
        return;
    }
    $rows = $queues[$key];
    unset($queues[$key]);

    foreach ($rows as $row) {
        gjc_audit_write($row);
    }
}

/**
 * Flush this connection's queued entries as failures.
 *
 * The attempt still gets recorded — an admin who tried to release ₱50,000 and
 * hit a constraint is exactly the kind of thing an audit trail is for — but it
 * is marked 'failure' so it can never be mistaken for money that moved.
 */
function gjc_audit_rollback(PDO $db, string $reason = ''): void
{
    $queues = &gjc_audit_queue();
    $key = spl_object_id($db);
    if (empty($queues[$key])) {
        return;
    }
    $rows = $queues[$key];
    unset($queues[$key]);

    $reason = trim($reason);
    foreach ($rows as $row) {
        $row['outcome'] = 'failure';
        if ($reason !== '') {
            $row['reason'] = substr($reason, 0, 500);
        }
        gjc_audit_write($row);
    }
}

/* ── The writer ─────────────────────────────────────────────────────────── */

/**
 * Insert one prepared row, chaining it onto the last entry.
 *
 * The read-then-insert has to be atomic or two concurrent requests both chain
 * off the same prev_hash and one of them is orphaned, so it runs under a named
 * lock held on the audit connection for the duration.
 */
function gjc_audit_write(array $row): void
{
    $auditDb = gjc_audit_pdo();
    if ($auditDb === null) {
        return; // gjc_audit_pdo() already explained itself in the error log.
    }

    $locked = false;
    try {
        $lock = $auditDb->query("SELECT GET_LOCK('gjc_audit_chain', 10)")->fetchColumn();
        $locked = ((int) $lock === 1);
        if (!$locked) {
            error_log('[audit] could not acquire the chain lock within 10s; writing ' . $row['action_type'] . ' unchained.');
        }

        $prevHash = null;
        if ($locked) {
            $prevHash = $auditDb
                ->query("SELECT entry_hash FROM systemic_audit_trail
                          WHERE entry_hash IS NOT NULL ORDER BY log_id DESC LIMIT 1")
                ->fetchColumn();
            $prevHash = ($prevHash === false || $prevHash === null) ? null : (string) $prevHash;
        }

        $entryHash = $locked ? gjc_audit_chain_hash($prevHash, $row) : null;

        $auditDb->prepare(
            "INSERT INTO systemic_audit_trail
                (user_id, user_role, sub_role, action_type, stall_id, affected_table,
                 target_id, outcome, reason, correlation_id, old_value, new_value,
                 ip_address, user_agent, endpoint, http_method, session_ref,
                 timestamp, prev_hash, entry_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $row['user_id'],
            $row['user_role'],
            $row['sub_role'],
            $row['action_type'],
            $row['stall_id'],
            $row['affected_table'],
            $row['target_id'],
            $row['outcome'],
            $row['reason'],
            $row['correlation_id'],
            $row['old_value'],
            $row['new_value'],
            $row['ip_address'],
            $row['user_agent'],
            $row['endpoint'],
            $row['http_method'],
            $row['session_ref'],
            $row['timestamp'],
            $prevHash,
            $entryHash,
        ]);
    } catch (Throwable $e) {
        // Never let an audit failure break the request it is auditing — but
        // never let it be invisible either. This catch used to be empty, which
        // is how a full table, a dead connection or a rejected value could stop
        // the whole audit trail without anyone noticing.
        error_log(
            '[audit] INSERT failed for ' . ($row['action_type'] ?? '?')
            . ' on ' . ($row['affected_table'] ?? '?')
            . ' (user_id=' . var_export($row['user_id'] ?? null, true) . '): '
            . $e->getMessage()
        );
    } finally {
        if ($locked) {
            try {
                $auditDb->query("SELECT RELEASE_LOCK('gjc_audit_chain')");
            } catch (Throwable $ignored) {
            }
        }
    }
}

/**
 * Record one action.
 *
 * The positional signature is unchanged — 60-odd call sites depend on it. The
 * trailing $meta array is where the new fields go:
 *
 *   target_id  the id of the row this action touched (string or int)
 *   outcome    success | failure | denied | unconfirmed   (default success)
 *   reason     why, for anything a human would want justified
 *
 * @param array{target_id?:mixed,outcome?:string,reason?:?string} $meta
 */
function logAudit(
    PDO $pdo,
    int $user_id,
    string $user_role,
    string $action_type,
    string $affected_table,
    $old_value,
    $new_value,
    $stall_id = null,
    array $meta = []
): void {
    try {
        $action_type = strtoupper(trim($action_type));

        if (!in_array($action_type, AUDIT_ACTION_TYPES, true)) {
            // Name the caller. The old code just returned, so a typo'd or
            // unregistered action type meant the action was never logged and
            // nothing anywhere said so.
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
            $where = ($caller['file'] ?? '?') . ':' . ($caller['line'] ?? '?');
            $note = in_array($action_type, AUDIT_LEGACY_ACTION_TYPES, true)
                ? 'is retired and cannot be written any more'
                : 'is not in AUDIT_ACTION_TYPES';
            error_log("[audit] action type '{$action_type}' {$note} — entry from {$where} was NOT written.");
            return;
        }

        // A missing actor is a fact about the event, not a reason to drop it.
        // gjc_expire_overdue_stall_applications() runs from the public
        // apply.php with no logged-in user; those entries used to vanish.
        if ($user_id > 0) {
            $role = $user_role !== ''
                ? gjc_audit_role($user_role)
                : gjc_audit_role_from_user($pdo, $user_id);
            $actorId = $user_id;
        } else {
            $actorId = null;
            $role = session_status() === PHP_SESSION_ACTIVE ? 'Anonymous' : 'System';
        }

        $outcome = strtolower(trim((string) ($meta['outcome'] ?? 'success')));
        if (!in_array($outcome, ['success', 'failure', 'denied', 'unconfirmed'], true)) {
            $outcome = 'success';
        }

        $targetId = $meta['target_id'] ?? null;
        if (is_array($targetId)) {
            $targetId = implode(',', $targetId);
        }
        $targetId = ($targetId === null || $targetId === '') ? null : substr((string) $targetId, 0, 64);

        $reason = $meta['reason'] ?? null;
        $reason = ($reason === null || trim((string) $reason) === '')
            ? null
            : substr(trim((string) $reason), 0, 500);

        $subRole = (string) ($_SESSION['sub_role'] ?? '');

        $row = [
            'user_id'        => $actorId,
            'user_role'      => $role,
            'sub_role'       => $subRole !== '' ? substr($subRole, 0, 32) : null,
            'action_type'    => $action_type,
            'stall_id'       => ($stall_id !== null && $stall_id !== '') ? substr((string) $stall_id, 0, 20) : null,
            'affected_table' => substr($affected_table, 0, 50),
            'target_id'      => $targetId,
            'outcome'        => $outcome,
            'reason'         => $reason,
            'correlation_id' => gjc_audit_correlation_id(),
            'old_value'      => gjc_audit_json($old_value),
            'new_value'      => gjc_audit_json($new_value),
            'ip_address'     => gjc_audit_ip(),
            'user_agent'     => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 255),
            'endpoint'       => substr((string) ($_SERVER['SCRIPT_NAME'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'unknown')), 0, 255),
            'http_method'    => substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'), 0, 8),
            'session_ref'    => gjc_audit_session_ref(),
            // Stamped now, not at flush time — a queued entry must carry the
            // moment the action happened, not the moment the transaction ended.
            'timestamp'      => gmdate('Y-m-d H:i:s'),
        ];

        if ($pdo->inTransaction()) {
            $queues = &gjc_audit_queue();
            $queues[spl_object_id($pdo)][] = $row;
            gjc_audit_register_shutdown();
            return;
        }

        gjc_audit_write($row);
    } catch (Throwable $e) {
        error_log('[audit] logAudit() failed for ' . $action_type . ': ' . $e->getMessage());
    }
}
