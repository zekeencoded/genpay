<?php

/**
 * Make systemic_audit_trail append-only and tamper-evident. Idempotent; CLI only (root).
 *
 *   php database/migrations/audit_hardening.php            apply
 *   php database/migrations/audit_hardening.php --dry-run  show, change nothing
 *
 * Steps: add columns, nullable user_id, VARCHAR action_type, MEDIUMTEXT payloads,
 * indexes, redact/relabel legacy rows, block UPDATE/DELETE with triggers, and
 * create the insert-only gjc_audit_writer account.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration is CLI only.\n");
}

require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

$dryRun = in_array('--dry-run', $argv, true);

const AUDIT_TABLE   = 'systemic_audit_trail';
const AUDIT_DB_USER = 'gjc_audit_writer';
const AUDIT_DB_HOST = 'localhost';

$applied = [];
$skipped = [];

/** Echo + remember. */
function step(string $message, bool $changed = true): void
{
    global $applied, $skipped, $dryRun;
    $mark = $changed ? ($dryRun ? '[would]' : '[done]') : '[skip]';
    echo "  {$mark} {$message}\n";
    if ($changed) {
        $applied[] = $message;
    } else {
        $skipped[] = $message;
    }
}

function run(PDO $db, string $sql): void
{
    global $dryRun;
    if (!$dryRun) {
        $db->exec($sql);
    }
}

function schemaName(PDO $db): string
{
    return (string) $db->query("SELECT DATABASE()")->fetchColumn();
}

function columns(PDO $db): array
{
    $out = [];
    foreach ($db->query("SHOW COLUMNS FROM " . AUDIT_TABLE) as $row) {
        $out[(string) $row['Field']] = $row;
    }
    return $out;
}

/** Indexes as name => columns, compared by leading columns. */
function indexes(PDO $db): array
{
    $out = [];
    foreach ($db->query("SHOW INDEX FROM " . AUDIT_TABLE) as $row) {
        $out[(string) $row['Key_name']][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
    }
    foreach ($out as $name => $cols) {
        ksort($cols);
        $out[$name] = array_values($cols);
    }
    return $out;
}

/** Name of an existing index that already starts with $cols, if any. */
function coveringIndex(array $existing, array $cols): ?string
{
    foreach ($existing as $name => $indexCols) {
        if (array_slice($indexCols, 0, count($cols)) === $cols) {
            return $name;
        }
    }
    return null;
}

function triggerExists(PDO $db, string $name): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ? LIMIT 1"
    );
    $stmt->execute([schemaName($db), $name]);
    return (bool) $stmt->fetchColumn();
}

/** Pull the labels out of an `enum('a','b')` type string. */
function enumLabels(string $type): array
{
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $m);
    return $m[1] ?? [];
}

echo "\nGenPay — audit trail hardening" . ($dryRun ? "  (dry run)" : "") . "\n";
echo str_repeat('-', 62) . "\n";

try {
    $db->query("SELECT 1 FROM " . AUDIT_TABLE . " LIMIT 1");
} catch (Throwable $e) {
    exit("\nFATAL: `" . AUDIT_TABLE . "` not found in " . schemaName($db) . ".\n"
        . "Restore the table before hardening it: database/systemic_audit_trail.sql\n\n");
}

/* ── 1. Columns ─────────────────────────────────────────────────────────── */

echo "\n1. Columns\n";

$newColumns = [
    'target_id'      => "VARCHAR(64) NULL AFTER affected_table",
    'outcome'        => "ENUM('success','failure','denied','unconfirmed') NOT NULL DEFAULT 'success' AFTER target_id",
    'reason'         => "VARCHAR(500) NULL AFTER outcome",
    'sub_role'       => "VARCHAR(32) NULL AFTER user_role",
    'correlation_id' => "CHAR(32) NULL AFTER reason",
    'endpoint'       => "VARCHAR(255) NULL AFTER user_agent",
    'http_method'    => "VARCHAR(8) NULL AFTER endpoint",
    'session_ref'    => "CHAR(16) NULL AFTER http_method",
    'prev_hash'      => "CHAR(64) NULL AFTER timestamp",
    'entry_hash'     => "CHAR(64) NULL AFTER prev_hash",
];

$live = columns($db);
foreach ($newColumns as $name => $definition) {
    if (isset($live[$name])) {
        step("column {$name}", false);
        continue;
    }
    run($db, "ALTER TABLE " . AUDIT_TABLE . " ADD COLUMN {$name} {$definition}");
    step("column {$name}");
}

/* ── 2. Nullable actor, wider role ──────────────────────────────────────── */

echo "\n2. Actor columns\n";

$live = columns($db);

// NULL user_id = no logged-in user (pre-auth or system).
if (strtoupper((string) ($live['user_id']['Null'] ?? 'NO')) === 'YES') {
    step("user_id nullable", false);
} else {
    // Drop and re-add the FK to change nullability.
    run($db, "ALTER TABLE " . AUDIT_TABLE . " DROP FOREIGN KEY fk_systemic_audit_user");
    run($db, "ALTER TABLE " . AUDIT_TABLE . " MODIFY user_id INT NULL");
    run($db, "ALTER TABLE " . AUDIT_TABLE . " ADD CONSTRAINT fk_systemic_audit_user
              FOREIGN KEY (user_id) REFERENCES users(userID) ON DELETE RESTRICT");
    step("user_id nullable (FK re-added)");
}

$roleType   = (string) ($live['user_role']['Type'] ?? '');
$roleLabels = enumLabels($roleType);
$wantRoles  = ['System', 'Anonymous', 'Parent', 'Unknown'];
$missingRoles = array_diff($wantRoles, $roleLabels);
if (!$missingRoles) {
    step("user_role covers System/Anonymous", false);
} else {
    // Merge with live labels so later ones aren't lost.
    $merged = array_values(array_unique(array_merge($roleLabels, $wantRoles)));
    $sql = implode(', ', array_map(
        static fn(string $l): string => "'" . str_replace("'", "\\'", $l) . "'",
        $merged
    ));
    run($db, "ALTER TABLE " . AUDIT_TABLE . " MODIFY user_role ENUM({$sql}) NOT NULL");
    step("user_role += " . implode(', ', $missingRoles));
}

/* ── 3. action_type ENUM → VARCHAR(64) ──────────────────────────────────── */

echo "\n3. action_type\n";

$live = columns($db);
$actionType = strtolower((string) ($live['action_type']['Type'] ?? ''));
if (str_starts_with($actionType, 'varchar')) {
    step("action_type is VARCHAR", false);
} else {
    $preserved = enumLabels((string) $live['action_type']['Type']);
    // ENUM -> VARCHAR keeps every label.
    run($db, "ALTER TABLE " . AUDIT_TABLE . " MODIFY action_type VARCHAR(64) NOT NULL");
    step("action_type ENUM(" . count($preserved) . " labels) → VARCHAR(64)");
    echo "         preserved: " . implode(', ', $preserved) . "\n";
}

/* ── 4. Payload width ───────────────────────────────────────────────────── */

echo "\n4. Payload columns\n";

$live = columns($db);
foreach (['old_value', 'new_value'] as $col) {
    if (strtolower((string) ($live[$col]['Type'] ?? '')) === 'mediumtext') {
        step("{$col} is MEDIUMTEXT", false);
        continue;
    }
    run($db, "ALTER TABLE " . AUDIT_TABLE . " MODIFY {$col} MEDIUMTEXT NULL");
    step("{$col} TEXT → MEDIUMTEXT");
}

/* ── 5. Indexes ─────────────────────────────────────────────────────────── */

echo "\n5. Indexes\n";

$wantIndexes = [
    'idx_audit_user'        => ['user_id'],
    'idx_audit_action'      => ['action_type'],
    'idx_audit_target'      => ['affected_table', 'target_id'],
    'idx_audit_time'        => ['timestamp'],
    'idx_audit_correlation' => ['correlation_id'],
];

$haveIndexes = indexes($db);
foreach ($wantIndexes as $name => $cols) {
    $covered = coveringIndex($haveIndexes, $cols);
    if ($covered !== null) {
        step("index on (" . implode(', ', $cols) . ") — already covered by {$covered}", false);
        continue;
    }
    run($db, "ALTER TABLE " . AUDIT_TABLE . " ADD INDEX {$name} (" . implode(', ', $cols) . ")");
    $haveIndexes[$name] = $cols;
    step("index {$name} (" . implode(', ', $cols) . ")");
}

/* ── 6. Redact secrets in legacy rows (BEFORE the triggers exist) ───────── */

echo "\n6. Legacy redaction\n";

$redactedIds = [];
$pending = [];
$rows = $db->query("SELECT log_id, old_value, new_value FROM " . AUDIT_TABLE
    . " WHERE old_value IS NOT NULL OR new_value IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

$update = $db->prepare("UPDATE " . AUDIT_TABLE . " SET old_value = ?, new_value = ? WHERE log_id = ?");

foreach ($rows as $row) {
    $changed = false;
    $clean = [];

    foreach (['old_value', 'new_value'] as $col) {
        $raw = $row[$col];
        $clean[$col] = $raw;
        if ($raw === null || trim((string) $raw) === '') {
            continue;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            continue; // not a JSON payload — leave it exactly as it is
        }
        $redacted = gjc_audit_redact($decoded);
        if ($redacted !== $decoded) {
            $clean[$col] = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $changed = true;
        }
    }

    if ($changed) {
        $redactedIds[] = (int) $row['log_id'];
        $pending[(int) $row['log_id']] = $clean;
    }
}

// Lift the append-only trigger on re-runs; step 7 restores it.
$liftedTrigger = false;
if ($redactedIds && !$dryRun && triggerExists($db, 'trg_audit_no_update')) {
    $db->exec("DROP TRIGGER trg_audit_no_update");
    $liftedTrigger = true;
    step("append-only UPDATE trigger lifted for redaction (reinstalled in step 7)");
}

if (!$dryRun) {
    foreach ($pending as $logId => $clean) {
        $update->execute([$clean['old_value'], $clean['new_value'], $logId]);
    }
}

if (!$redactedIds) {
    step("no secrets found in " . count($rows) . " legacy payloads", false);
} else {
    step(count($redactedIds) . " row(s) redacted: " . implode(', ', $redactedIds));

    // Log which rows the redaction touched.
    if (!$dryRun) {
        $db->prepare(
            "INSERT INTO " . AUDIT_TABLE . "
                (user_id, user_role, action_type, affected_table, target_id, outcome, reason,
                 old_value, new_value, ip_address, user_agent, endpoint)
             VALUES (NULL, 'System', 'MIGRATION_REDACTION', ?, NULL, 'success', ?, NULL, ?, '0.0.0.0', 'cli', ?)"
        )->execute([
            AUDIT_TABLE,
            'One-time redaction of credential-shaped values in legacy audit payloads.',
            json_encode([
                'redacted_log_ids' => $redactedIds,
                'row_count'        => count($redactedIds),
                'rows_scanned'     => count($rows),
            ], JSON_UNESCAPED_SLASHES),
            'database/migrations/audit_hardening.php',
        ]);
        step("MIGRATION_REDACTION entry written (log_id " . $db->lastInsertId() . ")");
    }
}

/* ── 6b. Correct the role on entries a parent actually made ─────────────── */

echo "\n6b. Parent role relabel\n";

// Relabel parent rows filed as 'Student'. Only unchained rows (entry_hash NULL), and log the change.

$mislabelled = $db->query(
    "SELECT s.log_id
       FROM " . AUDIT_TABLE . " s
       JOIN users u ON u.userID = s.user_id
      WHERE u.roleID = 7
        AND s.user_role = 'Student'
        AND s.entry_hash IS NULL
      ORDER BY s.log_id"
)->fetchAll(PDO::FETCH_COLUMN);
$mislabelled = array_map('intval', $mislabelled);

// A chained row would have to be left alone and reported rather than fixed.
$chainedMislabelled = (int) $db->query(
    "SELECT COUNT(*) FROM " . AUDIT_TABLE . " s
       JOIN users u ON u.userID = s.user_id
      WHERE u.roleID = 7 AND s.user_role = 'Student' AND s.entry_hash IS NOT NULL"
)->fetchColumn();

if (!$mislabelled) {
    step('no parent entries filed under Student', false);
} else {
    if (!$dryRun) {
        if (triggerExists($db, 'trg_audit_no_update')) {
            $db->exec("DROP TRIGGER trg_audit_no_update");
            step('append-only UPDATE trigger lifted for relabel (reinstalled in step 7)');
        }
        $chunk = implode(',', $mislabelled);
        $db->exec("UPDATE " . AUDIT_TABLE . " SET user_role = 'Parent' WHERE log_id IN ({$chunk})");
    }
    step(count($mislabelled) . " row(s) relabelled Student -> Parent (log_id "
        . $mislabelled[0] . '-' . end($mislabelled) . ')');

    if (!$dryRun) {
        $db->prepare(
            "INSERT INTO " . AUDIT_TABLE . "
                (user_id, user_role, action_type, affected_table, target_id, outcome, reason,
                 old_value, new_value, ip_address, user_agent, endpoint)
             VALUES (NULL, 'System', 'MIGRATION_ROLE_RELABEL', ?, NULL, 'success', ?, ?, ?, '0.0.0.0', 'cli', ?)"
        )->execute([
            AUDIT_TABLE,
            "Corrected user_role on entries made by parent accounts (roleID 7) that gjc_audit_role() filed as 'Student'.",
            json_encode(['user_role' => 'Student'], JSON_UNESCAPED_SLASHES),
            json_encode([
                'user_role'         => 'Parent',
                'relabelled_log_ids' => $mislabelled,
                'row_count'         => count($mislabelled),
            ], JSON_UNESCAPED_SLASHES),
            'database/migrations/audit_hardening.php',
        ]);
        step('MIGRATION_ROLE_RELABEL entry written (log_id ' . $db->lastInsertId() . ')');
    }
}

if ($chainedMislabelled > 0) {
    echo "  [warn] {$chainedMislabelled} chained row(s) also carry the wrong role.\n";
    echo "         Left untouched on purpose: rewriting them would break the hash chain.\n";
}

/* ── 7. Append-only triggers ────────────────────────────────────────────── */

echo "\n7. Append-only triggers\n";

$triggers = [
    'trg_audit_no_update' => "BEFORE UPDATE ON " . AUDIT_TABLE . " FOR EACH ROW
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'systemic_audit_trail is append-only: UPDATE is not permitted.'",
    'trg_audit_no_delete' => "BEFORE DELETE ON " . AUDIT_TABLE . " FOR EACH ROW
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'systemic_audit_trail is append-only: DELETE is not permitted.'",
];

foreach ($triggers as $name => $body) {
    if (triggerExists($db, $name)) {
        step("trigger {$name}", false);
        continue;
    }
    run($db, "CREATE TRIGGER {$name} {$body}");
    step("trigger {$name}");
}

/* ── 8. Restricted writer account + credentials file ────────────────────── */

echo "\n8. Audit writer account\n";

$configPath  = dirname(__DIR__, 2) . '/connection/audit_config.php';
$havePassword = null;

if (is_file($configPath)) {
    $existing = require $configPath;
    if (is_array($existing) && !empty($existing['pass'])
        && $existing['pass'] !== 'REPLACE_WITH_THE_AUDIT_WRITER_PASSWORD') {
        $havePassword = (string) $existing['pass'];
    }
}

$password = $havePassword ?? bin2hex(random_bytes(24));
$schema   = schemaName($db);

if (!$dryRun) {
    $db->exec("CREATE USER IF NOT EXISTS '" . AUDIT_DB_USER . "'@'" . AUDIT_DB_HOST . "'"
        . " IDENTIFIED BY " . $db->quote($password));
    // Re-assert on every run so the account and the config file cannot drift apart.
    $db->exec("ALTER USER '" . AUDIT_DB_USER . "'@'" . AUDIT_DB_HOST . "'"
        . " IDENTIFIED BY " . $db->quote($password));
    // INSERT + SELECT only, so the app can't rewrite the audit trail.
    $db->exec("GRANT INSERT, SELECT ON `{$schema}`.`" . AUDIT_TABLE . "`"
        . " TO '" . AUDIT_DB_USER . "'@'" . AUDIT_DB_HOST . "'");
    $db->exec("FLUSH PRIVILEGES");
}
step(AUDIT_DB_USER . "@" . AUDIT_DB_HOST . " with INSERT, SELECT on {$schema}." . AUDIT_TABLE);

if ($havePassword !== null) {
    step("connection/audit_config.php (kept existing password)", false);
} elseif (!$dryRun) {
    $config = "<?php\n\n"
        . "/**\n"
        . " * Written by database/migrations/audit_hardening.php on "
        . gmdate('Y-m-d H:i:s') . " UTC.\n"
        . " *\n"
        . " * Credentials for the restricted audit-trail connection. Gitignored — this\n"
        . " * is a live database password. See connection/audit_config.example.php.\n"
        . " */\n\n"
        . "return [\n"
        . "    'host' => " . var_export(AUDIT_DB_HOST, true) . ",\n"
        . "    'port' => '3306',\n"
        . "    'name' => " . var_export($schema, true) . ",\n"
        . "    'user' => " . var_export(AUDIT_DB_USER, true) . ",\n"
        . "    'pass' => " . var_export($password, true) . ",\n"
        . "];\n";

    if (file_put_contents($configPath, $config) === false) {
        echo "\nFATAL: could not write {$configPath}\n";
        echo "Create it by hand from connection/audit_config.example.php using:\n";
        echo "  'user' => '" . AUDIT_DB_USER . "', 'pass' => '{$password}'\n\n";
        exit(1);
    }
    @chmod($configPath, 0600);
    step("connection/audit_config.php written (new password generated)");
} else {
    step("connection/audit_config.php would be written with a new password");
}

/* ── Verify ─────────────────────────────────────────────────────────────── */

echo "\n" . str_repeat('-', 62) . "\n";
echo ($dryRun ? "Dry run: " : "Applied: ") . count($applied) . " change(s), "
    . count($skipped) . " already in place.\n";

if (!$dryRun) {
    echo "\nVerifying the writer account can insert but not tamper:\n";
    try {
        $check = new PDO(
            "mysql:host=" . AUDIT_DB_HOST . ";dbname={$schema};charset=utf8mb4",
            AUDIT_DB_USER,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "  [ok]   connects as " . AUDIT_DB_USER . "\n";

        $count = $check->query("SELECT COUNT(*) FROM " . AUDIT_TABLE)->fetchColumn();
        echo "  [ok]   SELECT works ({$count} rows)\n";

        try {
            $check->exec("UPDATE " . AUDIT_TABLE . " SET reason = 'tamper' WHERE log_id = 1");
            echo "  [FAIL] UPDATE succeeded — it should not have!\n";
        } catch (Throwable $e) {
            echo "  [ok]   UPDATE refused: " . trim(explode("\n", $e->getMessage())[0]) . "\n";
        }

        try {
            $check->exec("DELETE FROM " . AUDIT_TABLE . " WHERE log_id = 1");
            echo "  [FAIL] DELETE succeeded — it should not have!\n";
        } catch (Throwable $e) {
            echo "  [ok]   DELETE refused: " . trim(explode("\n", $e->getMessage())[0]) . "\n";
        }
    } catch (Throwable $e) {
        echo "  [FAIL] " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n\n";
