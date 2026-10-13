<?php

require_once __DIR__ . "/config.php";
// Pulled in unconditionally so logAudit() is available everywhere app.php is —
// which is every page. gjc_require_role() below records a signed-in user being
// turned away from another role's area, and without this that would only be
// logged on the handful of pages that happened to require audit_logger.php for
// their own reasons. The file defines functions and constants only; including
// it has no side effects and it requires nothing back, so there is no cycle.
require_once __DIR__ . "/audit_logger.php";

if (PHP_SAPI !== "cli" && session_status() === PHP_SESSION_NONE) {
    session_start();
}

function gjc_e($value): string
{
    return (string) $value;
}

function gjc_money($amount): string
{
    return '&#8369;' . number_format((float) $amount, 2);
}

/**
 * Same formatting as gjc_money(), but the real ₱ character instead of an
 * HTML entity — for contexts that don't render raw HTML (JSON payloads,
 * notification/SMS text, log messages). Using gjc_money() there stores the
 * literal "&#8369;" text, which then gets HTML-escaped a second time by
 * whatever renders it and shows up as visible entity text instead of ₱.
 */
function gjc_money_plain($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/**
 * The two date formats the UI is allowed to show.
 *
 * The admin side had eight display formats for the same kind of value, and six
 * of those printed no year at all — on Top-ups and Encashments specifically, so
 * a finance officer could not tell a 2024 record from a 2026 one. These two are
 * simply the formats that were already dominant (`M j, Y` appeared 17 times),
 * promoted to the only ones.
 *
 * Both are null-safe and return an em dash for an empty or unparseable value,
 * because a blank cell reads as a rendering bug while a dash reads as "none".
 *
 * Machine formats (`Y-m-d` in query strings, `Ymd-His` in filenames) are not
 * display and keep using date() directly. print_fee_waiver.php keeps its
 * `F j, Y` — it is a legal form, not a screen.
 */
function gjc_date($value): string
{
    $ts = gjc_timestamp($value);
    return $ts === null ? '—' : date('M j, Y', $ts);
}

function gjc_datetime($value): string
{
    $ts = gjc_timestamp($value);
    return $ts === null ? '—' : date('M j, Y · g:i A', $ts);
}

/** Shared parse step: accepts a timestamp, a DateTimeInterface, or a date string. */
function gjc_timestamp($value): ?int
{
    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
        return null;
    }
    if ($value instanceof DateTimeInterface) {
        return $value->getTimestamp();
    }
    if (is_int($value)) {
        return $value;
    }
    $ts = strtotime((string) $value);
    return $ts === false ? null : $ts;
}

// GenCoin conversion rate: ₱10 = 1 GC (same rate the POS wallet-load flow uses).
define('GJC_PESOS_PER_GC', 10);

// Restricted-product strike policy DEFAULTS: warn the merchant at 3 blocked
// attempts; hitting 5 auto-suspends the whole stall (owner + staff logins,
// student purchases) for 3 days and restarts the count. Finance can lift a
// suspension early from admin/restricted_products.php.
//
// These are the fallbacks only — the live values are the 'violation_*' keys in
// app_settings, editable from admin/settings.php. Read them at runtime with
// gjc_setting_int(), not these constants.
define('GJC_VIOLATION_WARN_AT', 3);
define('GJC_VIOLATION_RISK_AT', 5);
define('GJC_VIOLATION_SUSPEND_DAYS', 3);

// Server-side key used to sign opaque "view record" tokens (see
// gjc_make_view_token()). This is not a user secret and nothing is stored per
// row — it only proves a token was minted by the app. Rotating it simply
// invalidates any outstanding view links.
if (!defined('GJC_VIEW_TOKEN_SECRET')) {
    define('GJC_VIEW_TOKEN_SECRET', gjc_local_config('view_token_secret', 'GJC_VIEW_TOKEN_SECRET'));
}

/**
 * Mint an opaque, tamper-proof token for a read-only "view" URL.
 *
 * The token is base64url("<id>.<sig>") where <sig> is a truncated
 * HMAC-SHA256 of "<scope>:<id>" keyed by GJC_VIEW_TOKEN_SECRET. It carries a
 * row id in the URL without exposing it as a bare, enumerable ?id=1,2,3
 * integer, and any edit to the id breaks the signature. Stateless — no DB
 * column, nothing stored. The $scope namespaces tokens so a token minted for
 * one entity's view page can't be replayed against another's.
 */
function gjc_make_view_token(int $id, string $scope = 'record'): string
{
    $id = max(0, $id);
    $sig = substr(hash_hmac('sha256', $scope . ':' . $id, GJC_VIEW_TOKEN_SECRET), 0, 40);
    return rtrim(strtr(base64_encode($id . '.' . $sig), '+/', '-_'), '=');
}

/**
 * Verify a token from gjc_make_view_token() and return the row id it carries,
 * or null if the token is missing, malformed, or the signature does not match
 * (compared with hash_equals(), constant time). The $scope must match the one
 * the token was minted with.
 */
function gjc_verify_view_token(?string $token, string $scope = 'record'): ?int
{
    $token = (string) $token;
    if ($token === '') {
        return null;
    }
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($raw === false || strpos($raw, '.') === false) {
        return null;
    }
    [$idPart, $sig] = explode('.', $raw, 2);
    if (!ctype_digit($idPart)) {
        return null;
    }
    $id = (int) $idPart;
    $expected = substr(hash_hmac('sha256', $scope . ':' . $id, GJC_VIEW_TOKEN_SECRET), 0, 40);
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    return $id;
}

function gjc_gc_amount($pesos): string
{
    $gc = (float) $pesos / GJC_PESOS_PER_GC;
    return rtrim(rtrim(number_format($gc, 2), '0'), '.');
}

// One-line GC value with its peso equivalent trailing it, for table-like rows
// ("50 GC ≈ ₱500.00"). Style .gc-inline-peso where it is used.
function gjc_gc_inline($pesos): string
{
    return gjc_gc_amount($pesos) . ' GC <span class="gc-inline-peso">&asymp; ' . gjc_money($pesos) . '</span>';
}

// Two-line price tag: GC as the primary value, peso equivalent beneath.
function gjc_gc_price($pesos): string
{
    return '<span class="gc-price">'
        . '<span class="gc-price-main">' . gjc_gc_amount($pesos) . ' GC</span>'
        . '<span class="gc-price-sub">&asymp; ' . gjc_money($pesos) . '</span>'
        . '</span>';
}

function gjc_role_name($role): string
{
    if (is_numeric($role)) {
        return [
            1 => 'student',
            2 => 'merchant',
            3 => 'finance',
            4 => 'finance',
            5 => 'merchant',
            6 => 'merchant',
            7 => 'parent',
        ][(int) $role] ?? 'guest';
    }

    // legacy alias: anything stored as 'admin' is treated as 'finance'
    if (strtolower((string) $role) === 'admin') {
        return 'finance';
    }

    return strtolower((string) $role);
}

/**
 * Message that is safe to show a client for a caught exception.
 *
 * Business rules in this app are thrown as RuntimeException / InvalidArgumentException
 * with text written for the user, so those pass through. Anything else — and
 * PDOException, which extends RuntimeException but carries SQLSTATE text with
 * table and column names — is logged in full and replaced by $generic.
 */
function gjc_safe_error(Throwable $e, string $context, string $generic = 'Something went wrong. Please try again.'): string
{
    if (($e instanceof RuntimeException || $e instanceof InvalidArgumentException) && !($e instanceof PDOException)) {
        return $e->getMessage();
    }
    error_log('[' . $context . '] ' . get_class($e) . ': ' . $e->getMessage());
    return $generic;
}

function gjc_user_id(): int
{
    return (int) ($_SESSION['userID'] ?? $_SESSION['user_id'] ?? 0);
}

function gjc_current_role(): string
{
    return gjc_role_name($_SESSION['roleID'] ?? $_SESSION['role'] ?? 0);
}

function gjc_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function gjc_table_columns(PDO $db, string $table, bool $refresh = false): array
{
    static $cache = [];
    if (!$refresh && isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $db->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    );
    $stmt->execute([$table]);
    return $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Normalizes a product name so a ban can't be dodged by cosmetic tweaks.
 * Folds case, leetspeak/homoglyphs (0→o, 3→e, @→a …), strips every
 * non-alphanumeric character (spaces, dashes, dots), then collapses runs of a
 * repeated character. So "Piattos", "piatos", "Pi@tt0s", "P-i-a-t-t-o-s" and
 * "Piattoss" all normalize to the same "piatos" — closing the drop/add-a-letter,
 * spacing, punctuation, and leetspeak evasions.
 */
function gjc_normalize_product_name(string $name): string
{
    $s = function_exists('mb_strtolower')
        ? mb_strtolower($name, 'UTF-8')
        : strtolower($name);
    $s = gjc_fold_confusables($s);                    // unicode look-alikes + accents -> ascii
    $s = strtr($s, [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's',
        '7' => 't', '8' => 'b', '9' => 'g',
        '@' => 'a', '$' => 's', '!' => 'i', '|' => 'i', '+' => 't',
        '£' => 'e', '€' => 'e',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);        // drop spaces / punctuation / zero-width
    $s = preg_replace('/(.)\1+/', '$1', (string) $s); // collapse runs: piattoss -> piatos
    return (string) $s;
}

/**
 * Folds common Unicode "confusable" characters — Cyrillic/Greek homoglyphs and
 * accented Latin letters — down to their plain ASCII look-alike. Catches
 * evasions like "Соbra" (Cyrillic с/о) or "Cóbrá" that survive plain lowercasing.
 * Keys are lowercase; run this after mb_strtolower().
 */
function gjc_fold_confusables(string $s): string
{
    static $map = null;
    if ($map === null) {
        $map = [
            // Cyrillic look-alikes
            'а'=>'a','в'=>'b','е'=>'e','ё'=>'e','к'=>'k','м'=>'m','н'=>'h','о'=>'o',
            'р'=>'p','с'=>'c','т'=>'t','у'=>'y','х'=>'x','і'=>'i','ј'=>'j','ѕ'=>'s',
            'ԁ'=>'d','ԛ'=>'q','г'=>'r','ѡ'=>'w',
            // Greek look-alikes
            'α'=>'a','β'=>'b','ε'=>'e','η'=>'n','ι'=>'i','κ'=>'k','ν'=>'v','ο'=>'o',
            'ρ'=>'p','τ'=>'t','υ'=>'u','χ'=>'x','ω'=>'w','γ'=>'y','ϲ'=>'c',
            // Accented / extended Latin
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a','ă'=>'a','ą'=>'a',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ē'=>'e','ĕ'=>'e','ė'=>'e','ę'=>'e','ě'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ĩ'=>'i','ī'=>'i','ĭ'=>'i',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ō'=>'o','ŏ'=>'o','ő'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ũ'=>'u','ū'=>'u','ŭ'=>'u','ů'=>'u',
            'ñ'=>'n','ń'=>'n','ň'=>'n','ç'=>'c','ć'=>'c','č'=>'c','ý'=>'y','ÿ'=>'y',
            'š'=>'s','ś'=>'s','ž'=>'z','ź'=>'z','ż'=>'z','ł'=>'l','đ'=>'d','ğ'=>'g',
            'ř'=>'r','ť'=>'t','ß'=>'b',
        ];
    }
    return strtr($s, $map);
}

/**
 * Generic filler words that carry no brand identity. When a ban is a multi-word
 * phrase, these are stripped so matching keys off the distinctive word(s) — e.g.
 * banning "Cobra Energy Drink" enforces on "cobra", not on every "energy"/"drink".
 */
function gjc_restriction_stopwords(): array
{
    static $stop = null;
    if ($stop === null) {
        $raw = [
            'the','and','a','an','of','with','for','in','on','plus','extra','no','zero',
            'original','classic','new','old','mini','jumbo','large','small','regular','size',
            'pack','packs','bottle','bottles','can','cans','cup','cups','box','sachet',
            'piece','pieces','pc','pcs','flavor','flavour','flavored','flavoured','brand',
            'energy','drink','drinks','soda','sodas','juice','water','iced','hot','cold',
        ];
        $stop = [];
        foreach ($raw as $w) {
            $n = gjc_normalize_product_name($w);
            if ($n !== '') {
                $stop[$n] = true;
            }
        }
    }
    return $stop;
}

/**
 * Breaks a ban name into its distinctive normalized word tokens (generic filler
 * and <3-char words dropped). Returns [] when the ban is made up entirely of
 * generic words, signalling callers to fall back to whole-phrase matching.
 */
function gjc_restriction_tokens(string $banName): array
{
    $stop  = gjc_restriction_stopwords();
    $words = preg_split('/[^\p{L}\p{N}]+/u', $banName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = [];
    foreach ($words as $w) {
        $n = gjc_normalize_product_name($w);
        if ($n === '' || strlen($n) < 3 || isset($stop[$n])) {
            continue;
        }
        $tokens[$n] = true;
    }
    return array_keys($tokens);
}

/**
 * True if $token appears in $hay as a clean substring, or — for longer tokens —
 * within a small edit distance of some window of $hay (catches typo evasions
 * like "kobra"/"coxbra"). Distance budget scales with length and stays 0 for
 * short tokens so generic 3-4 letter fragments don't over-match.
 */
function gjc_fuzzy_token_in_haystack(string $token, string $hay): bool
{
    $tlen = strlen($token);
    if ($tlen < 3 || $hay === '') {
        return false;
    }
    if (strpos($hay, $token) !== false) {
        return true;
    }
    $maxDist = $tlen >= 8 ? 2 : ($tlen >= 5 ? 1 : 0);
    if ($maxDist === 0) {
        return false;
    }
    $hlen = strlen($hay);
    for ($winLen = max(1, $tlen - $maxDist); $winLen <= $tlen + $maxDist; $winLen++) {
        for ($i = 0; $i + $winLen <= $hlen; $i++) {
            if (levenshtein($token, substr($hay, $i, $winLen)) <= $maxDist) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Core decision: does a single restriction match a product name? Everything runs
 * on the normalized form so leetspeak/spacing/homoglyph tricks are neutralised.
 *
 *  - 'exact'    → the whole normalized names must be identical.
 *  - 'contains' → matches if the normalized ban sits inside the product name, OR
 *                 any distinctive word of the ban is found (exact or fuzzy). This
 *                 is what catches "C0br4" (→"cobra") against a "Cobra Energy Drink"
 *                 ban even though the merchant never typed the full phrase.
 */
function gjc_restriction_matches(string $banName, string $matchType, string $productName): bool
{
    $normBan  = gjc_normalize_product_name($banName);
    $normName = gjc_normalize_product_name($productName);
    if ($normBan === '' || $normName === '') {
        return false;
    }

    if ($matchType === 'exact') {
        return $normName === $normBan;
    }

    // Whole-phrase substring (fast path + subset like "cobra" in "cobraenergydrink").
    if (strpos($normName, $normBan) !== false) {
        return true;
    }

    $tokens = gjc_restriction_tokens($banName);
    if (empty($tokens)) {
        // Ban is all generic words — fall back to a two-way phrase subset check.
        return strlen($normName) >= 4 && strpos($normBan, $normName) !== false;
    }
    foreach ($tokens as $t) {
        if (gjc_fuzzy_token_in_haystack($t, $normName)) {
            return true;
        }
    }
    return false;
}

/**
 * Cross-checks a product name against the admin-managed restricted_products
 * blacklist. Shared by inventory add/edit (and any future scanner) so every
 * enforcement point uses the exact same nutritional-compliance rule. Returns the
 * ban reason on the first match, or null when the product is clear.
 */
function gjc_check_restricted(PDO $db, string $productName): ?string
{
    if (!gjc_table_exists($db, 'restricted_products')) {
        return null;
    }
    if (gjc_normalize_product_name($productName) === '') {
        return null;
    }

    $restrictions = $db->query(
        "SELECT product_name, match_type, reason FROM restricted_products WHERE is_active = 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($restrictions as $r) {
        if (gjc_restriction_matches((string) $r['product_name'], (string) $r['match_type'], $productName)) {
            return $r['reason'];
        }
    }

    return null;
}

/**
 * Re-scans currently-listed inventory against every active ban and disables any
 * item that now matches under the smart rules. Idempotent (only touches items
 * not already restricted), so it's safe to call on page load — this is what
 * retroactively closes evasions that were added before a rule tightened.
 * Returns the number of items newly disabled.
 */
function gjc_enforce_restrictions_on_inventory(PDO $db): int
{
    if (!gjc_table_exists($db, 'merchant_inventory') || !gjc_table_exists($db, 'restricted_products')) {
        return 0;
    }
    $bans = $db->query(
        "SELECT product_name, match_type, reason FROM restricted_products WHERE is_active = 1"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!$bans) {
        return 0;
    }
    $items = $db->query(
        "SELECT id, product_name FROM merchant_inventory WHERE is_restricted = 0"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        return 0;
    }

    $upd = $db->prepare(
        "UPDATE merchant_inventory
            SET is_restricted = 1, is_available = 0, restriction_note = ?
          WHERE id = ?"
    );
    $disabled = 0;
    foreach ($items as $it) {
        foreach ($bans as $b) {
            if (gjc_restriction_matches((string) $b['product_name'], (string) $b['match_type'], (string) $it['product_name'])) {
                $upd->execute([$b['reason'], $it['id']]);
                $disabled++;
                break;
            }
        }
    }
    return $disabled;
}

/**
 * Optional current restricted-product state. Audit history remains separate.
 * Existing legacy fields remain until a separately approved CONTRACT.
 */
function gjc_ensure_user_violations_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        throw new RuntimeException('Violation state schema requires users.');
    }
    $type = $db->query("SHOW COLUMNS FROM users LIKE 'userID'")->fetch(PDO::FETCH_ASSOC);
    if (!$type || strtolower($type['Type']) !== 'int') {
        throw new RuntimeException('Violation owner FK requires signed INT users.userID.');
    }
    if (gjc_table_exists($db, 'user_violations')) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Violation state schema creation must precede a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_violations (
    user_id INT NOT NULL,
    restricted_violation_count INT UNSIGNED NOT NULL DEFAULT 0,
    restricted_violation_last_at DATETIME NULL,
    restricted_suspended_until DATETIME NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_violation_user FOREIGN KEY (user_id)
        REFERENCES users(userID) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    gjc_table_columns($db, 'user_violations', true);
}

/** Current facts, distinct from immutable PRODUCT_RESTRICTION history. */
function gjc_violation_fields(): array
{
    return ['restricted_violation_count', 'restricted_violation_last_at', 'restricted_suspended_until'];
}

function gjc_violation_has_legacy(PDO $db): bool
{
    $count = count(array_intersect(gjc_violation_fields(), gjc_table_columns($db, 'users')));
    if ($count !== 0 && $count !== 3) {
        throw new RuntimeException('Violation legacy fields must be all present or all absent.');
    }
    return $count === 3;
}

/** One-statement projection shared by enforcement and the finance list. */
function gjc_violation_projection(PDO $db): array
{
    $legacy = gjc_violation_has_legacy($db);
    $normalized = gjc_table_exists($db, 'user_violations')
        && !array_diff(array_merge(['user_id'], gjc_violation_fields()), gjc_table_columns($db, 'user_violations'));
    $select = [];
    foreach (gjc_violation_fields() as $field) {
        if ($legacy) {
            $select[] = "u.{$field} AS legacy_{$field}";
        }
        if ($normalized) {
            $select[] = "v.{$field} AS normalized_{$field}";
        }
    }
    return ['legacy' => $legacy, 'normalized' => $normalized, 'select' => $select,
        'join' => $normalized ? ' LEFT JOIN user_violations v ON v.user_id = u.userID' : ''];
}

function gjc_violation_from_projection(array $row, array $projection): array
{
    $legacy = $normalized = [];
    foreach (gjc_violation_fields() as $field) {
        if ($projection['legacy']) {
            $legacy[$field] = $row["legacy_{$field}"];
        }
        if ($projection['normalized']) {
            $normalized[$field] = $field === 'restricted_violation_count'
                ? (int) ($row["normalized_{$field}"] ?? 0) : $row["normalized_{$field}"];
        }
    }
    if (!$projection['normalized']) {
        return $legacy;
    }
    if ($projection['legacy']) {
        foreach (gjc_violation_fields() as $field) {
            $equal = $field === 'restricted_violation_count'
                ? (int) $legacy[$field] === $normalized[$field] : $legacy[$field] === $normalized[$field];
            if (!$equal) {
                return $legacy;
            }
        }
    }
    return $normalized;
}

function gjc_product_violation_state(PDO $db, int $userId, bool $lock = false): ?array
{
    if ($lock && !$db->inTransaction()) {
        throw new RuntimeException('Violation locking requires a transaction.');
    }
    $projection = gjc_violation_projection($db);
    if (!$projection['select']) {
        return null;
    }
    $stmt = $db->prepare('SELECT u.userID, ' . implode(', ', $projection['select'])
        . ' FROM users u' . $projection['join'] . ' WHERE u.userID = ? LIMIT 1'
        . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? gjc_violation_from_projection($row, $projection) : null;
}

function gjc_violation_write(PDO $db, callable $write): mixed
{
    if (!$db->inTransaction()) {
        gjc_ensure_product_violation_schema($db);
    }
    gjc_violation_has_legacy($db);
    if (!gjc_table_exists($db, 'user_violations')
        || array_diff(array_merge(['user_id'], gjc_violation_fields()), gjc_table_columns($db, 'user_violations'))) {
        throw new RuntimeException('Violation migration schema is not ready.');
    }
    $owns = !$db->inTransaction();
    $savepoint = 'gjc_violation_' . bin2hex(random_bytes(8));
    if ($owns) {
        $db->beginTransaction();
    } else {
        $db->exec("SAVEPOINT {$savepoint}");
    }
    try {
        $result = $write();
        if ($owns) {
            $db->commit();
        } else {
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        }
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            if ($owns) {
                $db->rollBack();
            } else {
                $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $db->exec("RELEASE SAVEPOINT {$savepoint}");
            }
        }
        throw $error;
    }
}


function gjc_sync_user_violation(PDO $db, int $userId): void
{
    if (!$db->inTransaction()) {
        throw new RuntimeException('Violation synchronization requires a transaction.');
    }
    if (!gjc_violation_has_legacy($db)) {
        return;
    }
    $db->prepare(
        "INSERT INTO user_violations
            (user_id, restricted_violation_count, restricted_violation_last_at, restricted_suspended_until)
         SELECT userID, restricted_violation_count, restricted_violation_last_at, restricted_suspended_until
           FROM users WHERE userID = ?
         ON DUPLICATE KEY UPDATE
            restricted_violation_count = VALUES(restricted_violation_count),
            restricted_violation_last_at = VALUES(restricted_violation_last_at),
            restricted_suspended_until = VALUES(restricted_suspended_until)"
    )->execute([$userId]);
}

function gjc_clear_product_suspension(PDO $db, int $userId, ?string $cutoff = null): bool
{
    return gjc_violation_write($db, function () use ($db, $userId, $cutoff): bool {
        $state = gjc_product_violation_state($db, $userId, true);
        if (!$state || $state['restricted_suspended_until'] === null
            || ($cutoff !== null && strcmp((string) $state['restricted_suspended_until'], $cutoff) > 0)) {
            return false;
        }
        $table = gjc_violation_has_legacy($db) ? 'users' : 'user_violations';
        $key = $table === 'users' ? 'userID' : 'user_id';
        $db->prepare("UPDATE {$table} SET restricted_suspended_until = NULL WHERE {$key} = ?")->execute([$userId]);
        gjc_sync_user_violation($db, $userId);
        return true;
    });
}

function gjc_expire_product_suspensions(PDO $db): int
{
    $projection = gjc_violation_projection($db);
    if (!$projection['select']) {
        return 0;
    }
    $rows = $db->query('SELECT u.userID, NOW() AS checked_at, ' . implode(', ', $projection['select'])
        . ' FROM users u' . $projection['join'])->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $row) {
        $state = gjc_violation_from_projection($row, $projection);
        if ($state['restricted_suspended_until'] !== null
            && strcmp((string) $state['restricted_suspended_until'], (string) $row['checked_at']) <= 0
            && gjc_clear_product_suspension($db, (int) $row['userID'], (string) $row['checked_at'])) {
            $count++;
        }
    }
    return $count;
}

function gjc_product_violators(PDO $db): array
{
    $projection = gjc_violation_projection($db);
    if (!$projection['select']) {
        return [];
    }
    $rows = $db->query('SELECT u.userID, u.first_name, u.last_name, m.stall_name, m.stall_id, '
        . implode(', ', $projection['select']) . ' FROM users u' . $projection['join']
        . ' LEFT JOIN merchant m ON m.userID = u.userID')->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $state = gjc_violation_from_projection($row, $projection);
        if ((int) $state['restricted_violation_count'] <= 0 && $state['restricted_suspended_until'] === null) {
            continue;
        }
        foreach (gjc_violation_fields() as $field) {
            unset($row["legacy_{$field}"], $row["normalized_{$field}"]);
        }
        $result[] = array_merge($row, $state);
    }
    usort($result, static fn(array $a, array $b): int =>
        (int) ($b['restricted_suspended_until'] !== null) <=> (int) ($a['restricted_suspended_until'] !== null)
        ?: (int) $b['restricted_violation_count'] <=> (int) $a['restricted_violation_count']
        ?: strcmp((string) ($b['restricted_violation_last_at'] ?? ''), (string) ($a['restricted_violation_last_at'] ?? '')));
    return $result;
}

/**
 * Idempotent self-healer for the restricted-product violation counter —
 * see references/schema.md's gjc_ensure_<feature>_schema() convention.
 */
function gjc_ensure_product_violation_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        return;
    }
    gjc_ensure_user_violations_schema($db);
}

/**
 * Returns the datetime until which this merchant account — or, for staff
 * accounts, their owner's whole stall — is suspended over restricted-product
 * strikes, or null when not suspended. Resolves merchant_owner_id from the
 * DB row (not the session) so it works at login time and for student-facing
 * checks alike, and lazily clears suspensions that already served their days
 * (no cron needed) so admin views never show stale ones.
 */
function gjc_merchant_suspended_until(PDO $db, int $userId): ?string
{
    if ($userId <= 0 || !gjc_table_exists($db, 'users')) {
        return null;
    }
    $cols = gjc_table_columns($db, 'users');
    if (in_array('merchant_owner_id', $cols, true)) {
        $stmt = $db->prepare('SELECT merchant_owner_id FROM users WHERE userID = ? LIMIT 1');
        $stmt->execute([$userId]);
        $ownerId = (int) $stmt->fetchColumn();
        if ($ownerId > 0) {
            $userId = $ownerId;
        }
    }
    $state = gjc_product_violation_state($db, $userId);
    $until = $state['restricted_suspended_until'] ?? null;
    if (!$until) {
        return null;
    }
    if (strtotime((string) $until) > time()) {
        return (string) $until;
    }
    return gjc_violation_write($db, function () use ($db, $userId): ?string {
        $state = gjc_product_violation_state($db, $userId, true);
        $until = $state['restricted_suspended_until'] ?? null;
        if (!$until) {
            return null;
        }
        if (strtotime((string) $until) <= time()) {
            gjc_clear_product_suspension($db, $userId);
            return null;
        }
        return (string) $until;
    });
}

/**
 * Records one blocked-restricted-product attempt (add or edit — both call
 * this) against the merchant admin who tried it. At the 'violation_warn_at'
 * threshold the merchant gets an "approaching suspension" warning; hitting
 * 'violation_risk_at' auto-suspends the whole stall for
 * 'violation_suspend_days' days (owner + staff logins, student purchases —
 * enforced via gjc_merchant_suspended_until), notifies the merchant plus
 * every finance/admin user, and spends the strikes: the counter restarts at
 * 0 so reinstatement gets a clean slate. The full history stays in the
 * PRODUCT_RESTRICTION audit trail, and finance can lift a live suspension
 * early from admin/restricted_products.php. Returns the count reached by
 * this attempt — the inventory UI escalates its blocked-attempt modals off it.
 */
function gjc_record_product_violation(PDO $db, int $merchantUserId, string $reason): int
{
    gjc_ensure_product_violation_schema($db);
    $warnAt = gjc_setting_int($db, 'violation_warn_at');
    $riskAt = gjc_setting_int($db, 'violation_risk_at');
    $suspendDays = gjc_setting_int($db, 'violation_suspend_days');

    $transition = gjc_violation_write($db, function () use ($db, $merchantUserId, $riskAt, $suspendDays): array {
        $state = gjc_product_violation_state($db, $merchantUserId, true);
        if ($state === null) {
            return ['count' => 0, 'until' => null];
        }
        $count = (int) $state['restricted_violation_count'] + 1;
        $legacy = gjc_violation_has_legacy($db);
        if ($legacy) {
            $db->prepare(
                "UPDATE users SET restricted_violation_count = restricted_violation_count + 1,
                    restricted_violation_last_at = NOW() WHERE userID = ?"
            )->execute([$merchantUserId]);
        } else {
            $db->prepare(
                "INSERT INTO user_violations (user_id, restricted_violation_count, restricted_violation_last_at)
                 VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE
                    restricted_violation_count = restricted_violation_count + 1,
                    restricted_violation_last_at = NOW()"
            )->execute([$merchantUserId]);
        }
        if ($count >= $riskAt) {
            $table = $legacy ? 'users' : 'user_violations';
            $key = $legacy ? 'userID' : 'user_id';
            $db->prepare("UPDATE {$table} SET restricted_suspended_until = DATE_ADD(NOW(), INTERVAL {$suspendDays} DAY),
                restricted_violation_count = 0 WHERE {$key} = ?")->execute([$merchantUserId]);
        }
        gjc_sync_user_violation($db, $merchantUserId);
        $after = gjc_product_violation_state($db, $merchantUserId);
        return ['count' => $count, 'until' => $after['restricted_suspended_until'] ?? null];
    });
    $count = (int) $transition['count'];


    // >= not ===: if finance lowers the threshold below a merchant's current
    // tally, the warning still fires on the next strike instead of being
    // skipped forever. Bounded above by $riskAt so it can't fire post-suspension.
    if ($count >= $warnAt && $count < $riskAt) {
        $strikesLeft = $riskAt - $count;
        gjc_notify(
            $db,
            $merchantUserId,
            'compliance',
            'Warning: restricted product attempts',
            "You've now had {$count} blocked attempts to list restricted products ({$reason}). "
                . "{$strikesLeft} more and your merchant account will be suspended for {$suspendDays} days — please check the Restricted Products list before adding new items.",
            'triangle-exclamation'
        );
    }

    if ($count >= $riskAt) {
        $untilLabel = date('M d, Y g:i A', strtotime((string) $transition['until']));

        $merchantName = gjc_user_label($db, $merchantUserId);

        if (function_exists('logAudit')) {
            logAudit(
                $db, $merchantUserId, gjc_current_role(),
                'PRODUCT_RESTRICTION', 'users', null,
                [
                    'event' => 'auto_suspended',
                    'merchant_user_id' => $merchantUserId,
                    'strikes' => $count,
                    'suspended_days' => $suspendDays,
                    'suspended_until' => $untilLabel,
                ],
                null,
                ['target_id' => $merchantUserId, 'reason' => $reason]
            );
        }

        gjc_notify(
            $db,
            $merchantUserId,
            'compliance',
            'Your account has been suspended',
            "You reached {$count} blocked attempts to list restricted products ({$reason}). Your merchant account — including staff logins and stall sales — is suspended for {$suspendDays} days, until {$untilLabel}. The GenPay finance team can lift it earlier.",
            'triangle-exclamation'
        );

        $financeIds = $db->query("SELECT userID FROM users WHERE roleID IN (3, 4)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($financeIds as $financeId) {
            gjc_notify(
                $db,
                (int) $financeId,
                'compliance',
                'Merchant account suspended',
                "{$merchantName} hit {$count} blocked attempts to list restricted products and has been auto-suspended until {$untilLabel}. You can lift the suspension early from the Restricted Products page.",
                'triangle-exclamation',
                ADMIN_URL . '/restricted_products.php'
            );
        }
    }

    return $count;
}

/**
 * Current manual lockout metadata; users.status remains the enforcement authority.
 * lockout_kind is preserved as an approved recorded snapshot.
 */
function gjc_ensure_user_suspensions_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        throw new RuntimeException('Suspension state schema requires users.');
    }
    $type = $db->query("SHOW COLUMNS FROM users LIKE 'userID'")->fetch(PDO::FETCH_ASSOC);
    if (!$type || strtolower($type['Type']) !== 'int') {
        throw new RuntimeException('Suspension state FKs require signed INT users.userID.');
    }
    if (gjc_table_exists($db, 'user_suspensions')) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Suspension state schema creation must precede a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_suspensions (
    user_id INT NOT NULL,
    suspended_until DATETIME NULL,
    suspension_reason VARCHAR(255) NULL,
    suspended_at DATETIME NULL,
    suspended_by INT NULL,
    suspension_prev_status VARCHAR(20) NULL,
    suspension_froze_wallet TINYINT(1) NOT NULL DEFAULT 0,
    lockout_kind VARCHAR(12) NULL,
    PRIMARY KEY (user_id),
    KEY idx_user_suspension_actor (suspended_by),
    CONSTRAINT fk_user_suspension_user FOREIGN KEY (user_id)
        REFERENCES users(userID) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_user_suspension_actor FOREIGN KEY (suspended_by)
        REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    gjc_table_columns($db, 'user_suspensions', true);
}

/**
 * Idempotent self-healer for finance-issued account lockouts — the Suspend and
 * Ban actions in admin/users.php. Widens users.status with the 'Suspended' and
 * 'Banned' labels, reading the labels off the live enum rather than a hardcoded
 * list so this never clobbers one a later migration added, then ensures the
 * user_suspensions row describes the lockout: when it lifts (NULL =
 * indefinite, and always NULL for a ban), why, who issued it, and the two bits
 * needed to undo it cleanly — the status it interrupted, and whether the wallet
 * freeze was ours to reverse.
 *
 * Suspension and ban share one current-state row because an account can only be in
 * one of them at a time: both are written to users.status, so claiming either
 * one excludes the other.
 *
 * Distinct from restricted_suspended_until (gjc_ensure_product_violation_schema),
 * which is the automatic merchant-only strike suspension. Both can be live on
 * the same merchant at once; gjc_merchant_sales_blocked() ORs them.
 */
function gjc_ensure_account_suspension_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        return;
    }

    if ($db->inTransaction()) {
        throw new RuntimeException('Suspension schema preparation must precede a transaction.');
    }

    try {
        $column = $db->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = (string) ($column['Type'] ?? '');
        $missing = array_values(array_filter(
            ['Suspended', 'Banned'],
            static fn(string $label): bool => strpos($type, "'{$label}'") === false
        ));
        if ($column && $missing) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $labelMatches);
            $labels = $labelMatches[1] ?? [];
            if ($labels) {
                $labels = array_merge($labels, $missing);
                $enumSql = implode(', ', array_map(
                    static fn(string $label): string => "'" . str_replace("'", "\\'", $label) . "'",
                    $labels
                ));
                $db->exec("ALTER TABLE users MODIFY status ENUM({$enumSql}) NOT NULL DEFAULT 'Active'");
            }
        }
    } catch (\Throwable $ignored) {}

    gjc_ensure_user_suspensions_schema($db);
}

/**
 * The live finance-issued suspension on an account, or null when there isn't
 * one. A timed suspension whose end has already passed is lifted right here
 * rather than by a cron job — the same lazy expiry gjc_merchant_suspended_until()
 * uses — so no caller ever sees a stale one.
 *
 * $inheritStallOwner makes a merchant staff account report its owner's
 * suspension as its own: suspending a stall owner takes the whole stall down,
 * staff logins included, exactly like a restricted-product suspension does.
 *
 * Returns ['user_id','kind','until','reason','at','by'] where 'kind' is
 * 'suspension' or 'ban', and a null 'until' means indefinite — only a finance
 * admin can lift it. A ban always has a null 'until'.
 */
function gjc_account_suspension(PDO $db, int $userId, bool $inheritStallOwner = false): ?array
{
    if ($userId <= 0 || !gjc_table_exists($db, 'users')) {
        return null;
    }
    $cols = gjc_table_columns($db, 'users');
    // Accept either the legacy metadata or the expanded normalized table.
    if (!in_array('suspended_until', $cols, true) && !gjc_table_exists($db, 'user_suspensions')) {
        return null;
    }
    $idCol = gjc_column($db, 'users', ['id', 'userID']);
    if (!$idCol) {
        return null;
    }

    // Own suspension first. Inheriting must be a fallback, never a redirect:
    // reading the owner's row instead of the staff member's would let a
    // directly-suspended staff account keep working under an unsuspended owner.
    $own = gjc_read_account_suspension($db, $idCol, $userId);
    if ($own !== null) {
        return $own;
    }

    if ($inheritStallOwner && in_array('merchant_owner_id', $cols, true)) {
        $stmt = $db->prepare("SELECT merchant_owner_id FROM users WHERE {$idCol} = ? LIMIT 1");
        $stmt->execute([$userId]);
        $ownerId = (int) $stmt->fetchColumn();
        if ($ownerId > 0 && $ownerId !== $userId) {
            return gjc_read_account_suspension($db, $idCol, $ownerId);
        }
    }

    return null;
}

/**
 * Reads status and suspension facts in one statement. During migration, legacy
 * facts remain the compatibility fallback if the normalized copy differs.
 * A missing normalized row reconstructs the empty/default current state.
 */
function gjc_account_lockout_row(PDO $db, string $idCol, int $userId, bool $forUpdate = false): ?array
{
    if (!in_array($idCol, ['id', 'userID'], true)) {
        throw new InvalidArgumentException('Invalid account identifier column.');
    }
    if ($forUpdate && !$db->inTransaction()) {
        throw new RuntimeException('Lockout row locking requires a transaction.');
    }
    $fields = ['suspended_until', 'suspension_reason', 'suspended_at', 'suspended_by',
        'suspension_prev_status', 'suspension_froze_wallet', 'lockout_kind'];
    $columns = gjc_table_columns($db, 'users');
    $hasLegacy = in_array('suspended_until', $columns, true);
    $hasNormalized = gjc_table_exists($db, 'user_suspensions')
        && !array_diff(array_merge(['user_id'], $fields), gjc_table_columns($db, 'user_suspensions'));
    if (!$hasLegacy && !$hasNormalized) {
        return null;
    }

    $select = ['u.status'];
    foreach ($fields as $field) {
        if ($hasLegacy) {
            $value = in_array($field, $columns, true) ? "u.{$field}"
                : ($field === 'suspension_froze_wallet' ? '0' : 'NULL');
            $select[] = "{$value} AS legacy_{$field}";
        }
        if ($hasNormalized) {
            $select[] = "s.{$field} AS normalized_{$field}";
        }
    }
    $join = $hasNormalized ? " LEFT JOIN user_suspensions s ON s.user_id = u.{$idCol}" : '';
    $stmt = $db->prepare("SELECT " . implode(', ', $select)
        . " FROM users u{$join} WHERE u.{$idCol} = ? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$userId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        return null;
    }

    $legacy = $normalized = ['status' => $record['status']];
    foreach ($fields as $field) {
        if ($hasLegacy) {
            $legacy[$field] = $record["legacy_{$field}"];
        }
        if ($hasNormalized) {
            $normalized[$field] = $field === 'suspension_froze_wallet'
                ? ($record["normalized_{$field}"] ?? 0) : $record["normalized_{$field}"];
        }
    }
    if (!$hasNormalized) {
        return $legacy;
    }
    if ($hasLegacy) {
        foreach ($fields as $field) {
            if (!in_array($field, $columns, true)) {
                continue;
            }
            $left = $legacy[$field];
            $right = $normalized[$field];
            $equal = $field === 'suspension_froze_wallet' ? (int) $left === (int) $right
                : (($left === null || $right === null) ? $left === $right : (string) $left === (string) $right);
            if (!$equal) {
                return $legacy;
            }
        }
    }
    return $normalized;
}

/** Hydrates suspension facts for a gated display/API row without running expiry. */
function gjc_with_user_suspension(PDO $db, array $row, int $userId): array
{
    if (!$row || $userId <= 0) {
        return $row;
    }
    $idCol = gjc_column($db, 'users', ['id', 'userID']);
    if (!$idCol) {
        return $row;
    }
    $state = gjc_account_lockout_row($db, $idCol, $userId);
    return $state === null ? $row : array_merge($row, $state);
}

/** Selects due suspension IDs using the shared state reader and database time. */
function gjc_due_account_suspension_ids(PDO $db, string $idCol): array
{
    if (!in_array($idCol, ['id', 'userID'], true)) {
        throw new InvalidArgumentException('Invalid account identifier column.');
    }
    $candidates = $db->query(
        "SELECT {$idCol} AS user_id, NOW() AS checked_at FROM users WHERE status = 'Suspended'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $due = [];
    foreach ($candidates as $candidate) {
        $userId = (int) $candidate['user_id'];
        $state = gjc_account_lockout_row($db, $idCol, $userId);
        // MySQL DATETIME and NOW() share the same canonical sortable format.
        if ($state !== null && $state['status'] === 'Suspended'
            && $state['suspended_until'] !== null
            && strcmp((string) $state['suspended_until'], (string) $candidate['checked_at']) <= 0) {
            $due[] = $userId;
        }
    }
    return $due;
}

/**
 * One row's live lockout — suspension or ban — with the lazy expiry attached.
 * Split out of gjc_account_suspension() only so that function can ask the same
 * question of two accounts (the user, then their stall owner) without
 * duplicating it.
 *
 * A ban is read through this same function on purpose: every enforcement point
 * in the app already calls gjc_account_suspension(), so returning bans here is
 * what makes a ban block logins, sessions, stall sales and inbound money
 * without a second check having to be added at each of those sites. Callers
 * that need to tell them apart read the 'kind' key.
 */
function gjc_read_account_suspension(PDO $db, string $idCol, int $userId): ?array
{
    $row = gjc_account_lockout_row($db, $idCol, $userId);
    $status = $row ? (string) $row['status'] : '';
    if (!$row || !in_array($status, ['Suspended', 'Banned'], true)) {
        return null;
    }

    // users.status remains the enforcement authority. lockout_kind is retained
    // as its recorded snapshot, including NULL on older suspension records.
    $kind = $status === 'Banned' ? 'ban' : 'suspension';

    // Only a suspension can run out. A ban has no end date and must never be
    // expired out from under finance even if a stale one is somehow set.
    $until = $row['suspended_until'] !== null ? (string) $row['suspended_until'] : null;
    if ($kind === 'suspension' && $until !== null && strtotime($until) <= time()) {
        gjc_lift_account_suspension($db, $userId, null, 'auto_expired');
        return null;
    }
    if ($kind === 'ban') {
        $until = null;
    }

    return [
        'user_id' => $userId,
        'kind'    => $kind,
        'until'   => $until,
        'reason'  => (string) ($row['suspension_reason'] ?? ''),
        'at'      => $row['suspended_at'] !== null ? (string) $row['suspended_at'] : null,
        'by'      => (int) ($row['suspended_by'] ?? 0),
    ];
}

/**
 * Puts an account under a finance-issued suspension. $until is the datetime it
 * lifts itself on, or null for indefinite.
 *
 * The status write is an atomic test-and-set (see references/patterns.md): a
 * double-submitted Suspend can't overwrite suspension_prev_status with
 * 'Suspended' and strand the account with nothing to restore to. The same
 * test-and-set excludes 'Banned', so a suspension can never quietly downgrade a
 * ban into something that expires on its own.
 * Returns false when the account doesn't exist, is already suspended, or is
 * banned.
 */
function gjc_suspend_account(PDO $db, int $userId, ?string $until, string $reason, int $suspendedBy): bool
{
    return gjc_claim_account_lockout($db, $userId, 'Suspended', $until, $reason, $suspendedBy);
}

/**
 * Puts an account under a permanent finance-issued ban — the Ban action in
 * admin/users.php. Same lockout record as a suspension (see
 * gjc_ensure_account_suspension_schema) with no end date, so nothing ever
 * expires it: gjc_expire_due_suspensions() only sweeps status='Suspended', and
 * gjc_read_account_suspension() refuses to expire a ban even if an end date
 * somehow lands on the row. Only gjc_lift_account_ban() clears it.
 *
 * Returns false when the account doesn't exist or is already banned. An
 * account that is merely suspended CAN be banned — that is an escalation, and
 * the ban inherits the status the suspension interrupted so lifting the ban
 * later restores the original state rather than the suspension.
 */
function gjc_ban_account(PDO $db, int $userId, string $reason, int $bannedBy): bool
{
    return gjc_claim_account_lockout($db, $userId, 'Banned', null, $reason, $bannedBy);
}

/** Loads the engine without allowing its PDO bootstrap to replace the caller's connection. */
function gjc_account_lockout_engine(PDO $connection): CirculationEngine
{
    require_once __DIR__ . '/CirculationEngine.php';
    return new CirculationEngine($connection);
}

/** Returns the physical legacy layout; a partial removal is never accepted. */
function gjc_has_legacy_user_suspension(PDO $db): bool
{
    $fields = ['suspended_until', 'suspension_reason', 'suspended_at', 'suspended_by',
        'suspension_prev_status', 'suspension_froze_wallet', 'lockout_kind'];
    $count = count(array_intersect($fields, gjc_table_columns($db, 'users')));
    if ($count !== 0 && $count !== count($fields)) {
        throw new RuntimeException('Suspension legacy columns must be all present or all absent.');
    }
    return $count === count($fields);
}

/** Writes current normalized facts inside the already-locked account transaction. */
function gjc_store_user_suspension(
    PDO $db, int $userId, ?string $until, ?string $reason, ?int $actorId,
    ?string $previousStatus, int $frozeWallet, ?string $kind, bool $stampNow
): void {
    if (!$db->inTransaction()) {
        throw new RuntimeException('Suspension storage requires its locked user transaction.');
    }
    $at = $stampNow ? 'NOW()' : 'NULL';
    $db->prepare(
        "INSERT INTO user_suspensions
            (user_id, suspended_until, suspension_reason, suspended_at, suspended_by,
             suspension_prev_status, suspension_froze_wallet, lockout_kind)
         VALUES (?, ?, ?, {$at}, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            suspended_until = VALUES(suspended_until),
            suspension_reason = VALUES(suspension_reason),
            suspended_at = VALUES(suspended_at),
            suspended_by = VALUES(suspended_by),
            suspension_prev_status = VALUES(suspension_prev_status),
            suspension_froze_wallet = VALUES(suspension_froze_wallet),
            lockout_kind = VALUES(lockout_kind)"
    )->execute([$userId, $until, $reason, $actorId, $previousStatus, $frozeWallet, $kind]);
}

/**
 * Runs a suspension metadata write without committing a caller's transaction.
 * Schema preparation is allowed only before a transaction; inside one, this
 * requires the already-expanded schema and performs no DDL.
 */
function gjc_account_lockout_write(PDO $db, callable $write): bool
{
    if (!$db->inTransaction()) {
        gjc_ensure_account_suspension_schema($db);
    }
    $fields = ['suspended_until', 'suspension_reason', 'suspended_at',
        'suspended_by', 'suspension_prev_status', 'suspension_froze_wallet', 'lockout_kind'];
    gjc_has_legacy_user_suspension($db);
    if (!gjc_table_exists($db, 'user_suspensions')
        || array_diff(array_merge(['user_id'], $fields), gjc_table_columns($db, 'user_suspensions'))) {
        throw new RuntimeException('The suspension migration schema is not ready.');
    }

    $ownsTransaction = !$db->inTransaction();
    $savepoint = 'gjc_lockout_' . bin2hex(random_bytes(8));
    if ($ownsTransaction) {
        $db->beginTransaction();
    } else {
        $db->exec("SAVEPOINT {$savepoint}");
    }
    try {
        $changed = $write();
        if ($ownsTransaction) {
            $db->commit();
        } else {
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        }
        return $changed;
    } catch (\Throwable $error) {
        if ($db->inTransaction()) {
            if ($ownsTransaction) {
                $db->rollBack();
            } else {
                $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $db->exec("RELEASE SAVEPOINT {$savepoint}");
            }
        }
        throw $error;
    }
}

/** Copies the persisted legacy facts exactly, including the recorded kind snapshot. */
function gjc_sync_user_suspension(PDO $db, string $idCol, int $userId): void
{
    if (!$db->inTransaction() || !in_array($idCol, ['id', 'userID'], true)) {
        throw new RuntimeException('Suspension synchronization requires its locked user transaction.');
    }
    if (!gjc_has_legacy_user_suspension($db)) {
        return;
    }
    $db->prepare(
        "INSERT INTO user_suspensions
            (user_id, suspended_until, suspension_reason, suspended_at, suspended_by,
             suspension_prev_status, suspension_froze_wallet, lockout_kind)
         SELECT {$idCol}, suspended_until, suspension_reason, suspended_at, suspended_by,
                suspension_prev_status, suspension_froze_wallet, lockout_kind
           FROM users WHERE {$idCol} = ?
         ON DUPLICATE KEY UPDATE
            suspended_until = VALUES(suspended_until),
            suspension_reason = VALUES(suspension_reason),
            suspended_at = VALUES(suspended_at),
            suspended_by = VALUES(suspended_by),
            suspension_prev_status = VALUES(suspension_prev_status),
            suspension_froze_wallet = VALUES(suspension_froze_wallet),
            lockout_kind = VALUES(lockout_kind)"
    )->execute([$userId]);
}

/**
 * The one write behind both Suspend and Ban. Claims users.status atomically,
 * remembers the status it interrupted so the lift can restore it, and freezes
 * the student wallet belt-and-braces — the login block already stops the
 * account acting — only when the wallet wasn't frozen already, so lifting can
 * tell our freeze apart from a parent's and never unlocks one it didn't set.
 *
 * $target is 'Suspended' or 'Banned'. The claim excludes the target status
 * itself (a repeat of the same action is a no-op) but a ban may still claim a
 * suspended account, which is how escalation works.
 */
function gjc_claim_account_lockout(PDO $db, int $userId, string $target, ?string $until, string $reason, int $actorId): bool
{
    return gjc_account_lockout_write($db, function () use ($db, $userId, $target, $until, $reason, $actorId): bool {
        $idCol = gjc_column($db, 'users', ['id', 'userID']) ?: 'userID';
        $hasLegacy = gjc_has_legacy_user_suspension($db);
        $hasKind = $hasLegacy;

        $row = gjc_account_lockout_row($db, $idCol, $userId, true);
        if (!$row) {
            return false;
        }

        // Escalating a suspension to a ban must carry the pre-suspension status
        // forward, not record 'Suspended' as the thing to restore to — otherwise
        // lifting the ban would drop the account back into a suspension that no
        // longer has a reason or an end date behind it.
        $prev = (string) $row['status'];
        $frozeWallet = 0;
        if (in_array($prev, ['Suspended', 'Banned'], true)) {
            $carried = trim((string) ($row['suspension_prev_status'] ?? ''));
            $prev = ($carried !== '' && !in_array($carried, ['Suspended', 'Banned'], true)) ? $carried : 'Active';
            // The outgoing lockout may already have frozen the wallet, in which
            // case the freeze attempt below is a no-op and would leave the flag at
            // 0 — the ban would then refuse to unfreeze on lift, stranding a wallet
            // that only GenPay had locked. Carry the claim forward instead.
            $frozeWallet = (int) ($row['suspension_froze_wallet'] ?? 0);
        }

        // A suspension may not claim an account that is suspended OR banned; a ban
        // may not re-claim one that is already banned.
        $blocked = $target === 'Banned' ? ["'Banned'"] : ["'Suspended'", "'Banned'"];
        $kindSet = $hasKind ? ", lockout_kind = " . ($target === 'Banned' ? "'ban'" : "'suspension'") : '';

        if ($hasLegacy) {
            $claim = $db->prepare(
                "UPDATE users
                    SET status = ?, suspended_until = ?, suspension_reason = ?,
                        suspended_at = NOW(), suspended_by = ?, suspension_prev_status = ?,
                        suspension_froze_wallet = ?{$kindSet}
                  WHERE {$idCol} = ? AND status NOT IN (" . implode(', ', $blocked) . ")"
            );
            $claim->execute([$target, $until, mb_substr($reason, 0, 255), $actorId, $prev, $frozeWallet, $userId]);
        } else {
            $claim = $db->prepare(
                "UPDATE users SET status = ? WHERE {$idCol} = ? AND status NOT IN (" . implode(', ', $blocked) . ")"
            );
            $claim->execute([$target, $userId]);
        }
        if ($claim->rowCount() === 0) {
            return false;
        }

        if (!$hasLegacy) {
            gjc_store_user_suspension($db, $userId, $until, mb_substr($reason, 0, 255),
                $actorId, $prev, $frozeWallet, $target === 'Banned' ? 'ban' : 'suspension', true);
        }

        if (gjc_table_exists($db, 'student_wallets')) {
            try {
                if (gjc_account_lockout_engine($db)->freezeStudentWalletForLockout($userId)) {
                    $freezeTable = $hasLegacy ? 'users' : 'user_suspensions';
                    $freezeId = $hasLegacy ? $idCol : 'user_id';
                    $db->prepare("UPDATE {$freezeTable} SET suspension_froze_wallet = 1 WHERE {$freezeId} = ?")->execute([$userId]);
                }
            } catch (\Throwable $ignored) {}
        }

        gjc_sync_user_suspension($db, $idCol, $userId);
        return true;
    });
}

/**
 * Clears a suspension and puts the account back the way it was: status returns
 * to whatever the suspension interrupted, so a staff account their owner had
 * deactivated stays Inactive instead of being silently reactivated, and the
 * student wallet is unfrozen only when the suspension is what froze it.
 *
 * Shared by the lazy expiry in gjc_account_suspension(), the sweep in
 * gjc_expire_due_suspensions(), and the lift_suspension action, so all three
 * undo a suspension identically. $liftedBy is null for an automatic expiry.
 * Returns false when the account wasn't suspended.
 */
function gjc_lift_account_suspension(PDO $db, int $userId, ?int $liftedBy, string $event = 'suspension_lifted'): bool
{
    return gjc_clear_account_lockout($db, $userId, 'Suspended', $liftedBy, $event);
}

/**
 * Lifts a permanent ban, putting the account back the way the ban found it.
 * Deliberately a separate entry point from gjc_lift_account_suspension(): a ban
 * has no automatic path out, so nothing but an explicit finance action — the
 * lift_ban endpoint in admin/api/users.php — can ever reach this.
 * Returns false when the account isn't banned.
 */
function gjc_lift_account_ban(PDO $db, int $userId, ?int $liftedBy): bool
{
    return gjc_clear_account_lockout($db, $userId, 'Banned', $liftedBy, 'ban_lifted');
}

/**
 * The one write behind lifting either kind of lockout. $fromStatus is the
 * status being cleared, and is part of the test-and-set, so a lift aimed at a
 * suspension can never clear a ban that replaced it between the read and the
 * write (or the reverse).
 */
function gjc_clear_account_lockout(PDO $db, int $userId, string $fromStatus, ?int $liftedBy, string $event): bool
{
    $row = null;
    $restored = '';
    $changed = gjc_account_lockout_write($db, function () use ($db, $userId, $fromStatus, &$row, &$restored): bool {
        $idCol = gjc_column($db, 'users', ['id', 'userID']) ?: 'userID';
        $hasLegacy = gjc_has_legacy_user_suspension($db);

        $row = gjc_account_lockout_row($db, $idCol, $userId, true);
        if (!$row || (string) $row['status'] !== $fromStatus) {
            return false;
        }

        $restored = (string) ($row['suspension_prev_status'] ?? '');
        if ($restored === '' || in_array($restored, ['Suspended', 'Banned'], true)) {
            $restored = 'Active';
        }

        // Test-and-set again: two concurrent lifts (an admin clicking while the
        // page-load sweep runs) must not both unfreeze the wallet and both log.
        $kindSet = in_array('lockout_kind', gjc_table_columns($db, 'users'), true)
            ? ', lockout_kind = NULL'
            : '';
        if ($hasLegacy) {
            $claim = $db->prepare(
                "UPDATE users
                    SET status = ?, suspended_until = NULL, suspension_reason = NULL,
                        suspended_at = NULL, suspended_by = NULL,
                        suspension_prev_status = NULL, suspension_froze_wallet = 0{$kindSet}
                  WHERE {$idCol} = ? AND status = ?"
            );
            $claim->execute([$restored, $userId, $fromStatus]);
        } else {
            $claim = $db->prepare("UPDATE users SET status = ? WHERE {$idCol} = ? AND status = ?");
            $claim->execute([$restored, $userId, $fromStatus]);
        }
        if ($claim->rowCount() === 0) {
            return false;
        }

        if (!$hasLegacy) {
            gjc_store_user_suspension($db, $userId, null, null, null, null, 0, null, false);
        }

        if ((int) ($row['suspension_froze_wallet'] ?? 0) === 1 && gjc_table_exists($db, 'student_wallets')) {
            try {
                gjc_account_lockout_engine($db)->unfreezeStudentWalletForLockout($userId);
            } catch (\Throwable $ignored) {}
        }

        gjc_sync_user_suspension($db, $idCol, $userId);
        return true;
    });
    if (!$changed) {
        return false;
    }
    $isBan = $fromStatus === 'Banned';

    // Both paths notify, so the account always learns access is back — whether
    // an admin lifted it early or the clock simply ran out.
    if ($isBan) {
        $noticeTitle = 'Account ban lifted';
        $noticeBody  = 'A GenPay finance admin lifted the ban on your account. You can sign in and use your account again.';
    } elseif ($event === 'auto_expired') {
        $noticeTitle = 'Account suspension lifted';
        $noticeBody  = 'Your suspension period has ended. You can sign in and use your GenPay account again.';
    } else {
        $noticeTitle = 'Account suspension lifted';
        $noticeBody  = 'A GenPay finance admin lifted your account suspension. You can sign in and use your account again.';
    }
    gjc_notify($db, $userId, 'compliance', $noticeTitle, $noticeBody, 'circle-check');

    if (function_exists('logAudit')) {
        logAudit(
            $db,
            $liftedBy > 0 ? $liftedBy : $userId,
            $liftedBy > 0 ? gjc_current_role() : 'finance',
            'USER_ACCOUNT',
            'users',
            [
                'userID' => $userId,
                'status' => $fromStatus,
                'suspended_until' => $row['suspended_until'],
                'suspension_reason' => $row['suspension_reason'],
            ],
            [
                'event' => $event,
                'userID' => $userId,
                'status' => $restored,
                'lifted_by' => $liftedBy,
                'wallet_unfrozen' => (int) ($row['suspension_froze_wallet'] ?? 0) === 1,
            ],
            null,
            ['target_id' => $userId, 'reason' => (string) ($row['suspension_reason'] ?? '')]
        );
    }

    return true;
}

/**
 * Lifts every timed suspension that has run out. gjc_account_suspension()
 * already expires them one row at a time as it reads them, but admin/users.php
 * renders the whole table from a single query and never calls it per row —
 * this sweeps first so the list can't show a suspension that ended hours ago.
 */
function gjc_expire_due_suspensions(PDO $db): int
{
    gjc_ensure_account_suspension_schema($db);
    $idCol = gjc_column($db, 'users', ['id', 'userID']) ?: 'userID';

    try {
        $due = gjc_due_account_suspension_ids($db, $idCol);
    } catch (\Throwable $ignored) {
        return 0;
    }

    $lifted = 0;
    foreach ($due as $id) {
        if (gjc_lift_account_suspension($db, (int) $id, null, 'auto_expired')) {
            $lifted++;
        }
    }
    return $lifted;
}

/**
 * The one sentence every lockout surface shows — the login error, the login
 * page banner, the blocked-credit responses. Keeps the wording identical
 * wherever a locked-out account gets turned away, and says plainly which kind
 * of lockout it is: a suspension names its end date (or says indefinite), a ban
 * says permanent and never offers one.
 *
 * Takes the array gjc_account_suspension() returns.
 */
function gjc_suspension_notice(array $suspension, string $subject = 'This account'): string
{
    $until = $suspension['until'] ?? null;
    $reason = trim((string) ($suspension['reason'] ?? ''));
    $isBan = ($suspension['kind'] ?? 'suspension') === 'ban';

    if ($isBan) {
        $notice = $subject . ' has been permanently banned from GenPay.';
    } else {
        $notice = $until
            ? $subject . ' is suspended until ' . date('M d, Y g:i A', strtotime((string) $until)) . '.'
            : $subject . ' is suspended indefinitely.';
    }
    if ($reason !== '') {
        $notice .= ' Reason: ' . $reason;
        if (!str_ends_with($notice, '.')) {
            $notice .= '.';
        }
    }
    if ($isBan) {
        $notice .= ' Contact the GenPay finance office if you believe this is a mistake.';
    } elseif ($until === null) {
        $notice .= ' Contact the GenPay finance office to have it reviewed.';
    }

    return $notice;
}

/**
 * True when a stall may not sell right now — either a restricted-product strike
 * suspension or a finance-issued suspension on the stall owner is live. Every
 * student/parent-facing sales path checks this instead of
 * gjc_merchant_suspended_until() alone, so a manual Suspend closes the stall the
 * same way an automatic one does.
 */
function gjc_merchant_sales_blocked(PDO $db, int $merchantUserId): bool
{
    return gjc_merchant_suspended_until($db, $merchantUserId) !== null
        || gjc_account_suspension($db, $merchantUserId, true) !== null;
}

/**
 * Why money may not be credited into this account right now, or null when it
 * may. Every inbound-money path (top-up approval, parent allowance, P2P
 * transfer) calls this on the RECEIVING user: the login block only stops a
 * suspended account from acting for itself, and can't stop someone else's
 * session from pushing funds at it.
 */
function gjc_funds_in_block_reason(PDO $db, int $userId): ?string
{
    $suspension = gjc_account_suspension($db, $userId);
    if ($suspension === null) {
        $student = $db->prepare('SELECT sw.is_frozen FROM student_wallets sw JOIN users u ON u.userID = sw.user_id WHERE sw.user_id = ? AND u.roleID = 1');
        $student->execute([$userId]);
        $frozen = $student->fetchColumn();
        if ($frozen !== false && ((int) $frozen === 1 || gjc_student_wallet_state($db, $userId) !== 'active')) {
            return 'This student wallet is frozen. Cash-in and incoming transfers are unavailable; the balance can still be withdrawn at the Finance office.';
        }
        return null;
    }
    return gjc_suspension_notice($suspension, gjc_user_label($db, $userId) . "'s account")
        . (($suspension['kind'] ?? 'suspension') === 'ban'
            ? ' Funds cannot be credited to it.'
            : ' Funds cannot be credited to it until the suspension is lifted.');
}

/**
 * The users.userID behind a wallet row — 'student' reads student_wallets.user_id
 * directly, 'parent' hops parent_wallets -> parents.user_id. Credit paths that
 * only ever hold a wallet id use this to name the receiving account for
 * gjc_funds_in_block_reason(). Returns 0 when it can't be resolved, which
 * callers must treat as "don't block" rather than "blocked".
 */
function gjc_wallet_owner_user_id(PDO $db, string $kind, int $walletId): int
{
    if ($walletId <= 0) {
        return 0;
    }
    try {
        if ($kind === 'student' && gjc_table_exists($db, 'student_wallets')) {
            $stmt = $db->prepare("SELECT user_id FROM student_wallets WHERE id = ? LIMIT 1");
            $stmt->execute([$walletId]);
            return (int) $stmt->fetchColumn();
        }
        if ($kind === 'parent' && gjc_table_exists($db, 'parent_wallets') && gjc_table_exists($db, 'parents')) {
            $stmt = $db->prepare(
                "SELECT p.user_id
                   FROM parent_wallets pw
                   JOIN parents p ON p.id = pw.parent_id
                  WHERE pw.id = ? LIMIT 1"
            );
            $stmt->execute([$walletId]);
            return (int) $stmt->fetchColumn();
        }
    } catch (\Throwable $ignored) {}

    return 0;
}

function gjc_column(PDO $db, string $table, array $candidates): ?string
{
    $columns = gjc_table_columns($db, $table);
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

/** D2 application-owned detail schemas. No live routing change during EXPAND. */
function gjc_stall_application_detail_definitions(): array
{
    return array (
  'ddl' => 
  array (
    'stall_application_applicants' => 'CREATE TABLE IF NOT EXISTS stall_application_applicants (
    application_id INT UNSIGNED NOT NULL,
    first_name varchar(60) NOT NULL DEFAULT \'\',
    middle_name varchar(60) NULL DEFAULT NULL,
    last_name varchar(60) NOT NULL DEFAULT \'\',
    suffix varchar(20) NULL DEFAULT NULL,
    sex enum(\'male\',\'female\') NOT NULL DEFAULT \'male\',
    contact_number varchar(15) NOT NULL,
    email varchar(255) NOT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_1_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_meetings' => 'CREATE TABLE IF NOT EXISTS stall_application_meetings (
    application_id INT UNSIGNED NOT NULL,
    meetup_scheduled_at datetime NULL DEFAULT NULL,
    meetup_location varchar(255) NULL DEFAULT NULL,
    meetup_notes text NULL DEFAULT NULL,
    meetup_scheduled_by int NULL DEFAULT NULL,
    meetup_scheduled_email_sent_at datetime NULL DEFAULT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_2_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_2_meetup_scheduled_by FOREIGN KEY (meetup_scheduled_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_payments' => 'CREATE TABLE IF NOT EXISTS stall_application_payments (
    application_id INT UNSIGNED NOT NULL,
    down_payment_amount decimal(10,2) NULL DEFAULT NULL,
    down_payment_reference varchar(80) NULL DEFAULT NULL,
    down_payment_notes text NULL DEFAULT NULL,
    down_payment_recorded_by int NULL DEFAULT NULL,
    down_payment_recorded_at datetime NULL DEFAULT NULL,
    deposit_amount decimal(10,2) NULL DEFAULT NULL,
    advance_amount decimal(10,2) NULL DEFAULT NULL,
    rental_start_date date NULL DEFAULT NULL,
    payment_schedule_day tinyint unsigned NULL DEFAULT NULL,
    payment_method varchar(20) NULL DEFAULT NULL,
    payment_ref_no varchar(60) NULL DEFAULT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_3_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_3_down_payment_recorded_by FOREIGN KEY (down_payment_recorded_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_contracts' => 'CREATE TABLE IF NOT EXISTS stall_application_contracts (
    application_id INT UNSIGNED NOT NULL,
    contract_file varchar(500) NULL DEFAULT NULL,
    contract_uploaded_at datetime NULL DEFAULT NULL,
    contract_uploaded_by int NULL DEFAULT NULL,
    contract_ref varchar(40) NULL DEFAULT NULL,
    signed_at datetime NULL DEFAULT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_4_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_4_contract_uploaded_by FOREIGN KEY (contract_uploaded_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_decisions' => 'CREATE TABLE IF NOT EXISTS stall_application_decisions (
    application_id INT UNSIGNED NOT NULL,
    reviewed_by int NULL DEFAULT NULL,
    reviewed_at datetime NULL DEFAULT NULL,
    rejection_reason text NULL DEFAULT NULL,
    initially_approved_by int NULL DEFAULT NULL,
    initially_approved_at datetime NULL DEFAULT NULL,
    merchant_user_id int NULL DEFAULT NULL,
    awarded_by int NULL DEFAULT NULL,
    awarded_at datetime NULL DEFAULT NULL,
    cancelled_by int NULL DEFAULT NULL,
    cancelled_at datetime NULL DEFAULT NULL,
    cancel_reason text NULL DEFAULT NULL,
    first_viewed_at datetime NULL DEFAULT NULL,
    first_viewed_by int NULL DEFAULT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_5_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_initially_approved_by FOREIGN KEY (initially_approved_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_merchant_user_id FOREIGN KEY (merchant_user_id) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_awarded_by FOREIGN KEY (awarded_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_first_viewed_by FOREIGN KEY (first_viewed_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_addresses' => 'CREATE TABLE IF NOT EXISTS stall_application_addresses (
    application_id INT UNSIGNED NOT NULL,
    street VARCHAR(150) NOT NULL DEFAULT \'\',
    location_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_address_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_address_location FOREIGN KEY (location_id) REFERENCES address_locations(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_documents' => 'CREATE TABLE IF NOT EXISTS stall_application_documents (
    application_id INT UNSIGNED NOT NULL,
    document_kind enum(\'profile_picture\',\'business_permit\',\'sanitary_permit\',\'gjc_requirements\',\'clearance\') NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    PRIMARY KEY (application_id, document_kind),
    CONSTRAINT fk_d2_document_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_rejections' => 'CREATE TABLE IF NOT EXISTS stall_application_rejections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id INT UNSIGNED NOT NULL,
    event_key CHAR(64) NOT NULL,
    rejected_at_step TINYINT UNSIGNED NOT NULL,
    rejection_reason TEXT NOT NULL,
    rejected_by INT NULL,
    rejected_at DATETIME NOT NULL,
    event_source ENUM(\'legacy_state\',\'workflow\') NOT NULL DEFAULT \'workflow\',
    reactivated_at DATETIME NULL,
    reactivated_by INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_d2_rejection_event (event_key),
    KEY idx_d2_rejection_application (application_id),
    CONSTRAINT fk_d2_rejection_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_rejection_actor FOREIGN KEY (rejected_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_reactivation_actor FOREIGN KEY (reactivated_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
  ),
  'types' => 
  array (
    'stall_application_applicants' => 
    array (
      'application_id' => 'int unsigned',
      'first_name' => 'varchar(60)',
      'middle_name' => 'varchar(60)',
      'last_name' => 'varchar(60)',
      'suffix' => 'varchar(20)',
      'sex' => 'enum(\'male\',\'female\')',
      'contact_number' => 'varchar(15)',
      'email' => 'varchar(255)',
    ),
    'stall_application_meetings' => 
    array (
      'application_id' => 'int unsigned',
      'meetup_scheduled_at' => 'datetime',
      'meetup_location' => 'varchar(255)',
      'meetup_notes' => 'text',
      'meetup_scheduled_by' => 'int',
      'meetup_scheduled_email_sent_at' => 'datetime',
    ),
    'stall_application_payments' => 
    array (
      'application_id' => 'int unsigned',
      'down_payment_amount' => 'decimal(10,2)',
      'down_payment_reference' => 'varchar(80)',
      'down_payment_notes' => 'text',
      'down_payment_recorded_by' => 'int',
      'down_payment_recorded_at' => 'datetime',
      'deposit_amount' => 'decimal(10,2)',
      'advance_amount' => 'decimal(10,2)',
      'rental_start_date' => 'date',
      'payment_schedule_day' => 'tinyint unsigned',
      'payment_method' => 'varchar(20)',
      'payment_ref_no' => 'varchar(60)',
    ),
    'stall_application_contracts' => 
    array (
      'application_id' => 'int unsigned',
      'contract_file' => 'varchar(500)',
      'contract_uploaded_at' => 'datetime',
      'contract_uploaded_by' => 'int',
      'contract_ref' => 'varchar(40)',
      'signed_at' => 'datetime',
    ),
    'stall_application_decisions' => 
    array (
      'application_id' => 'int unsigned',
      'reviewed_by' => 'int',
      'reviewed_at' => 'datetime',
      'rejection_reason' => 'text',
      'initially_approved_by' => 'int',
      'initially_approved_at' => 'datetime',
      'merchant_user_id' => 'int',
      'awarded_by' => 'int',
      'awarded_at' => 'datetime',
      'cancelled_by' => 'int',
      'cancelled_at' => 'datetime',
      'cancel_reason' => 'text',
      'first_viewed_at' => 'datetime',
      'first_viewed_by' => 'int',
    ),
    'stall_application_addresses' => 
    array (
      'application_id' => 'int unsigned',
      'street' => 'varchar(150)',
      'location_id' => 'int unsigned',
    ),
    'stall_application_documents' => 
    array (
      'application_id' => 'int unsigned',
      'document_kind' => 'enum(\'profile_picture\',\'business_permit\',\'sanitary_permit\',\'gjc_requirements\',\'clearance\')',
      'file_path' => 'varchar(500)',
    ),
    'stall_application_rejections' => 
    array (
      'id' => 'bigint unsigned',
      'application_id' => 'int unsigned',
      'event_key' => 'char(64)',
      'rejected_at_step' => 'tinyint unsigned',
      'rejection_reason' => 'text',
      'rejected_by' => 'int',
      'rejected_at' => 'datetime',
      'event_source' => 'enum(\'legacy_state\',\'workflow\')',
      'reactivated_at' => 'datetime',
      'reactivated_by' => 'int',
    ),
  ),
  'fks' => 
  array (
    'stall_application_applicants' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_1_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
    ),
    'stall_application_meetings' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_2_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'meetup_scheduled_by',
        'name' => 'fk_d2_2_meetup_scheduled_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
    ),
    'stall_application_payments' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_3_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'down_payment_recorded_by',
        'name' => 'fk_d2_3_down_payment_recorded_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
    ),
    'stall_application_contracts' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_4_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'contract_uploaded_by',
        'name' => 'fk_d2_4_contract_uploaded_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
    ),
    'stall_application_decisions' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_5_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'reviewed_by',
        'name' => 'fk_d2_5_reviewed_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      2 => 
      array (
        'column' => 'initially_approved_by',
        'name' => 'fk_d2_5_initially_approved_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      3 => 
      array (
        'column' => 'merchant_user_id',
        'name' => 'fk_d2_5_merchant_user_id',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      4 => 
      array (
        'column' => 'awarded_by',
        'name' => 'fk_d2_5_awarded_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      5 => 
      array (
        'column' => 'cancelled_by',
        'name' => 'fk_d2_5_cancelled_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      6 => 
      array (
        'column' => 'first_viewed_by',
        'name' => 'fk_d2_5_first_viewed_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
    ),
    'stall_application_addresses' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_address_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'location_id',
        'name' => 'fk_d2_address_location',
        'target' => 'address_locations',
        'target_column' => 'id',
      ),
    ),
    'stall_application_documents' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_document_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
    ),
    'stall_application_rejections' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_rejection_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'rejected_by',
        'name' => 'fk_d2_rejection_actor',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      2 => 
      array (
        'column' => 'reactivated_by',
        'name' => 'fk_d2_reactivation_actor',
        'target' => 'users',
        'target_column' => 'userID',
      ),
    ),
  ),
  'groups' => 
  array (
    'stall_application_applicants' => 
    array (
      0 => 'first_name',
      1 => 'middle_name',
      2 => 'last_name',
      3 => 'suffix',
      4 => 'sex',
      5 => 'contact_number',
      6 => 'email',
    ),
    'stall_application_meetings' => 
    array (
      0 => 'meetup_scheduled_at',
      1 => 'meetup_location',
      2 => 'meetup_notes',
      3 => 'meetup_scheduled_by',
      4 => 'meetup_scheduled_email_sent_at',
    ),
    'stall_application_payments' => 
    array (
      0 => 'down_payment_amount',
      1 => 'down_payment_reference',
      2 => 'down_payment_notes',
      3 => 'down_payment_recorded_by',
      4 => 'down_payment_recorded_at',
      5 => 'deposit_amount',
      6 => 'advance_amount',
      7 => 'rental_start_date',
      8 => 'payment_schedule_day',
      9 => 'payment_method',
      10 => 'payment_ref_no',
    ),
    'stall_application_contracts' => 
    array (
      0 => 'contract_file',
      1 => 'contract_uploaded_at',
      2 => 'contract_uploaded_by',
      3 => 'contract_ref',
      4 => 'signed_at',
    ),
    'stall_application_decisions' => 
    array (
      0 => 'reviewed_by',
      1 => 'reviewed_at',
      2 => 'rejection_reason',
      3 => 'initially_approved_by',
      4 => 'initially_approved_at',
      5 => 'merchant_user_id',
      6 => 'awarded_by',
      7 => 'awarded_at',
      8 => 'cancelled_by',
      9 => 'cancelled_at',
      10 => 'cancel_reason',
      11 => 'first_viewed_at',
      12 => 'first_viewed_by',
    ),
  ),
  'document_kinds' => 
  array (
    0 => 'profile_picture',
    1 => 'business_permit',
    2 => 'sanitary_permit',
    3 => 'gjc_requirements',
    4 => 'clearance',
  ),
);
}

/** Explicit D2 EXPAND: default is a read-only plan, never called by page loads. */
function gjc_ensure_stall_application_details_schema(PDO $db, bool $apply = false): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('D2 EXPAND requires CLI, ewallet and no transaction.');
    }
    foreach ([['stall_applications','id','int unsigned'],['users','userID','int'],['address_locations','id','int unsigned']] as [$table,$column,$type]) {
        if (!gjc_table_exists($db,$table)) {
            throw new RuntimeException('Missing required parent table: '.$table);
        }
        $definition=$db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch(PDO::FETCH_ASSOC);
        if (!$definition || strtolower($definition['Type'])!==$type) {
            throw new RuntimeException('D2 parent identifiers have unexpected types.');
        }
    }
    if (gjc_stall_application_has_legacy($db)) {
    foreach (['reviewed_by','meetup_scheduled_by','down_payment_recorded_by','contract_uploaded_by',
        'initially_approved_by','merchant_user_id','awarded_by','cancelled_by','first_viewed_by'] as $actor) {
        $invalid=(int)$db->query('SELECT COUNT(*) FROM stall_applications a LEFT JOIN users u ON u.userID=a.'.$actor
            .' WHERE a.'.$actor.' IS NOT NULL AND (u.userID IS NULL OR a.'.$actor.'>2147483647)')->fetchColumn();
        if ($invalid) {
            throw new RuntimeException('D2 actor data requires separate review: '.$actor);
        }
    }
    $invalidRejected=(int)$db->query("SELECT COUNT(*) FROM stall_applications WHERE status='rejected'
        AND (rejection_reason IS NULL OR rejection_reason='' OR reviewed_at IS NULL)")->fetchColumn();
    if ($invalidRejected) {
        throw new RuntimeException('D2 cannot invent missing rejection facts.');
    }
    }
    $definitions=gjc_stall_application_detail_definitions();$plan=[];
    foreach ($definitions['ddl'] as $table=>$sql) {
        if (!gjc_table_exists($db,$table)) {
            foreach ($definitions['fks'][$table] as $fk) {
                $find=$db->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?');
                $find->execute([$fk['name']]);
                if ((int)$find->fetchColumn()) {
                    throw new RuntimeException('D2 foreign-key name already exists: '.$fk['name']);
                }
            }
            $plan[$table]=$sql;continue;
        }
        $actual=[];foreach($db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC) as $column)$actual[$column['Field']]=strtolower($column['Type']);
        if ($actual!==$definitions['types'][$table]) {
            throw new RuntimeException('Unexpected installed D2 columns: '.$table);
        }
        $primary=$db->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$table."' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
        $expectedPrimary=$table==='stall_application_documents'?['application_id','document_kind']:($table==='stall_application_rejections'?['id']:['application_id']);
        if ($primary!==$expectedPrimary) {
            throw new RuntimeException('Unexpected installed D2 primary key: '.$table);
        }
        if ($table==='stall_application_rejections') {
            $unique=$db->query("SELECT COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stall_application_rejections' AND INDEX_NAME='uq_d2_rejection_event' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
            if ($unique!==[['COLUMN_NAME'=>'event_key','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1]]) {
                throw new RuntimeException('Missing unique D2 rejection event key.');
            }
        }
        $read=$db->prepare('SELECT k.COLUMN_NAME,k.CONSTRAINT_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME');
        $read->execute([$table]);$expected=[];
        foreach($definitions['fks'][$table] as $fk)$expected[]=['COLUMN_NAME'=>$fk['column'],'CONSTRAINT_NAME'=>$fk['name'],'REFERENCED_TABLE_NAME'=>$fk['target'],'REFERENCED_COLUMN_NAME'=>$fk['target_column'],'DELETE_RULE'=>'RESTRICT','UPDATE_RULE'=>'RESTRICT'];
        usort($expected,fn($a,$b)=>strcmp($a['CONSTRAINT_NAME'],$b['CONSTRAINT_NAME']));
        if ($read->fetchAll(PDO::FETCH_ASSOC)!==$expected) {
            throw new RuntimeException('Unexpected installed D2 foreign keys: '.$table);
        }
    }
    if ($apply) {
        foreach($plan as $table=>$sql) {$db->exec($sql);gjc_table_columns($db,$table,true);}
    }
    return $plan;
}

/**
 * Pipeline status vocabulary: review -> meeting -> down_payment -> approval -> active.
 * Maps 1:1 to the 4-step application pipeline (current_step 1-4).
 */
/** Exact legacy output layout with normalized reads and byte-sensitive fallback. */
function gjc_stall_application_projection_metadata(): array
{
    return array (
  'logical_columns' => 
  array (
    0 => 'id',
    1 => 'stall_id',
    2 => 'business_name',
    3 => 'first_name',
    4 => 'middle_name',
    5 => 'last_name',
    6 => 'suffix',
    7 => 'sex',
    8 => 'street',
    9 => 'barangay',
    10 => 'city',
    11 => 'province',
    12 => 'proprietor_name',
    13 => 'contact_number',
    14 => 'email',
    15 => 'profile_picture',
    16 => 'business_permit',
    17 => 'sanitary_permit',
    18 => 'gjc_requirements',
    19 => 'clearance',
    20 => 'terms_accepted',
    21 => 'status',
    22 => 'current_step',
    23 => 'reviewed_by',
    24 => 'reviewed_at',
    25 => 'rejection_reason',
    26 => 'contract_ref',
    27 => 'signed_at',
    28 => 'initially_approved_by',
    29 => 'initially_approved_at',
    30 => 'created_at',
    31 => 'updated_at',
    32 => 'meetup_scheduled_at',
    33 => 'meetup_location',
    34 => 'meetup_notes',
    35 => 'meetup_scheduled_by',
    36 => 'meetup_scheduled_email_sent_at',
    37 => 'down_payment_amount',
    38 => 'down_payment_reference',
    39 => 'down_payment_notes',
    40 => 'down_payment_recorded_by',
    41 => 'down_payment_recorded_at',
    42 => 'merchant_user_id',
    43 => 'temp_password_plain',
    44 => 'preferred_stall_id',
    45 => 'contract_file',
    46 => 'contract_uploaded_at',
    47 => 'contract_uploaded_by',
    48 => 'deposit_amount',
    49 => 'advance_amount',
    50 => 'rental_start_date',
    51 => 'payment_schedule_day',
    52 => 'awarded_by',
    53 => 'awarded_at',
    54 => 'cancelled_by',
    55 => 'cancelled_at',
    56 => 'cancel_reason',
    57 => 'first_viewed_at',
    58 => 'first_viewed_by',
    59 => 'payment_method',
    60 => 'payment_ref_no',
  ),
  'normalized_expressions' => 
  array (
    'first_name' => 'ap.first_name',
    'middle_name' => 'ap.middle_name',
    'last_name' => 'ap.last_name',
    'suffix' => 'ap.suffix',
    'sex' => 'ap.sex',
    'contact_number' => 'ap.contact_number',
    'email' => 'ap.email',
    'meetup_scheduled_at' => 'mt.meetup_scheduled_at',
    'meetup_location' => 'mt.meetup_location',
    'meetup_notes' => 'mt.meetup_notes',
    'meetup_scheduled_by' => 'mt.meetup_scheduled_by',
    'meetup_scheduled_email_sent_at' => 'mt.meetup_scheduled_email_sent_at',
    'down_payment_amount' => 'py.down_payment_amount',
    'down_payment_reference' => 'py.down_payment_reference',
    'down_payment_notes' => 'py.down_payment_notes',
    'down_payment_recorded_by' => 'py.down_payment_recorded_by',
    'down_payment_recorded_at' => 'py.down_payment_recorded_at',
    'deposit_amount' => 'py.deposit_amount',
    'advance_amount' => 'py.advance_amount',
    'rental_start_date' => 'py.rental_start_date',
    'payment_schedule_day' => 'py.payment_schedule_day',
    'payment_method' => 'py.payment_method',
    'payment_ref_no' => 'py.payment_ref_no',
    'contract_file' => 'ct.contract_file',
    'contract_uploaded_at' => 'ct.contract_uploaded_at',
    'contract_uploaded_by' => 'ct.contract_uploaded_by',
    'contract_ref' => 'ct.contract_ref',
    'signed_at' => 'ct.signed_at',
    'reviewed_by' => 'dc.reviewed_by',
    'reviewed_at' => 'dc.reviewed_at',
    'rejection_reason' => 'dc.rejection_reason',
    'initially_approved_by' => 'dc.initially_approved_by',
    'initially_approved_at' => 'dc.initially_approved_at',
    'merchant_user_id' => 'dc.merchant_user_id',
    'awarded_by' => 'dc.awarded_by',
    'awarded_at' => 'dc.awarded_at',
    'cancelled_by' => 'dc.cancelled_by',
    'cancelled_at' => 'dc.cancelled_at',
    'cancel_reason' => 'dc.cancel_reason',
    'first_viewed_at' => 'dc.first_viewed_at',
    'first_viewed_by' => 'dc.first_viewed_by',
    'street' => 'ad.street',
    'barangay' => 'b.label',
    'city' => 'c.label',
    'province' => 'p.label',
    'profile_picture' => 'nd0.file_path',
    'business_permit' => 'nd1.file_path',
    'sanitary_permit' => 'nd2.file_path',
    'gjc_requirements' => 'nd3.file_path',
    'clearance' => 'nd4.file_path',
  ),
  'joins' => 
  array (
    0 => 'LEFT JOIN stall_application_applicants ap ON ap.application_id=sa.id',
    1 => 'LEFT JOIN stall_application_meetings mt ON mt.application_id=sa.id',
    2 => 'LEFT JOIN stall_application_payments py ON py.application_id=sa.id',
    3 => 'LEFT JOIN stall_application_contracts ct ON ct.application_id=sa.id',
    4 => 'LEFT JOIN stall_application_decisions dc ON dc.application_id=sa.id',
    5 => 'LEFT JOIN stall_application_addresses ad ON ad.application_id=sa.id',
    6 => 'LEFT JOIN address_locations b ON b.id=ad.location_id AND b.location_kind=\'barangay\'',
    7 => 'LEFT JOIN address_locations c ON c.id=b.parent_id AND c.location_kind=\'city\'',
    8 => 'LEFT JOIN address_locations p ON p.id=c.parent_id AND p.location_kind=\'province\'',
    9 => 'LEFT JOIN stall_application_documents nd0 ON nd0.application_id=sa.id AND nd0.document_kind=\'profile_picture\'',
    10 => 'LEFT JOIN stall_application_documents nd1 ON nd1.application_id=sa.id AND nd1.document_kind=\'business_permit\'',
    11 => 'LEFT JOIN stall_application_documents nd2 ON nd2.application_id=sa.id AND nd2.document_kind=\'sanitary_permit\'',
    12 => 'LEFT JOIN stall_application_documents nd3 ON nd3.application_id=sa.id AND nd3.document_kind=\'gjc_requirements\'',
    13 => 'LEFT JOIN stall_application_documents nd4 ON nd4.application_id=sa.id AND nd4.document_kind=\'clearance\'',
  ),
  'legacy_fields' => 
  array (
    0 => 'first_name',
    1 => 'middle_name',
    2 => 'last_name',
    3 => 'suffix',
    4 => 'sex',
    5 => 'contact_number',
    6 => 'email',
    7 => 'meetup_scheduled_at',
    8 => 'meetup_location',
    9 => 'meetup_notes',
    10 => 'meetup_scheduled_by',
    11 => 'meetup_scheduled_email_sent_at',
    12 => 'down_payment_amount',
    13 => 'down_payment_reference',
    14 => 'down_payment_notes',
    15 => 'down_payment_recorded_by',
    16 => 'down_payment_recorded_at',
    17 => 'deposit_amount',
    18 => 'advance_amount',
    19 => 'rental_start_date',
    20 => 'payment_schedule_day',
    21 => 'payment_method',
    22 => 'payment_ref_no',
    23 => 'contract_file',
    24 => 'contract_uploaded_at',
    25 => 'contract_uploaded_by',
    26 => 'contract_ref',
    27 => 'signed_at',
    28 => 'reviewed_by',
    29 => 'reviewed_at',
    30 => 'rejection_reason',
    31 => 'initially_approved_by',
    32 => 'initially_approved_at',
    33 => 'merchant_user_id',
    34 => 'awarded_by',
    35 => 'awarded_at',
    36 => 'cancelled_by',
    37 => 'cancelled_at',
    38 => 'cancel_reason',
    39 => 'first_viewed_at',
    40 => 'first_viewed_by',
    41 => 'street',
    42 => 'barangay',
    43 => 'city',
    44 => 'province',
    45 => 'profile_picture',
    46 => 'business_permit',
    47 => 'sanitary_permit',
    48 => 'gjc_requirements',
    49 => 'clearance',
    50 => 'proprietor_name',
  ),
  'string_fields' => 
  array (
    0 => 'stall_id',
    1 => 'business_name',
    2 => 'first_name',
    3 => 'middle_name',
    4 => 'last_name',
    5 => 'suffix',
    6 => 'sex',
    7 => 'street',
    8 => 'barangay',
    9 => 'city',
    10 => 'province',
    11 => 'proprietor_name',
    12 => 'contact_number',
    13 => 'email',
    14 => 'profile_picture',
    15 => 'business_permit',
    16 => 'sanitary_permit',
    17 => 'gjc_requirements',
    18 => 'clearance',
    19 => 'status',
    20 => 'rejection_reason',
    21 => 'contract_ref',
    22 => 'meetup_location',
    23 => 'meetup_notes',
    24 => 'down_payment_reference',
    25 => 'down_payment_notes',
    26 => 'temp_password_plain',
    27 => 'preferred_stall_id',
    28 => 'contract_file',
    29 => 'cancel_reason',
    30 => 'payment_method',
    31 => 'payment_ref_no',
  ),
  'actor_fields' => 
  array (
    0 => 'meetup_scheduled_by',
    1 => 'down_payment_recorded_by',
    2 => 'contract_uploaded_by',
    3 => 'reviewed_by',
    4 => 'initially_approved_by',
    5 => 'merchant_user_id',
    6 => 'awarded_by',
    7 => 'cancelled_by',
    8 => 'first_viewed_by',
  ),
);
}

function gjc_stall_application_has_legacy(PDO $db): bool
{
    $fields=gjc_stall_application_projection_metadata()['legacy_fields'];
    $count=count(array_intersect($fields,gjc_table_columns($db,'stall_applications')));
    if ($count!==0 && $count!==count($fields)) {
        throw new RuntimeException('Application fields are partially contracted.');
    }
    return $count===count($fields);
}

/** Complete legacy-compatible SELECT source; alias is internal and validated. */
function gjc_stall_application_source(PDO $db, string $alias='app'): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i',$alias)) {
        throw new InvalidArgumentException('Invalid application alias.');
    }
    $m=gjc_stall_application_projection_metadata();$legacy=gjc_stall_application_has_legacy($db);
    foreach(gjc_stall_application_detail_definitions()['ddl'] as $table=>$ddl) {
        if(!gjc_table_exists($db,$table))throw new RuntimeException('Application detail schema is missing: '.$table);
    }
    $expressions=[];
    foreach($m['logical_columns'] as $field) {
        if($field==='proprietor_name')continue;
        $normal=$m['normalized_expressions'][$field]??null;
        if($normal===null){$expressions[$field]='sa.'.$field;continue;}
        $value=$normal;
        if($legacy){$left=$normal;$right='sa.'.$field;
            if(in_array($field,$m['string_fields'],true)){$left='BINARY '.$left;$right='BINARY '.$right;}
            $value='CASE WHEN '.$left.' <=> '.$right.' THEN '.$normal.' ELSE sa.'.$field.' END';
        }
        if(in_array($field,$m['actor_fields'],true))$value='CAST(('.$value.') AS SIGNED)';
        $expressions[$field]=$value;
    }
    $names=[];foreach(['first_name','middle_name','last_name','suffix'] as $field)$names[]="NULLIF((".$expressions[$field]."),'')";
    $expressions['proprietor_name']="TRIM(CONCAT_WS(' ',".implode(',',$names).'))';
    $select=[];foreach($m['logical_columns'] as $field)$select[]=$expressions[$field].' AS '.$field;
    $from=' FROM stall_applications sa '.implode(' ',$m['joins']);
    if(!$legacy){$missing=['ap.application_id','mt.application_id','py.application_id','ct.application_id','dc.application_id','ad.application_id','b.id','c.id','p.id'];
        foreach(range(0,4) as $i)$missing[]='nd'.$i.'.application_id';
        if($db->query('SELECT sa.id'.$from.' WHERE '.implode(' OR ',array_map(fn($field)=>$field.' IS NULL',$missing)).' LIMIT 1')->fetchColumn()!==false)throw new RuntimeException('Application details are incomplete.');
    }
    return '(SELECT '.implode(', ',$select).$from.') '.$alias;
}

function gjc_stall_application_fetch(PDO $db, int $id, bool $lock=false): ?array
{
    if($lock){if(!$db->inTransaction())throw new RuntimeException('Application lock requires a transaction.');$stmt=$db->prepare('SELECT id FROM stall_applications WHERE id=? FOR UPDATE');$stmt->execute([$id]);if($stmt->fetchColumn()===false)return null;}
    $source=gjc_stall_application_source($db);
    if($lock){
        // A locking read sees current detail rows even after an earlier caller snapshot.
        $inner=substr($source,1,-strlen(') app'));
        $stmt=$db->prepare($inner.' WHERE sa.id=? FOR SHARE');
    }else{$stmt=$db->prepare('SELECT app.* FROM '.$source.' WHERE app.id=? LIMIT 1');}
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Use a private savepoint when called inside an existing onboarding transaction. */
function gjc_stall_application_write(PDO $db, callable $operation): mixed
{
    $owns=!$db->inTransaction();$savepoint='gjc_app_'.bin2hex(random_bytes(8));
    if($owns)$db->beginTransaction();else $db->exec('SAVEPOINT '.$savepoint);
    try{$result=$operation();if($owns)$db->commit();else $db->exec('RELEASE SAVEPOINT '.$savepoint);return $result;}
    catch(Throwable $e){if($db->inTransaction()){if($owns)$db->rollBack();else{$db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$db->exec('RELEASE SAVEPOINT '.$savepoint);}}throw $e;}
}

/** Submitted names remain historical; only the combined rendering is derived. */
function gjc_stall_application_proprietor(array $state): string
{
    return trim(implode(' ',array_filter([$state['first_name']??'',$state['middle_name']??null,$state['last_name']??'',$state['suffix']??null],fn($part)=>$part!==null&&$part!=='')));
}
/** Synchronize the full logical application in the caller's transaction. */
function gjc_sync_stall_application_details(PDO $db, array $state): void
{
    if(!$db->inTransaction())throw new RuntimeException('Application synchronization requires a transaction.');
    $id=(int)$state['id'];$definitions=gjc_stall_application_detail_definitions();
    foreach($definitions['groups'] as $table=>$fields){$values=[$id];foreach($fields as $field){if(!array_key_exists($field,$state))throw new RuntimeException('Missing application value: '.$field);$values[]=$state[$field];}
        $sql='INSERT INTO '.$table.' (application_id,'.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($values),'?')).') ON DUPLICATE KEY UPDATE '.implode(',',array_map(fn($field)=>$field.'=VALUES('.$field.')',$fields));$db->prepare($sql)->execute($values);
    }
    $parent=null;
    foreach(['province','city','barangay'] as $kind){$label=$state[$kind];if(!is_string($label)||mb_strlen($label,'UTF-8')>100)throw new InvalidArgumentException('Invalid application address label.');
        $find=$db->prepare('SELECT id FROM address_locations WHERE parent_id<=>? AND location_kind=? AND label=? LIMIT 1');$find->execute([$parent,$kind,$label]);$location=$find->fetchColumn();
        if($location===false){$db->prepare('INSERT INTO address_locations(parent_id,location_kind,label) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([$parent,$kind,$label]);$location=$db->lastInsertId();}$parent=(int)$location;
    }
    $db->prepare('INSERT INTO stall_application_addresses(application_id,street,location_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE street=VALUES(street),location_id=VALUES(location_id)')->execute([$id,$state['street'],$parent]);
    $doc=$db->prepare('INSERT INTO stall_application_documents(application_id,document_kind,file_path) VALUES(?,?,?) ON DUPLICATE KEY UPDATE file_path=VALUES(file_path)');
    foreach($definitions['document_kinds'] as $kind)$doc->execute([$id,$kind,$state[$kind]]);
}

const GJC_APPLICATION_DB_NOW='@genpay:database-now';

/** Return false for a missing row or changed guarded state; otherwise write atomically. */
function gjc_stall_application_update(PDO $db, int $id, array $changes, array $conditions=[]): bool
{
    return gjc_stall_application_write($db,function()use($db,$id,$changes,$conditions):bool{
        $before=gjc_stall_application_fetch($db,$id,true);if(!$before)return false;
        foreach($conditions as $field=>$expected)if(!array_key_exists($field,$before)||$before[$field]!==$expected)return false;
        $allowed=gjc_stall_application_projection_metadata()['logical_columns'];$now=null;
        foreach($changes as $field=>&$value){if(!in_array($field,$allowed,true)||in_array($field,['id','created_at','updated_at','proprietor_name'],true))throw new InvalidArgumentException('Invalid application update field: '.$field);
            if($value===GJC_APPLICATION_DB_NOW && ($field==='signed_at'||str_ends_with($field,'_at')))$value=$now??=$db->query('SELECT NOW()')->fetchColumn();
        }unset($value);
        $state=array_replace($before,$changes);$state['proprietor_name']=gjc_stall_application_proprietor($state);
        if(mb_strlen($state['proprietor_name'],'UTF-8')>120)throw new InvalidArgumentException('Applicant name exceeds 120 characters.');
        $legacy=gjc_stall_application_has_legacy($db);$physical=gjc_table_columns($db,'stall_applications');
        $write=$changes;if($legacy)$write['proprietor_name']=$state['proprietor_name'];$write=array_intersect_key($write,array_flip($physical));
        if($write){$assign=implode(',',array_map(fn($field)=>$field.'=?',array_keys($write)));$db->prepare('UPDATE stall_applications SET '.$assign.' WHERE id=?')->execute(array_merge(array_values($write),[$id]));}
        if($legacy){$stmt=$db->prepare('SELECT * FROM stall_applications WHERE id=?');$stmt->execute([$id]);$state=$stmt->fetch(PDO::FETCH_ASSOC);}
        gjc_sync_stall_application_details($db,$state);
        if(!$legacy){$after=gjc_stall_application_fetch($db,$id);$changed=false;foreach($changes as $field=>$value)if($after[$field]!==$before[$field]){$changed=true;break;}
            if($changed)$db->prepare('UPDATE stall_applications SET updated_at=NOW() WHERE id=?')->execute([$id]);
        }
        if(($changes['status']??null)==='rejected'&&$before['status']!=='rejected'){
            $after=gjc_stall_application_fetch($db,$id);
            $db->prepare('INSERT INTO stall_application_rejections(application_id,event_key,rejected_at_step,rejection_reason,rejected_by,rejected_at,event_source) VALUES(?,?,?,?,?,?,?)')->execute([$id,bin2hex(random_bytes(32)),(int)$after['current_step'],$after['rejection_reason'],$after['reviewed_by'],$after['reviewed_at'],'workflow']);
        }
        return true;
    });
}
function gjc_stall_application_defaults(): array
{
    return array (
  'stall_id' => NULL,
  'business_name' => '',
  'first_name' => '',
  'middle_name' => NULL,
  'last_name' => '',
  'suffix' => NULL,
  'sex' => 'male',
  'street' => '',
  'barangay' => '',
  'city' => '',
  'province' => '',
  'proprietor_name' => '',
  'contact_number' => '',
  'email' => '',
  'profile_picture' => '',
  'business_permit' => '',
  'sanitary_permit' => '',
  'gjc_requirements' => '',
  'clearance' => '',
  'terms_accepted' => 0,
  'status' => 'pending_verification',
  'current_step' => 1,
  'reviewed_by' => NULL,
  'reviewed_at' => NULL,
  'rejection_reason' => NULL,
  'contract_ref' => NULL,
  'signed_at' => NULL,
  'initially_approved_by' => NULL,
  'initially_approved_at' => NULL,
  'meetup_scheduled_at' => NULL,
  'meetup_location' => NULL,
  'meetup_notes' => NULL,
  'meetup_scheduled_by' => NULL,
  'meetup_scheduled_email_sent_at' => NULL,
  'down_payment_amount' => NULL,
  'down_payment_reference' => NULL,
  'down_payment_notes' => NULL,
  'down_payment_recorded_by' => NULL,
  'down_payment_recorded_at' => NULL,
  'merchant_user_id' => NULL,
  'temp_password_plain' => NULL,
  'preferred_stall_id' => NULL,
  'contract_file' => NULL,
  'contract_uploaded_at' => NULL,
  'contract_uploaded_by' => NULL,
  'deposit_amount' => NULL,
  'advance_amount' => NULL,
  'rental_start_date' => NULL,
  'payment_schedule_day' => NULL,
  'awarded_by' => NULL,
  'awarded_at' => NULL,
  'cancelled_by' => NULL,
  'cancelled_at' => NULL,
  'cancel_reason' => NULL,
  'first_viewed_at' => NULL,
  'first_viewed_by' => NULL,
  'payment_method' => NULL,
  'payment_ref_no' => NULL,
);
}

function gjc_stall_application_create(PDO $db, array $values): int
{
    return gjc_stall_application_write($db,function()use($db,$values):int{
        $state=array_replace(gjc_stall_application_defaults(),$values);$now=null;
        foreach($state as $field=>&$value)if($value===GJC_APPLICATION_DB_NOW&&($field==='signed_at'||str_ends_with($field,'_at')))$value=$now??=$db->query('SELECT NOW()')->fetchColumn();unset($value);
        $state['proprietor_name']=gjc_stall_application_proprietor($state);
        if(mb_strlen($state['proprietor_name'],'UTF-8')>120)throw new InvalidArgumentException('Applicant name exceeds 120 characters.');
        unset($state['id'],$state['created_at'],$state['updated_at']);
        $physical=gjc_table_columns($db,'stall_applications');$write=array_intersect_key($state,array_flip($physical));
        $db->prepare('INSERT INTO stall_applications ('.implode(',',array_keys($write)).') VALUES ('.implode(',',array_fill(0,count($write),'?')).')')->execute(array_values($write));
        $id=(int)$db->lastInsertId();$stmt=$db->prepare('SELECT * FROM stall_applications WHERE id=?');$stmt->execute([$id]);$raw=$stmt->fetch(PDO::FETCH_ASSOC);
        $state=array_replace($state,$raw);gjc_sync_stall_application_details($db,$state);return $id;
    });
}

/** Reuse one rejected application; hold the scheduler lock until its transaction commits. */
function gjc_reactivate_stall_application(PDO $db, int $eventId, int $actor): ?array
{
    if($db->inTransaction())throw new RuntimeException('Application reactivation must own its transaction.');
    $location=gjc_meeting_default_location($db);
    $lock=$db->query("SELECT GET_LOCK('gjc_stall_scheduler',10)")->fetchColumn();if((int)$lock!==1)throw new RuntimeException('Meeting scheduler is busy.');
    try{return gjc_stall_application_write($db,function()use($db,$eventId,$actor,$location):?array{
        $lookup=$db->prepare('SELECT application_id FROM stall_application_rejections WHERE id=?');$lookup->execute([$eventId]);$applicationId=$lookup->fetchColumn();if($applicationId===false)return null;
        $app=gjc_stall_application_fetch($db,(int)$applicationId,true);
        $read=$db->prepare('SELECT * FROM stall_application_rejections WHERE id=? FOR UPDATE');$read->execute([$eventId]);$event=$read->fetch(PDO::FETCH_ASSOC);
        if(!$app||$app['status']!=='rejected'||!$event||$event['reactivated_at']!==null)return null;
        $latest=$db->prepare('SELECT id FROM stall_application_rejections WHERE application_id=? ORDER BY rejected_at DESC,id DESC LIMIT 1');$latest->execute([$applicationId]);if((int)$latest->fetchColumn()!==$eventId)return null;
        $slot=gjc_find_next_meeting_slot($db);if($slot===null)throw new RuntimeException('No verification meeting slot is available.');
        $date=$slot['date'].' '.$slot['time'].':00';
        if(!gjc_stall_application_update($db,(int)$applicationId,['status'=>'pending_verification','current_step'=>1,'reviewed_by'=>null,'reviewed_at'=>null,'rejection_reason'=>null,'first_viewed_at'=>null,'first_viewed_by'=>null,'meetup_scheduled_at'=>$date,'meetup_location'=>$location,'meetup_scheduled_by'=>$actor,'meetup_scheduled_email_sent_at'=>null],['status'=>'rejected']))return null;
        $db->prepare('UPDATE stall_application_rejections SET reactivated_at=NOW(),reactivated_by=? WHERE id=?')->execute([$actor,$eventId]);
        $current=gjc_stall_application_fetch($db,(int)$applicationId);
        $current['_reactivation_before']=['id'=>$app['id'],'status'=>'rejected',
            'meetup_scheduled_at'=>$app['meetup_scheduled_at'],'meetup_location'=>$app['meetup_location']];
        return $current;
    });}finally{$db->query("SELECT RELEASE_LOCK('gjc_stall_scheduler')")->fetchColumn();}
}

function gjc_ensure_stall_application_workflow_schema(PDO $db): void
{
    if(!gjc_table_exists($db,'stall_applications'))return;
    gjc_stall_application_has_legacy($db);
    foreach(gjc_stall_application_detail_definitions()['ddl'] as $table=>$ddl)if(!gjc_table_exists($db,$table))throw new RuntimeException('Application details require the approved EXPAND.');
    $status=$db->query("SHOW COLUMNS FROM stall_applications LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if(!$status||$status['Type']!=="enum('pending_verification','awarded','rejected','cancelled','expired')")throw new RuntimeException('Unexpected application workflow vocabulary.');
}

/**
 * Lazy expiry (there is no background cron in this app, so every touch point
 * re-checks — see also gjc_account_suspension()). A
 * pending_verification application whose one-stop meeting time has already
 * passed with no award/reject decision means the applicant never showed up;
 * flip it to 'expired' so it stops blocking that email/name/business from a
 * fresh re-application (see apply.php's duplicate check) and stops occupying
 * the meeting slot.
 *
 * Cutoff is the *calendar date*, not the exact time-of-day: a meeting slot
 * only marks its hour, and finance may still be mid-walk-in (uploading the
 * contract, recording payment) well after that hour on the same day. Waiting
 * until the day is over avoids yanking an application out from under an
 * admin who is actively awarding/rejecting it.
 *
 * $triggeredBy is whoever's page load caught it (0 from the public apply
 * form, which has no logged-in admin) — logAudit() already no-ops for a
 * non-positive user id, so passing 0 just means the transition happens
 * without an audit entry in that case.
 */
function gjc_expire_overdue_stall_applications(PDO $db, int $triggeredBy = 0): int
{
    if (!gjc_table_exists($db, "stall_applications")) {
        return 0;
    }

    $overdue = $db
        ->query(
            "SELECT id, proprietor_name, business_name, email, meetup_scheduled_at
               FROM " . gjc_stall_application_source($db) . "
              WHERE status = 'pending_verification'
                AND meetup_scheduled_at IS NOT NULL
                AND DATE(meetup_scheduled_at) < CURDATE()",
        )
        ->fetchAll(PDO::FETCH_ASSOC);

    if (!$overdue) {
        return 0;
    }

    $expiredCount = 0;
    foreach ($overdue as $app) {
        $meetingAt = new DateTime($app["meetup_scheduled_at"]);
        $reason = "Applicant did not attend the scheduled verification meeting on "
            . $meetingAt->format("M j, Y g:i A") . ".";

        $changed=gjc_stall_application_update($db,(int)$app['id'],['status'=>'expired','reviewed_by'=>$triggeredBy>0?$triggeredBy:null,'reviewed_at'=>GJC_APPLICATION_DB_NOW,'rejection_reason'=>$reason],['status'=>'pending_verification','meetup_scheduled_at'=>$app['meetup_scheduled_at']]);
        if (!$changed) {
            continue; // already expired by a concurrent request
        }
        $expiredCount++;

        logAudit(
            $db,
            $triggeredBy,
            gjc_current_role(),
            "STALL_UPDATE",
            "stall_applications",
            ["id" => $app["id"], "status" => "pending_verification", "meetup_scheduled_at" => $app["meetup_scheduled_at"]],
            ["id" => $app["id"], "status" => "expired", "event" => "auto_expired_no_show"],
            null,
            ['target_id' => $app['id'], 'reason' => $reason]
        );

        gjc_send_stall_application_termination_email(
            $app,
            "Application Expired",
            "You did not attend your scheduled verification meeting, so your stall application has expired.",
            $reason,
        );
    }

    return $expiredCount;
}

/**
 * Per-field validation rules for apply.php's 5 upload tiles — the single
 * source of truth shared by apply.php's final form submit and
 * apply_upload.php's eager per-file AJAX upload, so both enforce identical
 * whitelists.
 */
function gjc_stall_application_upload_rules(): array
{
    $docMimes = ["image/jpeg", "image/png", "application/pdf"];
    $docExt   = ["jpg", "jpeg", "png", "pdf"];
    $imgMimes = ["image/jpeg", "image/png"];
    $imgExt   = ["jpg", "jpeg", "png"];

    return [
        "profile_picture"  => ["label" => "Profile Picture",  "mimes" => $imgMimes, "exts" => $imgExt],
        "business_permit"  => ["label" => "Business Permit",  "mimes" => $docMimes, "exts" => $docExt],
        "sanitary_permit"  => ["label" => "Sanitary Permit",  "mimes" => $docMimes, "exts" => $docExt],
        "gjc_requirements" => ["label" => "GJC Requirements", "mimes" => $docMimes, "exts" => $docExt],
        "clearance"        => ["label" => "Clearance",        "mimes" => $docMimes, "exts" => $docExt],
    ];
}

/**
 * A not-yet-submitted stall application has no id to key an upload folder on,
 * so each visitor gets a random per-session token (not the raw PHP session
 * id, to avoid tying a filesystem name to the session identity) the first
 * time they attach a file. Every file attached before the final submit lands
 * under this same folder, so it survives a page refresh or a validation
 * error on some other field — apply.php renames the whole folder to the
 * real `uploads/stall_applications/<id>/` once the row is actually inserted.
 */
function gjc_stall_application_draft_token(): string
{
    if (empty($_SESSION["apply_draft_token"])) {
        $_SESSION["apply_draft_token"] = "draft_" . bin2hex(random_bytes(8));
    }
    return $_SESSION["apply_draft_token"];
}

function gjc_stall_application_draft_dir(): string
{
    return BASE_PATH . "/uploads/stall_applications/" . gjc_stall_application_draft_token();
}

/** Files already attached in this session's draft, keyed by field name. */
function gjc_stall_application_draft_files(): array
{
    return $_SESSION["apply_draft_files"] ?? [];
}

/**
 * Validate one uploaded file against $rule and, if valid, move it into this
 * session's draft folder immediately (replacing whatever was previously
 * staged for the same field) and record it in the session. Used by both
 * apply.php's full-form submit and apply_upload.php's eager per-file upload.
 *
 * @return array{success:bool,message?:string,name?:string}
 */
function gjc_stage_stall_application_upload(string $field, ?array $file, array $rule): array
{
    if (!$file || ($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file["tmp_name"])) {
        return ["success" => false, "message" => $rule["label"] . " is required."];
    }
    if ($file["error"] !== UPLOAD_ERR_OK) {
        return ["success" => false, "message" => $rule["label"] . " upload error (code " . $file["error"] . ")."];
    }
    if ($file["size"] > 5 * 1024 * 1024) { // house limit — 5MB, same cap used everywhere else in this app
        return ["success" => false, "message" => $rule["label"] . " exceeds the 5 MB limit."];
    }
    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    if (!in_array($ext, $rule["exts"], true)) {
        return ["success" => false, "message" => $rule["label"] . " must be " . implode(", ", array_map("strtoupper", $rule["exts"])) . "."];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
    if (!in_array($mime, $rule["mimes"], true)) {
        return ["success" => false, "message" => $rule["label"] . " has an invalid file type."];
    }

    $draftDir = gjc_stall_application_draft_dir();
    if (!is_dir($draftDir) && !mkdir($draftDir, 0755, true)) {
        return ["success" => false, "message" => "Could not save " . $rule["label"] . ". Please try again."];
    }
    $fname = $field . "_" . time() . mt_rand(1000, 9999) . "." . $ext;
    if (!move_uploaded_file($file["tmp_name"], $draftDir . "/" . $fname)) {
        return ["success" => false, "message" => "Could not save " . $rule["label"] . ". Please try again."];
    }

    $staged = gjc_stall_application_draft_files();
    if (isset($staged[$field]["fname"])) {
        @unlink($draftDir . "/" . $staged[$field]["fname"]);
    }
    $staged[$field] = ["fname" => $fname, "orig" => $file["name"]];
    $_SESSION["apply_draft_files"] = $staged;

    return ["success" => true, "name" => $file["name"]];
}

/**
 * Discards whatever is currently staged for this visitor. Called when the
 * browser tells us this page load is a fresh arrival at apply.php — not a
 * refresh of the form the visitor was already filling in — so documents
 * attached on an earlier, abandoned visit don't silently reappear (and don't
 * get silently reused for fields the visitor doesn't re-attach this time).
 */
function gjc_clear_stall_application_draft(): void
{
    if (!empty($_SESSION["apply_draft_token"])) {
        $dir = BASE_PATH . "/uploads/stall_applications/" . $_SESSION["apply_draft_token"];
        if (is_dir($dir)) {
            array_map("unlink", glob($dir . "/*") ?: []);
            @rmdir($dir);
        }
    }
    unset($_SESSION["apply_draft_files"], $_SESSION["apply_draft_token"]);
}

/**
 * Opportunistic cleanup for draft folders whose visitor never finished
 * submitting (closed the tab, session expired) — there is no cron in this
 * app, so apply.php sweeps this on every load, same lazy-check philosophy as
 * gjc_expire_overdue_stall_applications(). A draft older than 48 hours is
 * treated as abandoned.
 */
function gjc_cleanup_stale_stall_application_drafts(): void
{
    $base = BASE_PATH . "/uploads/stall_applications";
    if (!is_dir($base)) {
        return;
    }
    $cutoff = time() - 48 * 3600;
    foreach (glob($base . "/draft_*", GLOB_ONLYDIR) ?: [] as $dir) {
        if (filemtime($dir) < $cutoff) {
            array_map("unlink", glob($dir . "/*") ?: []);
            @rmdir($dir);
        }
    }
}

/**
 * Archive table for applications declined during Step 1 (Review) or
 * Step 2 (Meeting). Kept separate from the live pipeline so finance staff
 * can reference or reactivate a declined application later.
 */
function gjc_ensure_archived_rejections_schema(PDO $db): void
{
    if (!gjc_table_exists($db,'stall_application_rejections')) {
        throw new RuntimeException('Normalized rejection history is missing.');
    }
}

/**
 * Optional job title for merchant staff accounts (e.g. "Cashier", "Cook").
 * Purely descriptive — has no effect on permissions, which stay keyed off
 * roleID/sub_role.
 *
 * Also widens middle_name/suffix to nullable: those columns predate this
 * feature as NOT NULL DEFAULT '', which rejects the NULL the staff form now
 * sends when an admin leaves them blank.
 */
function gjc_ensure_staff_position_schema(PDO $db): void
{
    if (!gjc_table_exists($db, "users")) {
        return;
    }

    $columns = gjc_table_columns($db, "users");
    if (!in_array("position", $columns, true)) {
        $db->exec("ALTER TABLE users ADD COLUMN position VARCHAR(60) NULL DEFAULT NULL AFTER roleID");
    }
    // "Remove" on merchant/staff.php is a soft delete: the audit trail keeps a
    // foreign key to users.userID, so a staff login that ever signed in cannot
    // be hard-deleted. A removed row is set Inactive (which blocks sign-in) and
    // stamped here, and every roster query filters it out.
    if (!in_array("staff_removed_at", $columns, true)) {
        $db->exec("ALTER TABLE users ADD COLUMN staff_removed_at DATETIME NULL DEFAULT NULL AFTER position");
    }

    $stmt = $db->prepare(
        "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
           AND COLUMN_NAME IN ('middle_name', 'suffix') AND IS_NULLABLE = 'NO'",
    );
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            $db->exec("ALTER TABLE users MODIFY {$row['COLUMN_NAME']} {$row['COLUMN_TYPE']} NULL DEFAULT NULL");
        } catch (Throwable $ignored) {
        }
    }
}

function gjc_ensure_first_login_schema(PDO $db): void
{
    if (!gjc_table_exists($db, "users")) {
        return;
    }

    $columns = gjc_table_columns($db, "users");
    $adds = [
        "is_first_login" => "TINYINT(1) NOT NULL DEFAULT 0",
        "password_changed" => "TINYINT(1) NOT NULL DEFAULT 1",
        "temp_password" => "VARCHAR(100) NULL",
    ];

    foreach ($adds as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
        }
    }
}

/**
 * Supplies normalized address fields while retaining safe compatibility reads.
 * New accounts without an address read as blank after legacy columns are removed.
 */
function gjc_with_user_address(PDO $db, array $row, int $userId): array
{
    if (!$row || !gjc_table_exists($db, 'user_addresses') || !gjc_table_exists($db, 'address_locations')) {
        return $row;
    }
    $fields = ['address_barangay', 'address_city', 'address_province'];
    $legacy = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $row)) {
            $legacy[$field] = $row[$field];
        }
    }
    $normalized = gjc_user_address_values($db, $userId);
    if (count($legacy) === 3 && ($normalized === null || $normalized !== $legacy)) {
        return $row;
    }
    return array_replace($row, $normalized ?? array_fill_keys($fields, null));
}

function gjc_current_user(PDO $db): array
{
    $id = gjc_user_id();
    if (!$id || !gjc_table_exists($db, "users")) {
        return [
            "id" => 0,
            "name" => "Guest",
            "email" => "",
            "role" => gjc_current_role(),
            "roleID" => (int) ($_SESSION["roleID"] ?? 0),
        ];
    }

    $idColumn = gjc_column($db, "users", ["id", "userID"]);
    if (!$idColumn) {
        return [
            "id" => $id,
            "name" => "User",
            "email" => "",
            "role" => gjc_current_role(),
            "roleID" => 0,
        ];
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE {$idColumn} = ? LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($user) {
        $user = gjc_with_user_address($db, $user, (int) ($user[$idColumn] ?? $id));
    }

    $name = trim((string) ($user["name"] ?? ""));
    if ($name === "") {
        $name = trim(
            (string) (($user["first_name"] ?? "") .
                " " .
                ($user["last_name"] ?? "")),
        );
    }
    if ($name === "") {
        $name = $user["email"] ?? "User";
    }

    $role = gjc_current_role();
    if (!empty($user["roleID"])) {
        $role = gjc_role_name($user["roleID"]);
    }

    return [
        "id" => (int) ($user[$idColumn] ?? $id),
        "name" => $name,
        "email" => $user["email"] ?? "",
        "role" => $role,
        "roleID" => (int) ($user["roleID"] ?? ($_SESSION["roleID"] ?? 0)),
        "raw" => $user,
    ];
}

function gjc_user_label(PDO $db, int $userId): string
{
    if (!$userId || !gjc_table_exists($db, "users")) {
        return "Unknown User";
    }

    $idColumn = gjc_column($db, "users", ["id", "userID"]);
    if (!$idColumn) {
        return "User #" . $userId;
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE {$idColumn} = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $name = trim((string) ($user["name"] ?? ""));
    if ($name === "") {
        $name = trim(
            (string) (($user["first_name"] ?? "") .
                " " .
                ($user["last_name"] ?? "")),
        );
    }
    if ($name === "") {
        $name = $user["email"] ?? "User #" . $userId;
    }
    return $name;
}

// ── App settings: finance-editable policy knobs ────────────────────────────
// Deliberately NOT the `system_settings` table — that one is the economy
// singleton (circulation cap + cashier vault), guarded by CHECK constraints and
// DB triggers, and writable only by CirculationEngine. These are plain policy
// numbers finance can retune without a deploy.

/**
 * Canonical keys + fallbacks. A key absent from the DB reads as its default.
 * The GJC_VIOLATION_* constants stay the single source of truth for their own
 * defaults — the settings row only overrides them at runtime.
 */
function gjc_app_setting_defaults(): array
{
    return [
        // Transaction limits (panel 2)
        'transfer_daily_limit'     => 5000.00,
        'transfer_min_amount'      => 1.00,
        'topup_max_per_request'    => 5000.00,
        'wallet_default_spend_cap' => 0.00, // 0 = no default cap on new wallets

        // Merchant restricted-product policy (panel 3) — whole numbers
        'violation_warn_at'      => (float) GJC_VIOLATION_WARN_AT,
        'violation_risk_at'      => (float) GJC_VIOLATION_RISK_AT,
        'violation_suspend_days' => (float) GJC_VIOLATION_SUSPEND_DAYS,

        // Fee waiver credits (panel 4)
        'fee_waiver_max_amount' => 50000.00,

        // Maintenance fee (panel 5) — a percentage of each student POS payment /
        // P2P transfer, billed to tuition (0.1 = 0.1%, not 10%). 0 switches it off.
        'maintenance_fee_rate_pct' => 0.10,
    ];
}

function gjc_ensure_app_settings_schema(PDO $db): void
{
    if (gjc_table_exists($db, 'app_settings')) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('App settings schema must precede a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key   VARCHAR(64)   NOT NULL,
            setting_value DECIMAL(15,2) NOT NULL,
            updated_by    INT           NULL,
            updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );
}

/**
 * All settings, defaults merged under whatever the DB overrides. Cached per
 * request. If the table is unreachable the defaults still come back, so a
 * schema problem loosens nothing — enforcement keeps running on the built-ins.
 */
function gjc_settings_all(PDO $db, bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }

    $cache = gjc_app_setting_defaults();
    try {
        gjc_ensure_app_settings_schema($db);
        $rows = $db->query("SELECT setting_key, setting_value FROM app_settings")
                   ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            if (array_key_exists($key, $cache)) {
                $cache[$key] = (float) $row['setting_value'];
            }
        }
    } catch (Throwable) {
        // Defaults already loaded above.
    }

    return $cache;
}

function gjc_setting(PDO $db, string $key): float
{
    return (float) (gjc_settings_all($db)[$key] ?? gjc_app_setting_defaults()[$key] ?? 0.0);
}

/** Whole-number settings (strike counts, day spans) stored in the same column. */
function gjc_setting_int(PDO $db, string $key): int
{
    return (int) round(gjc_setting($db, $key));
}

function gjc_setting_set(PDO $db, string $key, float $value, int $userId): void
{
    if (!array_key_exists($key, gjc_app_setting_defaults())) {
        throw new RuntimeException("Unknown setting key: {$key}");
    }
    gjc_ensure_app_settings_schema($db);
    $db->prepare(
        "INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by    = VALUES(updated_by)"
    )->execute([$key, $value, $userId]);

    gjc_settings_all($db, true); // bust the per-request cache
}

// ── Remember me: persistent login via single-use rotating tokens ───────────
// Cookie 'gjc_remember' = "<selector>:<validator>"; only a SHA-256 hash of the
// validator is stored, so a leaked table can't forge cookies. Tokens rotate on
// every use and are deleted on logout.

function gjc_ensure_remember_token_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS auth_remember_tokens (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     INT NOT NULL,
            selector    CHAR(24) NOT NULL,
            token_hash  CHAR(64) NOT NULL,
            expires_at  DATETIME NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_art_selector (selector),
            KEY idx_art_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );
}

/**
 * The granular role for a users.roleID. roleID is the single source of truth;
 * there is no stored sub_role (dropped by gjc_ensure_role_contract_schema).
 */
function gjc_sub_role_for_role_id(int $roleId): string
{
    return gjc_sub_role_map()[$roleId] ?? 'student';
}

/** roleID => sub_role. The one place this mapping is defined. */
function gjc_sub_role_map(): array
{
    return [
        1 => 'student',
        2 => 'merchant_admin',
        3 => 'super_admin',
        4 => 'super_admin',
        5 => 'merchant_admin',
        6 => 'merchant_staff',
        7 => 'parent',
    ];
}

/** SQL expression deriving sub_role from a roleID column, for SELECT lists. */
function gjc_sub_role_sql(string $roleIdColumn = 'roleID'): string
{
    $cases = '';
    foreach (gjc_sub_role_map() as $id => $name) {
        $cases .= " WHEN {$id} THEN '{$name}'";
    }
    return "(CASE {$roleIdColumn}{$cases} ELSE 'student' END)";
}

/** Populate the login session vars for a users row (shared by password login and remember-me). */
function gjc_establish_login_session(array $user): void
{
    $userId = (int) ($user['id'] ?? $user['userID'] ?? 0);
    $roleId = (int) ($user['roleID'] ?? 0);

    $_SESSION['userID'] = $userId;
    $_SESSION['user_id'] = $userId;
    $_SESSION['roleID'] = $roleId;
    $_SESSION['password_fingerprint'] = hash('sha256', (string) ($user['password'] ?? ''));

    // roleID is the single source of truth; sub_role is derived (3NF).
    $_SESSION['sub_role'] = gjc_sub_role_for_role_id($roleId);
    $_SESSION['merchant_owner_id'] = (int) ($user['merchant_owner_id'] ?? 0);
    $_SESSION['role'] = [1 => 'student', 2 => 'merchant', 3 => 'finance', 4 => 'finance', 5 => 'merchant', 6 => 'merchant', 7 => 'parent'][$roleId] ?? 'user';
}

function gjc_issue_remember_token(PDO $db, int $userId, int $days = 30): void
{
    gjc_ensure_remember_token_schema($db);
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $db->prepare(
        "INSERT INTO auth_remember_tokens (user_id, selector, token_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
    )->execute([$userId, $selector, hash('sha256', $validator), $days]);

    setcookie('gjc_remember', $selector . ':' . $validator, [
        'expires'  => time() + $days * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Delete the presented token (if any) and expire the cookie. Safe to call anytime. */
function gjc_clear_remember_token(PDO $db): void
{
    $raw = (string) ($_COOKIE['gjc_remember'] ?? '');
    if ($raw === '') {
        return;
    }
    if (strpos($raw, ':') !== false) {
        [$selector] = explode(':', $raw, 2);
        if (preg_match('/^[a-f0-9]{24}$/', $selector) && gjc_table_exists($db, 'auth_remember_tokens')) {
            $db->prepare("DELETE FROM auth_remember_tokens WHERE selector = ?")->execute([$selector]);
        }
    }
    setcookie('gjc_remember', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE['gjc_remember']);
}

/**
 * Silently restore a login from the remember-me cookie when no session exists.
 * Invalid/expired/foreign tokens are discarded. Deactivated accounts and
 * accounts still on a forced password change never auto-login. The used token
 * is rotated (deleted + reissued) so a stolen cookie only works once.
 */
function gjc_attempt_remember_login(PDO $db): void
{
    if (gjc_user_id() > 0) {
        return;
    }
    $raw = (string) ($_COOKIE['gjc_remember'] ?? '');
    if ($raw === '' || strpos($raw, ':') === false) {
        return;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        gjc_clear_remember_token($db);
        return;
    }

    gjc_ensure_remember_token_schema($db);
    $stmt = $db->prepare("SELECT * FROM auth_remember_tokens WHERE selector = ? LIMIT 1");
    $stmt->execute([$selector]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$token
        || $token['expires_at'] <= date('Y-m-d H:i:s')
        || !hash_equals($token['token_hash'], hash('sha256', $validator))) {
        gjc_clear_remember_token($db);
        return;
    }

    $idCol = gjc_column($db, 'users', ['id', 'userID']) ?: 'userID';
    $userStmt = $db->prepare("SELECT * FROM users WHERE {$idCol} = ? LIMIT 1");
    $userStmt->execute([(int) $token['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    $mustChange = $user && (
        !empty($user['force_password_change'])
        || !empty($user['is_first_login'])
        || (isset($user['password_changed']) && (int) $user['password_changed'] === 0)
    );
    if (!$user || (!empty($user['status']) && $user['status'] === 'Inactive') || $mustChange) {
        gjc_clear_remember_token($db);
        return;
    }

    // A suspended account must not be auto-logged-in either. Without this the
    // cookie and the gjc_require_role() lockout fight each other: the cookie
    // rebuilds the session on login.php, login.php forwards to the dashboard,
    // the role check tears the session down again — a redirect loop. Reading it
    // through gjc_account_suspension() also means a suspension that has already
    // run out lifts itself here and the login goes through as normal.
    if (gjc_account_suspension($db, (int) $token['user_id']) !== null) {
        gjc_clear_remember_token($db);
        return;
    }

    $db->prepare("DELETE FROM auth_remember_tokens WHERE id = ?")->execute([(int) $token['id']]);
    gjc_establish_login_session($user);
    session_regenerate_id(true);
    gjc_issue_remember_token($db, (int) $token['user_id']);
}

/** Reject sessions authenticated before a credential change, including older sessions. */
function gjc_password_session_valid(array $user): bool
{
    $fingerprint = (string) ($_SESSION['password_fingerprint'] ?? '');
    if ($fingerprint !== '') {
        return hash_equals($fingerprint, hash('sha256', (string) ($user['password'] ?? '')));
    }
    // Older sessions cannot prove which password authenticated them. Requiring
    // a fresh login prevents them becoming valid again after a student changes
    // their temporary password and clears the forced-change flags.
    return false;
}

function gjc_require_role(array $roles): void
{
    // A returning visitor with a valid remember-me cookie gets their session
    // restored here, before the role check bounces them to the login page.
    global $db;
    if ($db instanceof PDO) {
        gjc_attempt_remember_login($db);
    }

    $role = gjc_current_role();
    if (!in_array($role, $roles, true)) {
        // Record only a SIGNED-IN user being turned away from another role's
        // area — that is someone with a real session reaching for something
        // they may not have, and it left no trace anywhere before. An
        // anonymous visitor bounced to the login page is just a redirect and
        // would bury the real signal under noise from every logged-out hit.
        $deniedUserId = gjc_user_id();
        if ($deniedUserId > 0 && $db instanceof PDO && function_exists('logAudit')) {
            logAudit(
                $db,
                $deniedUserId,
                $role,
                'ACCESS_DENIED',
                'users',
                null,
                [
                    'actual_role'    => $role,
                    'required_roles' => $roles,
                ],
                null,
                [
                    'target_id' => $deniedUserId,
                    'outcome'   => 'denied',
                    'reason'    => 'Role ' . $role . ' cannot open a page restricted to: ' . implode(', ', $roles) . '.',
                ]
            );
        }
        header("Location: " . BASE_URL . "/login.php");
        exit();
    }

    // If the account was deactivated after this session was created, force logout immediately.
    $userId = gjc_user_id();
    if ($userId) {
        global $db;
        if ($db instanceof PDO && gjc_table_exists($db, 'users')) {
            if (in_array('status', gjc_table_columns($db, 'users'), true)) {
                $idCol = gjc_column($db, 'users', ['id', 'userID']);
                if ($idCol) {
                    $chk = $db->prepare("SELECT * FROM users WHERE {$idCol} = ? LIMIT 1");
                    $chk->execute([$userId]);
                    $sessionUser = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$sessionUser || !gjc_password_session_valid($sessionUser)) {
                        gjc_clear_remember_token($db);
                        session_unset();
                        session_destroy();
                        header("Location: " . BASE_URL . "/login.php?reason=password_reset");
                        exit();
                    }
                    $_SESSION['password_fingerprint'] = hash('sha256', (string) $sessionUser['password']);
                    if ($sessionUser['status'] === 'Inactive') {
                        session_unset();
                        session_destroy();
                        header("Location: " . BASE_URL . "/login.php?reason=deactivated");
                        exit();
                    }
                }
            }

            // A stall auto-suspended over restricted-product strikes locks out
            // the owner and their staff for the duration — see
            // gjc_record_product_violation().
            if ($role === 'merchant') {
                $suspendedUntil = gjc_merchant_suspended_until($db, $userId);
                if ($suspendedUntil !== null) {
                    session_unset();
                    session_destroy();
                    header("Location: " . BASE_URL . "/login.php?reason=suspended&until=" . urlencode($suspendedUntil));
                    exit();
                }
            }

            // A finance-issued suspension (admin/users.php) applies to every
            // role, and merchant staff go down with their owner's stall. Checked
            // on every page load, not just at login, so suspending someone who
            // is already signed in ends their session on their next click.
            $account = gjc_account_suspension($db, $userId, $role === 'merchant');
            if ($account !== null) {
                session_unset();
                session_destroy();
                $reason = ($account['kind'] ?? 'suspension') === 'ban' ? 'account_banned' : 'account_suspended';
                header("Location: " . BASE_URL . "/login.php?reason={$reason}"
                    . ($account['until'] !== null ? "&until=" . urlencode((string) $account['until']) : ""));
                exit();
            }
        }
    }

    $script = str_replace("\\", "/", $_SERVER["SCRIPT_NAME"] ?? "");
    if (!empty($_SESSION["force_change"]) && !str_ends_with($script, "/change_password.php")) {
        header("Location: " . BASE_URL . "/change_password.php");
        exit();
    }
}

/** Nobody on file is older than this — an upper bound for date-of-birth sanity. */
const GJC_MAX_AGE = 120;

/**
 * Idempotent self-healer for the personal-details block on an account: sex,
 * date of birth, and address. Lives on `users` rather than
 * `student_info` so every role carries the same fields — a parent or a merchant
 * owner has an address exactly like a student does.
 *
 * Every column is NULLable with no default: these are optional details a user
 * fills in over time, and an account created before this ran is simply blank
 * rather than wrong. Age is deliberately NOT a column — see gjc_age_from_dob().
 */
function gjc_ensure_user_profile_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        return;
    }

    gjc_ensure_user_address_schema($db);
    $cols = gjc_table_columns($db, 'users');
    $altered = false;
    $userFields = array_intersect_key(gjc_user_profile_columns(), array_flip(['sex', 'date_of_birth']));
    foreach ($userFields as $col => $def) {
        if (!in_array($col, $cols, true)) {
            try {
                $db->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
                $altered = true;
            } catch (\Throwable $ignored) {}
        }
    }
    if ($altered) {
        gjc_table_columns($db, 'users', true);
    }
}

/** Explicit staff-owner integrity change, read-only unless separately approved. */
function gjc_ensure_staff_owner_integrity_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Staff owner integrity requires CLI, ewallet and no transaction.');
    }
    if (!gjc_table_exists($db, 'users')) {
        throw new RuntimeException('Staff owner integrity requires users.');
    }
    $id = $db->query("SHOW COLUMNS FROM users LIKE 'userID'")->fetch(PDO::FETCH_ASSOC);
    $owner = $db->query("SHOW FULL COLUMNS FROM users LIKE 'merchant_owner_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$id || strtolower($id['Type']) !== 'int' || !$owner
        || !in_array(strtolower($owner['Type']), ['int', 'int unsigned'], true)
        || $owner['Null'] !== 'YES' || $owner['Default'] !== null || $owner['Extra'] !== '') {
        throw new RuntimeException('Unexpected staff owner column definitions.');
    }
    $keys = $db->query("SELECT k.COLUMN_NAME,k.CONSTRAINT_NAME,k.REFERENCED_TABLE_NAME,
        k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
        ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME
        AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='users' AND k.COLUMN_NAME='merchant_owner_id'
        ORDER BY k.CONSTRAINT_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $expected = [['COLUMN_NAME'=>'merchant_owner_id','CONSTRAINT_NAME'=>'fk_users_merchant_owner',
        'REFERENCED_TABLE_NAME'=>'users','REFERENCED_COLUMN_NAME'=>'userID',
        'DELETE_RULE'=>'RESTRICT','UPDATE_RULE'=>'RESTRICT']];
    $indexes = $db->query("SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'
        AND (COLUMN_NAME='merchant_owner_id' OR INDEX_NAME='idx_users_merchant_owner')
        ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $expectedIndex = [['INDEX_NAME'=>'idx_users_merchant_owner','COLUMN_NAME'=>'merchant_owner_id',
        'NON_UNIQUE'=>1,'SEQ_IN_INDEX'=>1]];
    if ($keys === $expected && strtolower($owner['Type']) === 'int' && $indexes === $expectedIndex) {
        return [];
    }
    if ($keys || $indexes) {
        throw new RuntimeException('Unexpected existing staff owner key or index.');
    }
    $nameUsed = (int) $db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_users_merchant_owner'")->fetchColumn();
    $incoming = (int) $db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users'
        AND REFERENCED_COLUMN_NAME='merchant_owner_id'")->fetchColumn();
    if ($nameUsed || $incoming) {
        throw new RuntimeException('Staff owner constraint name or incoming dependency conflict.');
    }
    $invalid = (int) $db->query('SELECT COUNT(*) FROM users s LEFT JOIN users o ON o.userID=s.merchant_owner_id
        WHERE s.merchant_owner_id IS NOT NULL AND (o.userID IS NULL OR s.merchant_owner_id>2147483647
            OR s.merchant_owner_id<=0 OR s.merchant_owner_id=s.userID OR s.roleID<>6
            OR o.roleID NOT IN (2,5) OR o.merchant_owner_id IS NOT NULL)')->fetchColumn();
    if ($invalid) {
        throw new RuntimeException('Unexpected owner data requires a separate review.');
    }
    $comment = $db->quote((string) $owner['Comment']);
    $plan = ['users'=>'ALTER TABLE users MODIFY COLUMN merchant_owner_id INT NULL DEFAULT NULL COMMENT '.$comment
        . ', ADD INDEX idx_users_merchant_owner (merchant_owner_id), ADD CONSTRAINT fk_users_merchant_owner'
        . ' FOREIGN KEY (merchant_owner_id) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT'];
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('An exact immediate private users export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath),true,512,JSON_THROW_ON_ERROR);
    if (($archive['schema_users'] ?? null) !== $db->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_NUM)[1]
        || ($archive['users'] ?? null) !== $db->query('SELECT * FROM users ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Private export differs from current users.');
    }
    $db->exec($plan['users']);
    gjc_table_columns($db,'users',true);
    return $plan;
}

/** Explicit violations CONTRACT; never called by normal page requests. */
function gjc_ensure_group_d_user_violation_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Violation CONTRACT requires CLI, ewallet, and no transaction.');
    }
    foreach (['users', 'user_violations'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Required violation table is missing: ' . $table);
        }
    }
    $fields = gjc_violation_fields();
    if (array_diff(array_merge(['user_id'], $fields), gjc_table_columns($db, 'user_violations', true))) {
        throw new RuntimeException('Normalized violation schema is incomplete.');
    }
    foreach ([['users', 'userID'], ['user_violations', 'user_id']] as [$table, $column]) {
        $type = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch(PDO::FETCH_ASSOC);
        if (!$type || strtolower($type['Type']) !== 'int') {
            throw new RuntimeException('Violation identifiers require signed INT.');
        }
    }
    $relations = $db->query("SELECT k.COLUMN_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME,
        k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'user_violations'
          AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);
    if ($relations !== [['COLUMN_NAME' => 'user_id', 'CONSTRAINT_NAME' => 'fk_user_violation_user',
        'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'userID',
        'DELETE_RULE' => 'CASCADE', 'UPDATE_RULE' => 'RESTRICT']]) {
        throw new RuntimeException('Normalized violation foreign key differs from approved schema.');
    }
    $primary = $db->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_violations' AND INDEX_NAME='PRIMARY'
        ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
    if ($primary !== ['user_id']) {
        throw new RuntimeException('Violation primary key differs from approved schema.');
    }
    $present = array_intersect($fields, gjc_table_columns($db, 'users', true));
    if (!$present) {
        return [];
    }
    if (count($present) !== 3) {
        throw new RuntimeException('Violation legacy columns are partially contracted.');
    }
    $rows = $db->query('SELECT userID, restricted_violation_count, restricted_violation_last_at,
        restricted_suspended_until FROM users ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC);
    $normalized = $db->query('SELECT u.userID, COALESCE(v.restricted_violation_count, 0) AS restricted_violation_count,
        v.restricted_violation_last_at, v.restricted_suspended_until FROM users u
        LEFT JOIN user_violations v ON v.user_id=u.userID ORDER BY u.userID')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows !== $normalized) {
        throw new RuntimeException('Normalized violation facts differ from legacy.');
    }
    $names = "'restricted_violation_count','restricted_violation_last_at','restricted_suspended_until'";
    $keys = $db->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='users' AND COLUMN_NAME IN ({$names}))
        OR (REFERENCED_TABLE_NAME='users' AND REFERENCED_COLUMN_NAME IN ({$names})))")->fetchAll(PDO::FETCH_COLUMN);
    $indexes = $db->query("SELECT INDEX_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME IN ({$names})")->fetchAll(PDO::FETCH_COLUMN);
    if ($keys || $indexes) {
        throw new RuntimeException('Unexpected legacy violation key or index dependency.');
    }
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT EVENT_NAME AS name, EVENT_DEFINITION AS body FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name, GENERATION_EXPRESSION AS body
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND GENERATION_EXPRESSION <> ''")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME) AS name, EXPRESSION AS body
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                AND EXPRESSION IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if ($object['body'] === null
            || preg_match('/\\b(restricted_violation_count|restricted_violation_last_at|restricted_suspended_until)\\b/i', $object['body'])) {
            throw new RuntimeException('Cannot contract violation-dependent object: ' . $object['name']);
        }
    }
    $plan = ['users' => 'ALTER TABLE users DROP COLUMN restricted_violation_count, DROP COLUMN restricted_violation_last_at, DROP COLUMN restricted_suspended_until'];
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A matching private violation export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    $schema = $db->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_NUM)[1];
    $normalizedSchema = $db->query('SHOW CREATE TABLE user_violations')->fetch(PDO::FETCH_NUM)[1];
    $allUsers = $db->query('SELECT * FROM users ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC);
    $normalizedRows = $db->query('SELECT * FROM user_violations ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    if (($archive['schema_users'] ?? null) !== $schema || ($archive['states'] ?? null) !== $rows
        || ($archive['schema_user_violations'] ?? null) !== $normalizedSchema
        || ($archive['users'] ?? null) !== $allUsers || ($archive['normalized_rows'] ?? null) !== $normalizedRows) {
        throw new RuntimeException('Private violation export differs from current source.');
    }
    $db->exec($plan['users']);
    gjc_table_columns($db, 'users', true);
    return $plan;
}

/** Explicit suspension CONTRACT; read-only by default and requires an exact private export. */
function gjc_ensure_group_d_user_suspension_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('User suspension CONTRACT requires CLI, ewallet, and no transaction.');
    }
    foreach (['users', 'user_suspensions'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Required suspension table is missing: ' . $table);
        }
    }
    $fields = ['suspended_until', 'suspension_reason', 'suspended_at', 'suspended_by', 'suspension_prev_status', 'suspension_froze_wallet', 'lockout_kind'];
    $normalColumns = gjc_table_columns($db, 'user_suspensions', true);
    if (array_diff(array_merge(['user_id'], $fields), $normalColumns)) {
        throw new RuntimeException('Normalized suspension schema is incomplete.');
    }
    foreach ([['users', 'userID'], ['user_suspensions', 'user_id'], ['user_suspensions', 'suspended_by']] as [$table, $column]) {
        $type = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch(PDO::FETCH_ASSOC);
        if (!$type || strtolower($type['Type']) !== 'int') {
            throw new RuntimeException('Suspension foreign keys require signed INT identifiers.');
        }
    }
    $relations = $db->query("SELECT k.COLUMN_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME,
        k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'user_suspensions'
          AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $expectedRelations = [
        ['COLUMN_NAME' => 'suspended_by', 'CONSTRAINT_NAME' => 'fk_user_suspension_actor',
            'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'userID',
            'DELETE_RULE' => 'RESTRICT', 'UPDATE_RULE' => 'RESTRICT'],
        ['COLUMN_NAME' => 'user_id', 'CONSTRAINT_NAME' => 'fk_user_suspension_user',
            'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'userID',
            'DELETE_RULE' => 'CASCADE', 'UPDATE_RULE' => 'RESTRICT'],
    ];
    if ($relations !== $expectedRelations) {
        throw new RuntimeException('Normalized suspension foreign keys differ from the approved schema.');
    }

    $present = array_intersect($fields, gjc_table_columns($db, 'users', true));
    if (!$present) {
        return [];
    }
    if (count($present) !== 7) {
        throw new RuntimeException('User suspension columns are partially contracted.');
    }
    $rows = $db->query('SELECT userID, status, suspended_until, suspension_reason, suspended_at,
        suspended_by, suspension_prev_status, suspension_froze_wallet, lockout_kind
        FROM users ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC);
    $normalized = $db->query('SELECT u.userID, u.status, s.suspended_until, s.suspension_reason, s.suspended_at,
        s.suspended_by, s.suspension_prev_status, COALESCE(s.suspension_froze_wallet, 0) AS suspension_froze_wallet,
        s.lockout_kind FROM users u LEFT JOIN user_suspensions s ON s.user_id = u.userID ORDER BY u.userID')
        ->fetchAll(PDO::FETCH_ASSOC);
    if ($rows !== $normalized) {
        throw new RuntimeException('Normalized suspension facts differ from the legacy projection.');
    }
    $names = "'suspended_until','suspension_reason','suspended_at','suspended_by','suspension_prev_status','suspension_froze_wallet','lockout_kind'";
    $keys = $db->query("SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME,
        k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
            AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND ((k.TABLE_NAME = 'users' AND k.COLUMN_NAME IN ({$names}))
          OR (k.REFERENCED_TABLE_NAME = 'users' AND k.REFERENCED_COLUMN_NAME IN ({$names})))")
        ->fetchAll(PDO::FETCH_ASSOC);
    $expectedKey = [['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'suspended_by',
        'CONSTRAINT_NAME' => 'fk_core_users_suspended_by', 'REFERENCED_TABLE_NAME' => 'users',
        'REFERENCED_COLUMN_NAME' => 'userID', 'DELETE_RULE' => 'RESTRICT', 'UPDATE_RULE' => 'RESTRICT']];
    if ($keys !== $expectedKey) {
        throw new RuntimeException('Unexpected legacy suspension key dependency.');
    }
    $indexes = $db->query("SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
        AND (COLUMN_NAME IN ({$names}) OR INDEX_NAME = 'fk_core_users_suspended_by')
        ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $expectedIndex = [['INDEX_NAME' => 'fk_core_users_suspended_by', 'COLUMN_NAME' => 'suspended_by',
        'NON_UNIQUE' => 1, 'SEQ_IN_INDEX' => 1]];
    if ($indexes !== $expectedIndex) {
        throw new RuntimeException('Unexpected legacy suspension index dependency.');
    }
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT EVENT_NAME AS name, EVENT_DEFINITION AS body FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name, GENERATION_EXPRESSION AS body
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND GENERATION_EXPRESSION <> ''")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME) AS name, EXPRESSION AS body
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                AND EXPRESSION IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if ($object['body'] === null
            || preg_match('/\\b(suspended_until|suspension_reason|suspended_at|suspended_by|suspension_prev_status|suspension_froze_wallet|lockout_kind)\\b/i', $object['body'])) {
            throw new RuntimeException('Cannot contract suspension-dependent object: ' . $object['name']);
        }
    }
    $plan = ['users' => 'ALTER TABLE users DROP FOREIGN KEY fk_core_users_suspended_by, DROP INDEX fk_core_users_suspended_by, DROP COLUMN suspended_until, DROP COLUMN suspension_reason, DROP COLUMN suspended_at, DROP COLUMN suspended_by, DROP COLUMN suspension_prev_status, DROP COLUMN suspension_froze_wallet, DROP COLUMN lockout_kind'];
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A matching private suspension export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    $schema = $db->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_NUM)[1];
    $normalizedSchema = $db->query('SHOW CREATE TABLE user_suspensions')->fetch(PDO::FETCH_NUM)[1];
    $normalizedRows = $db->query('SELECT * FROM user_suspensions ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    if (($archive['schema_users'] ?? null) !== $schema || ($archive['states'] ?? null) !== $rows
        || ($archive['schema_user_suspensions'] ?? null) !== $normalizedSchema
        || ($archive['normalized_rows'] ?? null) !== $normalizedRows) {
        throw new RuntimeException('Private suspension export differs from current source.');
    }
    $db->exec($plan['users']);
    gjc_table_columns($db, 'users', true);
    return $plan;
}

/**
 * Explicit user-address CONTRACT. Read-only unless applying with an exact private export.
 */
function gjc_ensure_group_d_user_address_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('User address CONTRACT requires CLI, ewallet, and no transaction.');
    }
    foreach (['users', 'user_addresses', 'address_locations'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Required address table is missing: ' . $table);
        }
    }
    $fields = ['address_barangay', 'address_city', 'address_province'];
    $present = array_intersect($fields, gjc_table_columns($db, 'users', true));
    if (!$present) {
        return [];
    }
    if (count($present) !== 3) {
        throw new RuntimeException('User address columns are partially contracted.');
    }
    $rows = $db->query('SELECT userID, address_barangay, address_city, address_province FROM users ORDER BY userID')
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $userId = (int) $row['userID'];
        unset($row['userID']);
        if (gjc_user_address_values($db, $userId) !== $row) {
            throw new RuntimeException('Normalized address is missing or differs from the original.');
        }
    }
    $names = "'address_barangay','address_city','address_province'";
    $keys = $db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'users' AND COLUMN_NAME IN ({$names}))
          OR (REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME IN ({$names})))")->fetchColumn();
    $indexes = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ({$names})")->fetchColumn();
    if ((int) $keys !== 0 || (int) $indexes !== 0) {
        throw new RuntimeException('A key or index depends on legacy address columns.');
    }
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT EVENT_NAME AS name, EVENT_DEFINITION AS body FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name, GENERATION_EXPRESSION AS body
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND GENERATION_EXPRESSION <> ''")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME) AS name, EXPRESSION AS body
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                AND EXPRESSION IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if ($object['body'] === null
            || preg_match('/\\b(address_barangay|address_city|address_province)\\b/i', $object['body'])) {
            throw new RuntimeException('Cannot contract address-dependent object: ' . $object['name']);
        }
    }
    $plan = ['users' => 'ALTER TABLE users DROP COLUMN address_barangay, DROP COLUMN address_city, DROP COLUMN address_province'];
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A matching private address export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    $schema = $db->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_NUM)[1];
    if (($archive['schema'] ?? null) !== $schema || ($archive['addresses'] ?? null) !== $rows) {
        throw new RuntimeException('Private address export differs from current source.');
    }
    $db->exec($plan['users']);
    gjc_table_columns($db, 'users', true);
    return $plan;
}

/**
 * Commits the existing profile UPDATE and normalized address as one operation.
 * No schema DDL runs here; callers retain their current validation and audit behavior.
 */
function gjc_save_profile_with_address(PDO $db, int $userId, string $sql, array $params, array $address): void
{
    if ($db->inTransaction()) {
        throw new LogicException('Profile address writes must own their transaction.');
    }
    if (!gjc_table_exists($db, 'address_locations') || !gjc_table_exists($db, 'user_addresses')) {
        throw new RuntimeException('Profile could not be saved. Please try again.');
    }
    $addressKeys = ['address_barangay', 'address_city', 'address_province'];
    $columns = gjc_table_columns($db, 'users', true);
    $legacyCount = count(array_intersect($addressKeys, $columns));
    if ($legacyCount !== 0 && $legacyCount !== 3) {
        throw new LogicException('Legacy address columns are only partially present.');
    }
    foreach ($addressKeys as $key) {
        if (!array_key_exists($key, $address)) {
            throw new InvalidArgumentException('All address fields must be supplied.');
        }
    }
    if (!$params || (int) $params[array_key_last($params)] !== $userId) {
        throw new InvalidArgumentException('Profile UPDATE must target the locked user.');
    }
    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT userID FROM users WHERE userID = ? FOR UPDATE');
        $lock->execute([$userId]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Profile could not be saved. Please try again.');
        }
        $db->prepare($sql)->execute($params);
        if ($legacyCount === 3) {
            $read = $db->prepare('SELECT address_barangay, address_city, address_province FROM users WHERE userID = ?');
            $read->execute([$userId]);
            $address = $read->fetch(PDO::FETCH_ASSOC);
            if (!$address) {
                throw new RuntimeException('Profile could not be saved. Please try again.');
            }
        }
        gjc_sync_user_address($db, $userId, $address);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Normalized optional user addresses. Existing profile columns remain during EXPAND/MIGRATE.
 * Schema creation must precede application transactions.
 */
function gjc_ensure_user_address_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        throw new RuntimeException('User address schema requires users.');
    }
    $type = $db->query("SHOW COLUMNS FROM users LIKE 'userID'")->fetch(PDO::FETCH_ASSOC);
    if (!$type || strtolower($type['Type']) !== 'int') {
        throw new RuntimeException('User address FK requires signed INT users.userID.');
    }
    $locationsExist = gjc_table_exists($db, 'address_locations');
    $addressesExist = gjc_table_exists($db, 'user_addresses');
    if ($locationsExist && $addressesExist) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('User address schema creation must precede a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS address_locations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id INT UNSIGNED NULL,
    location_kind ENUM('province', 'city', 'barangay') NOT NULL,
    label VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_address_location_scope (location_kind, (COALESCE(parent_id, 0)), label),
    KEY idx_address_location_parent (parent_id),
    CONSTRAINT fk_address_location_parent FOREIGN KEY (parent_id)
        REFERENCES address_locations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_address_province_root CHECK (location_kind <> 'province' OR parent_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_addresses (
    user_id INT NOT NULL,
    location_id INT UNSIGNED NULL,
    PRIMARY KEY (user_id),
    KEY idx_user_address_location (location_id),
    CONSTRAINT fk_user_address_user FOREIGN KEY (user_id)
        REFERENCES users(userID) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_user_address_location FOREIGN KEY (location_id)
        REFERENCES address_locations(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    gjc_table_columns($db, 'address_locations', true);
    gjc_table_columns($db, 'user_addresses', true);
}

/** Writes only normalized address tables; the caller owns the transaction and compatibility write. */
function gjc_sync_user_address(PDO $db, int $userId, array $values): void
{
    if (!$db->inTransaction()) {
        throw new RuntimeException('Address synchronization requires a transaction.');
    }
    foreach (['province', 'city', 'barangay'] as $kind) {
        $key = 'address_' . $kind;
        if (!array_key_exists($key, $values)) {
            throw new InvalidArgumentException('All three address fields must be supplied.');
        }
        if ($values[$key] !== null
            && (!is_string($values[$key]) || mb_strlen($values[$key], 'UTF-8') > 120)) {
            throw new InvalidArgumentException('Address labels must be strings of at most 120 characters.');
        }
    }
    $parentId = null;
    foreach (['province', 'city', 'barangay'] as $kind) {
        $label = $values['address_' . $kind];
        if ($label === null) {
            continue;
        }
        $find = $db->prepare('SELECT id FROM address_locations
            WHERE parent_id <=> ? AND location_kind = ? AND label = ? LIMIT 1');
        $find->execute([$parentId, $kind, $label]);
        $id = $find->fetchColumn();
        if ($id === false) {
            $db->prepare('INSERT INTO address_locations (parent_id, location_kind, label)
                VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
                ->execute([$parentId, $kind, $label]);
            $id = $db->lastInsertId();
        }
        $parentId = (int) $id;
    }
    $db->prepare('INSERT INTO user_addresses (user_id, location_id) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE location_id = ?')->execute([$userId, $parentId, $parentId]);
}

/** Returns NULL for an unmigrated user, or the exact original address field layout. */
function gjc_user_address_values(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare('SELECT location_id FROM user_addresses WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $values = ['address_barangay' => null, 'address_city' => null, 'address_province' => null];
    $id = $row['location_id'];
    $previousRank = 3;
    $ranks = ['province' => 0, 'city' => 1, 'barangay' => 2];
    $lookup = $db->prepare('SELECT parent_id, location_kind, label FROM address_locations WHERE id = ?');
    while ($id !== null) {
        $lookup->execute([(int) $id]);
        $node = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$node || !isset($ranks[$node['location_kind']])
            || $ranks[$node['location_kind']] >= $previousRank) {
            throw new RuntimeException('Address hierarchy is missing or invalid.');
        }
        $previousRank = $ranks[$node['location_kind']];
        $values['address_' . $node['location_kind']] = $node['label'];
        $id = $node['parent_id'];
    }
    return $values;
}

/**
 * The personal-details columns and their definitions, in the order they are
 * shown. One source of truth for the self-healer, the edit forms, and the
 * read-only views, so a field can never exist in the schema but go missing from
 * a form (or the reverse).
 *
 * The address is deliberately coarse — barangay, municipality, province, e.g.
 * "San Fernando Sur, Cabiao, Nueva Ecija". A canteen wallet has no use for a
 * house number, and collecting one only creates personal data to look after.
 */
function gjc_user_profile_columns(): array
{
    return [
        'sex'                        => "ENUM('Male', 'Female', 'Prefer not to say') NULL DEFAULT NULL",
        'date_of_birth'              => 'DATE NULL',
        'address_barangay'           => 'VARCHAR(120) NULL',
        'address_city'               => 'VARCHAR(120) NULL',
        'address_province'           => 'VARCHAR(120) NULL',
    ];
}

/** The values users.sex accepts. Matches the ENUM above. */
function gjc_sex_options(): array
{
    return ['Male', 'Female', 'Prefer not to say'];
}

/**
 * Age in whole years from a stored date of birth, or null when there isn't one.
 * Age is computed on every read and never stored: a number in a column is
 * correct for at most a year, and would eventually contradict the birth date
 * sitting next to it.
 */
function gjc_age_from_dob(?string $dob): ?int
{
    $dob = trim((string) $dob);
    if ($dob === '' || $dob === '0000-00-00') {
        return null;
    }
    try {
        $born = new DateTimeImmutable($dob);
    } catch (\Throwable $ignored) {
        return null;
    }
    $now = new DateTimeImmutable('today');
    if ($born > $now) {
        return null;
    }
    return (int) $born->diff($now)->y;
}

/** "Mar 04, 2007", or an em dash when no birth date is on file. */
function gjc_dob_label(?string $dob): string
{
    if (gjc_age_from_dob($dob) === null) {
        return '—';
    }
    return date('M d, Y', strtotime((string) $dob));
}

/**
 * "19 years old", or an em dash when no birth date is on file. Age is shown as
 * a field in its own right, but it is still only ever computed from
 * date_of_birth — there is no age column to fall out of step with it.
 */
function gjc_age_label(?string $dob): string
{
    $age = gjc_age_from_dob($dob);
    return $age === null ? '—' : $age . ' years old';
}

/**
 * The address as one line — "San Fernando Sur, Cabiao, Nueva Ecija" — skipping
 * the parts that were left blank so a half-filled address doesn't render as a
 * row of stray commas. Returns '' when nothing at all is on file, which callers
 * show as an em dash.
 */
function gjc_format_address(array $row): string
{
    $parts = array_filter([
        trim((string) ($row['address_barangay'] ?? '')),
        trim((string) ($row['address_city'] ?? '')),
        trim((string) ($row['address_province'] ?? '')),
    ], static fn(string $part): bool => $part !== '');

    return implode(', ', $parts);
}

/**
 * Reads the personal-details fields out of a submitted form, validates them,
 * and returns ['values' => [column => value], 'error' => ?string]. Shared by
 * every profile edit page so one rule set covers them all — a birth date that
 * the student page rejects can't be smuggled in through the parent page.
 *
 * A field left blank is stored as NULL rather than '', so clearing a value
 * actually clears it and the read-side "is anything on file?" checks stay
 * honest. Sex and date of birth follow the available users columns. Address
 * fields remain logical profile inputs even after their legacy columns are removed.
 */
function gjc_collect_profile_details(array $post, array $columns): array
{
    $blank = static function (string $key) use ($post): ?string {
        $value = trim((string) ($post[$key] ?? ''));
        return $value === '' ? null : $value;
    };

    $sex = $blank('sex');
    if ($sex !== null && !in_array($sex, gjc_sex_options(), true)) {
        return ['values' => [], 'error' => 'Pick one of the listed options for Sex.'];
    }

    $dob = $blank('date_of_birth');
    if ($dob !== null) {
        $ts = strtotime($dob);
        if ($ts === false) {
            return ['values' => [], 'error' => 'Enter your date of birth as a valid date.'];
        }
        $dob = date('Y-m-d', $ts);
        if ($ts > strtotime('today')) {
            return ['values' => [], 'error' => 'Date of birth cannot be in the future.'];
        }
        if (gjc_age_from_dob($dob) > GJC_MAX_AGE) {
            return ['values' => [], 'error' => 'Check the date of birth — that works out to over ' . GJC_MAX_AGE . ' years old.'];
        }
    }

    $values = [
        'sex'                        => $sex,
        'date_of_birth'              => $dob,
        'address_barangay'           => $blank('address_barangay'),
        'address_city'               => $blank('address_city'),
        'address_province'           => $blank('address_province'),
    ];

    $profileFields = array_unique(array_merge($columns, [
        'address_barangay', 'address_city', 'address_province',
    ]));
    return [
        'values' => array_intersect_key($values, array_flip($profileFields)),
        'error'  => null,
    ];
}

function gjc_ensure_operational_tables(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS topup_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_wallet_id INT UNSIGNED NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            payment_method VARCHAR(80) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reference_no VARCHAR(40) NULL UNIQUE,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            rejected_by INT NULL,
            rejected_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_topup_wallet (student_wallet_id),
            INDEX idx_topup_status (status)
        ) ENGINE=InnoDB",
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS encashment_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            merchant_wallet_id INT UNSIGNED NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            method VARCHAR(80) NOT NULL DEFAULT 'Cashier Release',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reference_no VARCHAR(40) NULL UNIQUE,
            released_by INT NULL,
            released_at DATETIME NULL,
            rejected_by INT NULL,
            rejected_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_encash_wallet (merchant_wallet_id),
            INDEX idx_encash_status (status)
        ) ENGINE=InnoDB",
    );

    $topupAdds = [
        "student_wallet_id" => "INT UNSIGNED NULL",
        "payment_method" => "VARCHAR(80) NOT NULL DEFAULT 'Cash at Cashier'",
        "reference_no" => "VARCHAR(40) NULL",
        "approved_by" => "INT NULL",
        "approved_at" => "DATETIME NULL",
        "rejected_by" => "INT NULL",
        "rejected_at" => "DATETIME NULL",
        "created_at" => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($topupAdds as $column => $definition) {
        if (
            !in_array($column, gjc_table_columns($db, "topup_requests"), true)
        ) {
            $db->exec(
                "ALTER TABLE topup_requests ADD COLUMN {$column} {$definition}",
            );
        }
    }

    $encashAdds = [
        "merchant_wallet_id" => "INT UNSIGNED NULL",
        "method" => "VARCHAR(80) NOT NULL DEFAULT 'Cashier Release'",
        "reference_no" => "VARCHAR(40) NULL",
        "released_by" => "INT NULL",
        "released_at" => "DATETIME NULL",
        "rejected_by" => "INT NULL",
        "rejected_at" => "DATETIME NULL",
        "created_at" => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($encashAdds as $column => $definition) {
        if (
            !in_array(
                $column,
                gjc_table_columns($db, "encashment_requests"),
                true,
            )
        ) {
            $db->exec(
                "ALTER TABLE encashment_requests ADD COLUMN {$column} {$definition}",
            );
        }
    }

    // Student cash-out (withdrawal) requests — mirrors encashment_requests
    // but keyed on the student wallet. Balance is deducted on release, not
    // at request time (same behaviour as merchant encashment).
    $db->exec(
        "CREATE TABLE IF NOT EXISTS withdrawal_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_wallet_id INT UNSIGNED NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            method VARCHAR(80) NOT NULL DEFAULT 'Cashier Release',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reference_no VARCHAR(40) NULL UNIQUE,
            released_by INT NULL,
            released_at DATETIME NULL,
            rejected_by INT NULL,
            rejected_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_withdraw_wallet (student_wallet_id),
            INDEX idx_withdraw_status (status)
        ) ENGINE=InnoDB",
    );

    $withdrawAdds = [
        "student_wallet_id" => "INT UNSIGNED NULL",
        "method" => "VARCHAR(80) NOT NULL DEFAULT 'Cashier Release'",
        "reference_no" => "VARCHAR(40) NULL",
        "released_by" => "INT NULL",
        "released_at" => "DATETIME NULL",
        "rejected_by" => "INT NULL",
        "rejected_at" => "DATETIME NULL",
        "created_at" => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($withdrawAdds as $column => $definition) {
        if (
            !in_array(
                $column,
                gjc_table_columns($db, "withdrawal_requests"),
                true,
            )
        ) {
            $db->exec(
                "ALTER TABLE withdrawal_requests ADD COLUMN {$column} {$definition}",
            );
        }
    }

    gjc_ensure_fee_schema($db);
}

function gjc_ensure_fee_schema(PDO $db): void
{
    // Fee columns on transactions. 3NF: this is the only place top-up fees are
    // stored (base/credited always equalled amount and were dropped; the old
    // topup_requests copies and fee_revenue_log were dropped too).
    foreach ([
        'top_up_source  VARCHAR(20) NULL',
        'fee_amount     DECIMAL(15,2) NULL',
        // 3NF: the merchant's share of fee_amount; school fee = fee_amount - merchant_fee.
        'merchant_fee    DECIMAL(15,2) NULL AFTER fee_amount',
    ] as $col) {
        try { $db->exec("ALTER TABLE transactions ADD COLUMN $col"); }
        catch (\PDOException $e) { /* already exists */ }
    }
    // Backfill the split from fee_revenue_log (the only other place it was kept).
    if (gjc_table_exists($db, 'fee_revenue_log')) {
        $db->exec(
            "UPDATE transactions t JOIN fee_revenue_log f ON f.transaction_ref = t.reference_no
                SET t.merchant_fee = f.merchant_fee
              WHERE t.merchant_fee IS NULL AND t.fee_amount IS NOT NULL"
        );
    }
}

function gjc_generate_student_id(PDO $db): string
{
    // Tied to the active school year (e.g. "2026-2027" -> "2026"), not just
    // today's real-world calendar year, so IDs stay meaningful across a
    // year rollover. Falls back to today's year if no school year is active.
    $activeYearName = gjc_active_school_year_name($db);
    $year = $activeYearName !== null ? substr($activeYearName, 0, 4) : date('Y');

    $stmt = $db->query(
        "SELECT MAX(CAST(SUBSTRING_INDEX(studentID, '-', -1) AS UNSIGNED))
           FROM student_info
          WHERE studentID REGEXP '^GJC[0-9]{4}-[0-9]+$'"
    );
    $max = (int) $stmt->fetchColumn();
    return sprintf('GJC%s-%04d', $year, $max + 1);
}

/**
 * 3NF EXPAND/MIGRATE for year level. The year level a student holds in a given
 * school year lives on student_enrollments (it depends on student + year).
 * student_info only keeps entry_yr_lvl: the year level the student entered at,
 * written once at registration/import and never updated (exactly how
 * student_info.yr_lvl has always behaved). Display = active-year enrollment
 * yr_lvl, falling back to entry_yr_lvl.
 *
 * Legacy student_info.yr_lvl (where still present) is dual-written but never
 * read; the CLI contract (gjc_ensure_student_entry_year_contract_schema) drops it.
 * DDL: never call from inside a transaction.
 */
function gjc_ensure_student_entry_year_schema(PDO $db): void
{
    static $ensured = false;
    if ($ensured || $db->inTransaction() || !gjc_table_exists($db, 'student_info')) {
        return;
    }
    $ensured = true;

    $cols = gjc_table_columns($db, 'student_info');
    if (!in_array('entry_yr_lvl', $cols, true)) {
        $after = in_array('yr_lvl', $cols, true) ? ' AFTER yr_lvl' : '';
        $db->exec("ALTER TABLE student_info ADD COLUMN entry_yr_lvl VARCHAR(11) NULL{$after}");
        gjc_table_columns($db, 'student_info', true);
        $cols = gjc_table_columns($db, 'student_info');
    }
    if (in_array('yr_lvl', $cols, true)) {
        $db->exec("UPDATE student_info SET entry_yr_lvl = yr_lvl WHERE entry_yr_lvl IS NULL");
    }
}

/**
 * Columns + placeholders for writing a student's entry year level, covering
 * both the EXPAND shape (legacy yr_lvl still present, dual-written) and the
 * CONTRACTED shape (entry_yr_lvl only). Returns [columnSql, placeholderSql, count].
 */
function gjc_student_entry_year_write_columns(PDO $db): array
{
    $cols = gjc_table_columns($db, 'student_info');
    $names = array_values(array_intersect(['yr_lvl', 'entry_yr_lvl'], $cols));
    if (!$names) {
        throw new RuntimeException('student_info has no year level column.');
    }
    return [implode(', ', $names), implode(', ', array_fill(0, count($names), '?')), count($names)];
}

/**
 * Refuses a column CONTRACT while any key, index, trigger, view, routine,
 * event, generated column, CHECK constraint or expression index still names
 * $table.$column. Shared by the 3NF contracts below.
 */
function gjc_assert_column_droppable(PDO $db, string $table, string $column, bool $checkKeys = true): void
{
    if ($checkKeys) {
        gjc_assert_column_unkeyed($db, $table, $column);
    }
    gjc_assert_column_unreferenced($db, $column);
}

function gjc_assert_column_unkeyed(PDO $db, string $table, string $column): void
{
    $keys = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = ? AND COLUMN_NAME = ?)
          OR (REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?))");
    $keys->execute([$table, $column, $table, $column]);
    $indexes = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $indexes->execute([$table, $column]);
    if ((int) $keys->fetchColumn() !== 0 || (int) $indexes->fetchColumn() !== 0) {
        throw new RuntimeException("A key or index depends on {$table}.{$column}.");
    }
}

/** Refuses while any trigger, view, routine, event, generated column, CHECK or expression index names $column. */
function gjc_assert_column_unreferenced(PDO $db, string $column): void
{
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT EVENT_NAME AS name, EVENT_DEFINITION AS body FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name, GENERATION_EXPRESSION AS body
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND GENERATION_EXPRESSION <> ''")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME) AS name, EXPRESSION AS body
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                AND EXPRESSION IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if ($object['body'] === null || preg_match('/\b' . preg_quote($column, '/') . '\b/i', $object['body'])) {
            throw new RuntimeException("Cannot contract {$column}: {$object['name']} may depend on it.");
        }
    }
}

/**
 * 3NF CONTRACT for year level: drops student_info.yr_lvl once every row's
 * entry_yr_lvl carries the same value, and makes entry_yr_lvl NOT NULL.
 * CLI only, outside any transaction. Without $apply it only returns the plan.
 * $apply requires $archivePath: a JSON export (written by
 * database/migrations/3nf_contract.php) that must match the current rows.
 */
function gjc_ensure_student_entry_year_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Year level CONTRACT requires CLI and no transaction.');
    }
    if (!gjc_table_exists($db, 'student_info')) {
        throw new RuntimeException('student_info is missing.');
    }
    gjc_ensure_student_entry_year_schema($db);
    $cols = gjc_table_columns($db, 'student_info', true);
    if (!in_array('yr_lvl', $cols, true)) {
        return [];
    }
    if (!in_array('entry_yr_lvl', $cols, true)) {
        throw new RuntimeException('entry_yr_lvl is missing; run the EXPAND first.');
    }
    $mismatch = (int) $db->query("SELECT COUNT(*) FROM student_info
        WHERE entry_yr_lvl IS NULL OR NOT (entry_yr_lvl <=> yr_lvl)")->fetchColumn();
    if ($mismatch !== 0) {
        throw new RuntimeException("{$mismatch} student_info row(s) have entry_yr_lvl differing from yr_lvl.");
    }
    gjc_assert_column_droppable($db, 'student_info', 'yr_lvl');

    $plan = ['student_info' => 'ALTER TABLE student_info MODIFY entry_yr_lvl VARCHAR(11) NOT NULL, DROP COLUMN yr_lvl'];
    if (!$apply) {
        return $plan;
    }
    $rows = $db->query('SELECT userID, yr_lvl FROM student_info ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC);
    gjc_assert_contract_archive($archivePath, 'student_info.yr_lvl', $rows);
    $db->exec($plan['student_info']);
    gjc_table_columns($db, 'student_info', true);
    return $plan;
}

/**
 * 3NF CONTRACT for roles: drops users.sub_role once every stored value equals
 * what gjc_sub_role_for_role_id() derives from roleID. Same CLI/archive rules
 * as gjc_ensure_student_entry_year_contract_schema().
 */
function gjc_ensure_role_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Role CONTRACT requires CLI and no transaction.');
    }
    if (!in_array('sub_role', gjc_table_columns($db, 'users', true), true)) {
        return [];
    }
    $rows = $db->query('SELECT userID, roleID, sub_role FROM users ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $stored = (string) ($row['sub_role'] ?? '');
        if ($stored !== '' && $stored !== gjc_sub_role_for_role_id((int) $row['roleID'])) {
            throw new RuntimeException("users.userID {$row['userID']}: sub_role '{$stored}' does not match roleID {$row['roleID']}.");
        }
    }
    gjc_assert_column_droppable($db, 'users', 'sub_role');

    $plan = ['users' => 'ALTER TABLE users DROP COLUMN sub_role'];
    if (!$apply) {
        return $plan;
    }
    gjc_assert_contract_archive($archivePath, 'users.sub_role', $rows);
    $db->exec($plan['users']);
    gjc_table_columns($db, 'users', true);
    return $plan;
}

/**
 * 3NF step 3: request/remittance tables whose user column is implied by the
 * wallet they point at. table => [user copy column, wallet column, wallet table].
 */
function gjc_wallet_owner_copies(): array
{
    return [
        'topup_requests'      => ['user_id', 'student_wallet_id', 'student_wallets'],
        'withdrawal_requests' => ['user_id', 'student_wallet_id', 'student_wallets'],
        'encashment_requests' => ['user_id', 'merchant_wallet_id', 'merchant_wallets'],
        'sales_remittances'   => ['merchant_user_id', 'merchant_wallet_id', 'merchant_wallets'],
    ];
}

/**
 * 3NF CONTRACT for one gjc_wallet_owner_copies() table. Refuses unless every
 * row has a wallet that exists and the copy is either NULL (written after the
 * code stopped writing it) or the same user as the wallet owner. Then
 * drops the copy's foreign keys and indexes, makes the wallet column NOT NULL
 * (each row keeps exactly one owner), guarantees a RESTRICT foreign key on the
 * wallet column, and drops the copy. CLI only, outside any transaction;
 * without $apply it only returns the plan; $apply needs a matching export.
 */
function gjc_ensure_wallet_owner_contract_schema(PDO $db, string $table, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Wallet owner CONTRACT requires CLI and no transaction.');
    }
    $copies = gjc_wallet_owner_copies();
    if (!isset($copies[$table])) {
        throw new InvalidArgumentException('Not a wallet owner contract table: ' . $table);
    }
    [$copy, $walletCol, $walletTable] = $copies[$table];
    if (!gjc_table_exists($db, $table) || !gjc_table_exists($db, $walletTable)) {
        throw new RuntimeException('Contract source table is missing: ' . $table);
    }
    $cols = gjc_table_columns($db, $table, true);
    if (!in_array($copy, $cols, true)) {
        return [];
    }
    if (!in_array($walletCol, $cols, true)) {
        throw new RuntimeException("{$table}.{$walletCol} is missing.");
    }

    $conflicts = (int) $db->query("SELECT COUNT(*) FROM `{$table}` r
        LEFT JOIN `{$walletTable}` w ON w.id = r.`{$walletCol}`
        WHERE w.id IS NULL OR (r.`{$copy}` IS NOT NULL AND r.`{$copy}` <> w.user_id)")->fetchColumn();
    if ($conflicts !== 0) {
        throw new RuntimeException("{$conflicts} {$table} row(s) have a missing wallet or a {$copy} that differs from the wallet owner.");
    }
    gjc_assert_column_unreferenced($db, $copy);

    // Foreign keys and indexes that use the copy column.
    $fkStmt = $db->prepare("SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
    $fkStmt->execute([$table, $copy]);
    $copyFks = $fkStmt->fetchAll(PDO::FETCH_COLUMN);
    $inbound = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?");
    $inbound->execute([$table, $copy]);
    if ((int) $inbound->fetchColumn() !== 0) {
        throw new RuntimeException("Another table references {$table}.{$copy}.");
    }
    $idxStmt = $db->prepare("SELECT INDEX_NAME, COUNT(*) AS width,
            SUM(COLUMN_NAME = ?) AS uses_copy
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        GROUP BY INDEX_NAME HAVING uses_copy > 0");
    $idxStmt->execute([$copy, $table]);
    $copyIndexes = [];
    foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $idx) {
        if ((int) $idx['width'] !== 1 || $idx['INDEX_NAME'] === 'PRIMARY') {
            throw new RuntimeException("Composite or primary index {$table}.{$idx['INDEX_NAME']} uses {$copy}.");
        }
        $copyIndexes[] = $idx['INDEX_NAME'];
    }

    // The wallet column must keep a RESTRICT foreign key once the copy is gone.
    $walletFk = $db->prepare("SELECT k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
            AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ?");
    $walletFk->execute([$table, $walletCol]);
    $walletFks = $walletFk->fetchAll(PDO::FETCH_ASSOC);
    foreach ($walletFks as $fk) {
        if ($fk['REFERENCED_TABLE_NAME'] !== $walletTable || $fk['REFERENCED_COLUMN_NAME'] !== 'id' || $fk['DELETE_RULE'] !== 'RESTRICT') {
            throw new RuntimeException("Unexpected foreign key {$fk['CONSTRAINT_NAME']} on {$table}.{$walletCol}.");
        }
    }

    $typeStmt = $db->prepare("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $typeStmt->execute([$table, $walletCol]);
    $walletMeta = $typeStmt->fetch(PDO::FETCH_ASSOC);
    if ($walletMeta['COLUMN_TYPE'] !== 'int unsigned' || $walletMeta['COLUMN_DEFAULT'] !== null || $walletMeta['COLUMN_COMMENT'] !== '') {
        throw new RuntimeException("Cannot preserve the definition of {$table}.{$walletCol}.");
    }

    $steps = [];
    if ($copyFks) {
        $steps[] = "ALTER TABLE `{$table}` " . implode(', ', array_map(static fn($n) => "DROP FOREIGN KEY `{$n}`", $copyFks));
    }
    $final = array_map(static fn($n) => "DROP INDEX `{$n}`", $copyIndexes);
    if ($walletMeta['IS_NULLABLE'] === 'YES') {
        $final[] = "MODIFY COLUMN `{$walletCol}` INT UNSIGNED NOT NULL";
    }
    $final[] = "DROP COLUMN `{$copy}`";
    if (!$walletFks) {
        $name = 'fk_core_' . $table . '_' . $walletCol;
        $final[] = "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$walletCol}`) REFERENCES `{$walletTable}` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT";
    }
    $steps[] = "ALTER TABLE `{$table}` " . implode(', ', $final);
    if (!$apply) {
        return $steps;
    }

    $rows = $db->query("SELECT id, `{$copy}`, `{$walletCol}` FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    gjc_assert_contract_archive($archivePath, "{$table}.{$copy}", $rows);
    foreach ($steps as $sql) {
        $db->exec($sql);
    }
    gjc_table_columns($db, $table, true);
    return $steps;
}

/**
 * 3NF step 4: columns whose every value is derivable from another row/column.
 * table => [columns, SQL counting rows where a present value disagrees with its source].
 */
function gjc_derived_fee_columns(): array
{
    return [
        // CirculationEngine always wrote amount into both (fees are added on top).
        'transactions' => [
            ['base_amount', 'credited_amount'],
            "SELECT COUNT(*) FROM transactions
              WHERE (base_amount IS NOT NULL AND base_amount <> amount)
                 OR (credited_amount IS NOT NULL AND credited_amount <> amount)",
        ],
        // Legacy request queue: the fee facts belong to the ledger row it became.
        'topup_requests' => [
            ['fee_amount', 'credited_amount', 'top_up_source'],
            "SELECT COUNT(*) FROM topup_requests r
               LEFT JOIN transactions t ON t.reference_no = r.reference_no
              WHERE (r.fee_amount IS NOT NULL OR r.credited_amount IS NOT NULL OR r.top_up_source IS NOT NULL)
                AND (t.id IS NULL
                  OR NOT (r.fee_amount <=> t.fee_amount)
                  OR (r.credited_amount IS NOT NULL AND r.credited_amount <> t.amount)
                  OR NOT (r.top_up_source <=> t.top_up_source))",
        ],
    ];
}

/**
 * 3NF step 5: stored totals derivable from other rows. Same shape as
 * gjc_derived_fee_columns(): table => [columns, SQL counting disagreeing rows].
 */
function gjc_derived_total_columns(): array
{
    return [
        // Never maintained by any code; voucher_payment_log is the source.
        'vouchers' => [
            ['use_count', 'last_used_at'],
            "SELECT COUNT(*) FROM vouchers v
              WHERE v.use_count <> (SELECT COUNT(*) FROM voucher_payment_log l WHERE l.voucher_id = v.id)
                 OR NOT (v.last_used_at <=> (SELECT MAX(l.created_at) FROM voucher_payment_log l WHERE l.voucher_id = v.id))",
        ],
        // A receipt is its maintenance_fees rows; collected fees are never voided.
        'maintenance_fee_collections' => [
            ['entry_count', 'total_amount'],
            "SELECT COUNT(*) FROM maintenance_fee_collections c
              WHERE (c.entry_count IS NOT NULL
                     AND c.entry_count <> (SELECT COUNT(*) FROM maintenance_fees f WHERE f.collection_id = c.id))
                 OR (c.total_amount IS NOT NULL
                     AND c.total_amount <> (SELECT COALESCE(SUM(f.fee_amount), 0) FROM maintenance_fees f WHERE f.collection_id = c.id))",
        ],
        // The newest super-admin mint in cap_increase_log (source NULL / super_admin_mint).
        // The stored time came from a separate NOW(), so allow a few seconds.
        'system_settings' => [
            ['last_cap_increased_by', 'last_cap_increased_at'],
            "SELECT COUNT(*) FROM system_settings s
               LEFT JOIN (SELECT super_admin_id, created_at FROM cap_increase_log
                           WHERE source IS NULL OR source = 'super_admin_mint'
                           ORDER BY created_at DESC, id DESC LIMIT 1) m ON 1 = 1
              WHERE NOT (s.last_cap_increased_by <=> m.super_admin_id)
                 OR (s.last_cap_increased_at IS NULL) <> (m.created_at IS NULL)
                 OR ABS(TIMESTAMPDIFF(SECOND, s.last_cap_increased_at, m.created_at)) > 5",
        ],
    ];
}

/** Every derived-column contract table (steps 4 and 5). */
function gjc_derived_columns(): array
{
    return gjc_derived_fee_columns() + gjc_derived_total_columns();
}

/**
 * 3NF CONTRACT: transaction_items.subtotal becomes a STORED generated column,
 * ROUND(quantity * unit_price, 2), so MySQL computes it and it cannot drift.
 * Readers are unchanged; gjc_store_transaction_items() stops supplying it.
 */
function gjc_ensure_transaction_items_subtotal_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Subtotal CONTRACT requires CLI and no transaction.');
    }
    if (!gjc_table_exists($db, 'transaction_items') || gjc_transaction_items_subtotal_is_generated($db)) {
        return [];
    }
    $meta = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_items' AND COLUMN_NAME = 'subtotal'")
        ->fetch(PDO::FETCH_ASSOC);
    if (!$meta || $meta['COLUMN_TYPE'] !== 'decimal(10,2)') {
        throw new RuntimeException('Unexpected transaction_items.subtotal definition.');
    }
    $conflicts = (int) $db->query("SELECT COUNT(*) FROM transaction_items
        WHERE NOT (subtotal <=> ROUND(quantity * unit_price, 2))")->fetchColumn();
    if ($conflicts !== 0) {
        throw new RuntimeException("{$conflicts} transaction_items row(s) have a subtotal other than quantity x unit_price.");
    }
    gjc_assert_column_unkeyed($db, 'transaction_items', 'subtotal');
    gjc_assert_column_unreferenced($db, 'subtotal');
    $plan = ['ALTER TABLE `transaction_items` MODIFY COLUMN `subtotal` DECIMAL(10,2)
        GENERATED ALWAYS AS (ROUND(`quantity` * `unit_price`, 2)) STORED NOT NULL'];
    if (!$apply) {
        return $plan;
    }
    $rows = $db->query('SELECT id, subtotal FROM transaction_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    gjc_assert_contract_archive($archivePath, 'transaction_items:subtotal', $rows);
    $db->exec($plan[0]);
    // MySQL recomputed every row; prove nothing moved.
    $after = $db->query('SELECT id, subtotal FROM transaction_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($after !== $rows) {
        throw new RuntimeException('Generated subtotals differ from the stored ones; restore from the export.');
    }
    return $plan;
}

/** 3NF CONTRACT for one gjc_derived_columns() table. Same CLI/plan/archive rules as the other contracts. */
function gjc_ensure_derived_columns_contract_schema(PDO $db, string $table, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Derived column CONTRACT requires CLI and no transaction.');
    }
    $config = gjc_derived_columns();
    if (!isset($config[$table])) {
        throw new InvalidArgumentException('Not a derived column table: ' . $table);
    }
    [$columns, $conflictSql] = $config[$table];
    $present = array_values(array_intersect($columns, gjc_table_columns($db, $table, true)));
    if (!$present) {
        return [];
    }
    if (count($present) !== count($columns)) {
        throw new RuntimeException("{$table} is partially contracted; restore it from the export first.");
    }
    if ($table === 'transactions') {
        $unsplit = (int) $db->query("SELECT COUNT(*) FROM transactions
            WHERE fee_amount IS NOT NULL AND merchant_fee IS NULL")->fetchColumn();
        if ($unsplit !== 0) {
            throw new RuntimeException("{$unsplit} fee transaction(s) have no merchant_fee split yet.");
        }
    }
    $conflicts = (int) $db->query($conflictSql)->fetchColumn();
    if ($conflicts !== 0) {
        throw new RuntimeException("{$conflicts} {$table} row(s) disagree with the value they duplicate.");
    }
    foreach ($columns as $column) {
        gjc_assert_column_unkeyed($db, $table, $column);
        gjc_assert_column_unreferenced($db, $column);
    }
    $plan = ["ALTER TABLE `{$table}` " . implode(', ', array_map(static fn($c) => "DROP COLUMN `{$c}`", $columns))];
    if (!$apply) {
        return $plan;
    }
    $cols = implode(', ', array_map(static fn($c) => "`{$c}`", $columns));
    $rows = $db->query("SELECT id, {$cols} FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    gjc_assert_contract_archive($archivePath, "{$table}:" . implode(',', $columns), $rows);
    $db->exec($plan[0]);
    gjc_table_columns($db, $table, true);
    return $plan;
}

/**
 * 3NF step 4: whole tables that hold nothing the rest of the schema lacks.
 * table => SQL counting rows that would lose information if the table went.
 */
function gjc_obsolete_fee_tables(): array
{
    return [
        // Every column is the ledger row's (source, amount + fee, both fees,
        // merchant wallet, initiator, time); the split now lives on transactions.
        'fee_revenue_log' => "SELECT COUNT(*) FROM fee_revenue_log f
            LEFT JOIN transactions t ON t.reference_no = f.transaction_ref
            WHERE t.id IS NULL
               OR NOT (f.top_up_source <=> t.top_up_source)
               OR t.fee_amount IS NULL OR t.merchant_fee IS NULL
               OR f.system_fee + f.merchant_fee <> t.fee_amount
               OR f.merchant_fee <> t.merchant_fee
               OR f.cash_amount <> t.amount + t.fee_amount
               OR NOT (f.merchant_wallet_id <=> t.merchant_wallet_id)
               OR f.processed_by <> t.initiated_by
               OR f.created_at <> t.created_at",
        // Never written by any code; dropping is only safe while they stay empty.
        'parent_topup_requests' => "SELECT COUNT(*) FROM parent_topup_requests",
        'school_revenue_ledger' => "SELECT COUNT(*) FROM school_revenue_ledger",
    ];
}

/** 3NF CONTRACT: DROP TABLE for one gjc_obsolete_fee_tables() table, after its rows and definition are exported. */
function gjc_ensure_obsolete_fee_table_contract_schema(PDO $db, string $table, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Obsolete table CONTRACT requires CLI and no transaction.');
    }
    $config = gjc_obsolete_fee_tables();
    if (!isset($config[$table])) {
        throw new InvalidArgumentException('Not an obsolete fee table: ' . $table);
    }
    if (!gjc_table_exists($db, $table)) {
        return [];
    }
    $losing = (int) $db->query($config[$table])->fetchColumn();
    if ($losing !== 0) {
        throw new RuntimeException("{$losing} {$table} row(s) hold data found nowhere else.");
    }
    $inbound = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND TABLE_NAME <> ?");
    $inbound->execute([$table, $table]);
    if ((int) $inbound->fetchColumn() !== 0) {
        throw new RuntimeException("Another table has a foreign key to {$table}.");
    }
    gjc_assert_column_unreferenced($db, $table);
    if ($table === 'fee_revenue_log') {
        $unsplit = (int) $db->query("SELECT COUNT(*) FROM transactions
            WHERE fee_amount IS NOT NULL AND merchant_fee IS NULL")->fetchColumn();
        if ($unsplit !== 0) {
            throw new RuntimeException("{$unsplit} fee transaction(s) have no merchant_fee split yet.");
        }
    }
    $plan = ["DROP TABLE `{$table}`"];
    if (!$apply) {
        return $plan;
    }
    gjc_assert_contract_archive($archivePath, "table:{$table}", gjc_table_export($db, $table));
    $db->exec($plan[0]);
    return $plan;
}

/** Definition + every row of a table, for a contract's private export. */
function gjc_table_export(PDO $db, string $table): array
{
    return [
        'schema' => $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1],
        'rows'   => $db->query("SELECT * FROM `{$table}` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/** A contract may only drop data that a matching private export already holds. */
function gjc_assert_contract_archive(?string $archivePath, string $column, array $rows): void
{
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException("A private export of {$column} is required before it is dropped.");
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    if (($archive[$column] ?? null) !== $rows) {
        throw new RuntimeException("The private export of {$column} differs from the current rows.");
    }
}

function gjc_ensure_student_info_record(PDO $db, int $userId, string $studentId): void
{
    $existing = $db->prepare("SELECT stud_infoID FROM student_info WHERE userID = ? LIMIT 1");
    $existing->execute([$userId]);
    if ($existing->fetchColumn()) {
        $db->prepare("UPDATE student_info SET studentID = ? WHERE userID = ?")->execute([$studentId, $userId]);
        return;
    }

    // Resolve a valid courseID — use the first existing one, or create a placeholder.
    $courseId = (int) $db->query("SELECT courseID FROM course ORDER BY courseID ASC LIMIT 1")->fetchColumn();
    if ($courseId === 0) {
        $db->exec("INSERT INTO course (course_code, course_name) VALUES ('GENERAL', 'General')");
        $courseId = (int) $db->lastInsertId();
    }

    [$yrCols, $yrMarks, $yrCount] = gjc_student_entry_year_write_columns($db);
    $db->prepare("INSERT INTO student_info (userID, studentID, {$yrCols}, courseID) VALUES (?, ?, {$yrMarks}, ?)")
       ->execute(array_merge([$userId, $studentId], array_fill(0, $yrCount, '1'), [$courseId]));
}

function gjc_backfill_student_ids(PDO $db): void
{
    if (!gjc_table_exists($db, 'student_info') || !gjc_table_exists($db, 'users')) {
        return;
    }
    gjc_ensure_student_entry_year_schema($db);
    $stmt = $db->query(
        "SELECT u.userID
           FROM users u
           LEFT JOIN student_info si ON si.userID = u.userID
          WHERE u.roleID = 1
            AND (si.stud_infoID IS NULL
              OR si.studentID = ''
              OR si.studentID NOT REGEXP '^GJC[0-9]{4}-[0-9]+$')
          ORDER BY u.userID ASC"
    );
    $missing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($missing as $userId) {
        $newId = gjc_generate_student_id($db);
        gjc_ensure_student_info_record($db, (int) $userId, $newId);
    }
}

function gjc_ensure_merchant_qr_orders_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS merchant_qr_orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL UNIQUE,
            short_code VARCHAR(12) NULL,
            merchant_wallet_id INT UNSIGNED NOT NULL,
            description TEXT NULL,
            amount DECIMAL(15,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            paid_by INT NULL,
            created_by INT NULL,
            paid_ref VARCHAR(40) NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mqo_token (token),
            INDEX idx_mqo_status_expiry (status, expires_at),
            INDEX idx_mqo_merchant_wallet (merchant_wallet_id),
            UNIQUE INDEX uq_mqo_short_code (short_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );

    $adds = [
        "short_code" => "VARCHAR(12) NULL AFTER token",
        "description" => "TEXT NULL",
        "amount" => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
        "status" => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
        "expires_at" => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        "paid_by" => "INT NULL",
        "created_by" => "INT NULL",
        "paid_ref" => "VARCHAR(40) NULL",
        "paid_at" => "DATETIME NULL",
        "created_at" => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];

    $columns = gjc_table_columns($db, "merchant_qr_orders");
    foreach ($adds as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $db->exec("ALTER TABLE merchant_qr_orders ADD COLUMN {$column} {$definition}");
        }
    }
    gjc_table_columns($db, 'merchant_qr_orders', true);

    $idxStmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'merchant_qr_orders'
            AND INDEX_NAME = 'uq_mqo_short_code'"
    );
    $idxStmt->execute();
    if ((int) $idxStmt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE merchant_qr_orders ADD UNIQUE INDEX uq_mqo_short_code (short_code)");
    }
}

/**
 * Cryptographically random short code for manual (typed) payment entry.
 * 8 chars from an unambiguous alphabet (no 0/O, 1/I/L), unique across
 * merchant_qr_orders. The 32-hex QR token is unrealistic to type, so the
 * POS shows this code beside the QR as the camera-less fallback; both
 * resolve to the same single-use order row.
 */
function gjc_generate_order_short_code(PDO $db): string
{
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $max = strlen($alphabet) - 1;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM merchant_qr_orders WHERE short_code = ?");
        $stmt->execute([$code]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $code;
        }
    }

    throw new RuntimeException('Could not allocate a unique payment code. Please try again.');
}

/**
 * Makes merchant_inventory.sku safe to use as a scan key for the student
 * cart: a SKU must be unique within a single merchant's catalog (NULL skus
 * are exempt, so optional SKUs still work). Attempts the index once; if
 * older duplicate data blocks it, the ALTER is skipped silently — app-layer
 * checks in merchant/api/inventory.php still prevent new collisions either way.
 */
function gjc_ensure_inventory_sku_index(PDO $db): void
{
    if (!gjc_table_exists($db, "merchant_inventory")) {
        return;
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'merchant_inventory' AND INDEX_NAME = 'uq_merchant_sku'"
    );
    $stmt->execute();
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    try {
        $db->exec(
            "ALTER TABLE merchant_inventory ADD UNIQUE KEY uq_merchant_sku (merchant_user_id, sku)"
        );
    } catch (\Throwable $e) {
        // Existing duplicate SKUs from before this rule existed — leave the
        // index off for now; app-layer validation still blocks new clashes.
    }
}

/**
 * Re-reads every line of the student's session cart against
 * merchant_inventory right now, drops any item that became
 * unavailable/restricted/out-of-stock since it was scanned (reporting why),
 * and returns a ready-to-render/ready-to-charge snapshot. Shared by the
 * cart UI (student/api/cart.php) and checkout (student/api/checkout.php) so
 * both always agree on what the cart actually contains.
 */
function gjc_cart_snapshot(PDO $db): array
{
    $cart = $_SESSION['cart'] ?? ['merchant_user_id' => null, 'items' => []];
    $itemIds = array_keys($cart['items'] ?? []);

    if (empty($itemIds)) {
        $_SESSION['cart'] = ['merchant_user_id' => null, 'items' => []];
        return [
            'merchant_user_id' => null,
            'merchant_label' => null,
            'lines' => [],
            'total' => 0.0,
            'dropped' => [],
        ];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $db->prepare(
        "SELECT id, sku, product_name, price, stock_qty, is_available, is_restricted, restriction_note, merchant_user_id
           FROM merchant_inventory
          WHERE id IN ({$placeholders})"
    );
    $stmt->execute($itemIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rowsById = [];
    foreach ($rows as $row) {
        $rowsById[(int) $row['id']] = $row;
    }

    $lines = [];
    $dropped = [];
    $total = 0.0;
    $merchantUserId = $cart['merchant_user_id'] ?? null;

    // A suspended stall can't sell at all — whether the suspension came from
    // restricted-product strikes or from finance suspending the owner's
    // account. Empty the cart with the reason so the student isn't left
    // holding an order they can never check out.
    if ($merchantUserId && gjc_merchant_sales_blocked($db, (int) $merchantUserId)) {
        $stallLabel = gjc_merchant_display_name($db, (int) $merchantUserId) ?: 'This stall';
        foreach ($cart['items'] as $itemId => $qty) {
            $row = $rowsById[(int) $itemId] ?? null;
            $dropped[] = [
                'name' => $row ? $row['product_name'] : "Item #{$itemId}",
                'reason' => "{$stallLabel} is temporarily suspended and cannot take orders.",
            ];
        }
        $_SESSION['cart'] = ['merchant_user_id' => null, 'items' => []];
        return [
            'merchant_user_id' => null,
            'merchant_label' => null,
            'lines' => [],
            'total' => 0.0,
            'dropped' => $dropped,
        ];
    }

    foreach ($cart['items'] as $itemId => $qty) {
        $row = $rowsById[(int) $itemId] ?? null;

        if (!$row) {
            $dropped[] = ['name' => "Item #{$itemId}", 'reason' => 'This product no longer exists.'];
            unset($_SESSION['cart']['items'][$itemId]);
            continue;
        }
        if ((int) $row['is_restricted'] === 1) {
            $dropped[] = ['name' => $row['product_name'], 'reason' => $row['restriction_note'] ?: 'This item now violates campus health guidelines.'];
            unset($_SESSION['cart']['items'][$itemId]);
            continue;
        }
        if ((int) $row['is_available'] !== 1) {
            $dropped[] = ['name' => $row['product_name'], 'reason' => 'The merchant marked this item unavailable.'];
            unset($_SESSION['cart']['items'][$itemId]);
            continue;
        }

        $qty = min((int) $qty, (int) $row['stock_qty']);
        if ($qty <= 0) {
            $dropped[] = ['name' => $row['product_name'], 'reason' => 'Out of stock.'];
            unset($_SESSION['cart']['items'][$itemId]);
            continue;
        }
        $_SESSION['cart']['items'][$itemId] = $qty;

        $lineTotal = round((float) $row['price'] * $qty, 2);
        $total += $lineTotal;
        $lines[] = [
            'id' => (int) $row['id'],
            'sku' => $row['sku'],
            'name' => $row['product_name'],
            'price' => round((float) $row['price'], 2),
            'qty' => $qty,
            'stock_qty' => (int) $row['stock_qty'],
            'line_total' => $lineTotal,
        ];
    }

    if (empty($_SESSION['cart']['items'])) {
        $_SESSION['cart']['merchant_user_id'] = null;
        $merchantUserId = null;
    }

    return [
        'merchant_user_id' => $merchantUserId,
        'merchant_label' => $merchantUserId ? gjc_merchant_display_name($db, $merchantUserId) : null,
        'lines' => $lines,
        'total' => round($total, 2),
        'dropped' => $dropped,
    ];
}

/**
 * Resolves a merchant's public display name (stall name, falling back to
 * their account name) from their user id. Shared by the cart snapshot and
 * the Shop Cart order endpoints so every screen shows the same label.
 */
function gjc_merchant_display_name(PDO $db, int $merchantUserId): ?string
{
    $stmt = $db->prepare(
        "SELECT m.stall_name, u.first_name, u.last_name
           FROM users u
           LEFT JOIN merchant m ON m.userID = u.userID
          WHERE u.userID = ?
          LIMIT 1"
    );
    $stmt->execute([$merchantUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return $row['stall_name'] ?: trim($row['first_name'] . ' ' . $row['last_name']);
}

/**
 * Widens transactions.transaction_type to include every label in
 * $requiredTypes, by reading the CURRENT live enum and rebuilding the
 * MODIFY with the union — never a hardcoded replacement list, which would
 * silently drop any label added by a migration this function doesn't know
 * about (that was the bug in the old gjc_ensure_transaction_refund_type()).
 * Call this from a page/dispatcher BEFORE any beginTransaction() — it's DDL,
 * and MySQL implicitly commits an open transaction the instant it sees DDL.
 */
function gjc_ensure_transaction_types(PDO $db, array $requiredTypes): void
{
    try {
        $column = $db->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
        $type = (string) ($column['Type'] ?? '');
        if (!$column) {
            return;
        }

        $missing = false;
        foreach ($requiredTypes as $required) {
            if (strpos($type, "'{$required}'") === false) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $labelMatches);
        $labels = $labelMatches[1] ?? [];
        foreach ($requiredTypes as $required) {
            if (!in_array($required, $labels, true)) {
                $labels[] = $required;
            }
        }
        $enumSql = implode(', ', array_map(
            static fn(string $label): string => "'" . str_replace("'", "\\'", $label) . "'",
            $labels
        ));
        $db->exec("ALTER TABLE transactions MODIFY transaction_type ENUM({$enumSql}) NOT NULL");
    } catch (\Throwable $e) {
        // Leave the ENUM as-is; the insert below will surface the failure clearly.
    }
}

/**
 * Same safe widen-from-the-live-enum approach as gjc_ensure_transaction_types(),
 * generalized to any ENUM column — used for cap_increase_log.source so new mint
 * sources don't need a bespoke widener each time. Call before beginTransaction();
 * this is DDL and MySQL implicitly commits an open transaction the instant it
 * sees it.
 */
function gjc_ensure_enum_values(PDO $db, string $table, string $column, array $required): void
{
    try {
        $column_info = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column))->fetch(PDO::FETCH_ASSOC);
        if (!$column_info) {
            return;
        }
        $type = (string) ($column_info['Type'] ?? '');

        $missing = false;
        foreach ($required as $value) {
            if (strpos($type, "'{$value}'") === false) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $labelMatches);
        $labels = $labelMatches[1] ?? [];
        foreach ($required as $value) {
            if (!in_array($value, $labels, true)) {
                $labels[] = $value;
            }
        }
        $enumSql = implode(', ', array_map(
            static fn(string $label): string => "'" . str_replace("'", "\\'", $label) . "'",
            $labels
        ));
        $null = stripos($type, 'NOT NULL') !== false ? ' NOT NULL' : '';
        $db->exec("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM({$enumSql}){$null}");
    } catch (\Throwable $e) {
        // Leave the ENUM as-is; the insert below will surface the failure clearly.
    }
}

/**
 * Widens transactions.transaction_type to include 'refund' (used when a
 * merchant issues a Return). Kept as its own function since
 * merchant/api/returns.php already calls it by this name.
 */
function gjc_ensure_transaction_refund_type(PDO $db): void
{
    gjc_ensure_transaction_types($db, ['refund']);
}

/**
 * Pending Shop Cart orders — a student submits a cart (no money moves yet),
 * the merchant sees it immediately in the Live Order Queue, and the student
 * later pays by scanning the merchant's static Wallet QR at the counter.
 */
function gjc_ensure_cart_orders_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS cart_orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reference_no VARCHAR(30) NOT NULL UNIQUE,
            student_wallet_id INT UNSIGNED NULL,
            merchant_wallet_id INT UNSIGNED NOT NULL,
            buyer_role VARCHAR(10) NOT NULL DEFAULT 'student',
            parent_wallet_id INT UNSIGNED NULL,
            description TEXT NULL,
            amount DECIMAL(15,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            paid_ref VARCHAR(40) NULL,
            INDEX idx_cart_orders_student_wallet (student_wallet_id, status),
            INDEX idx_cart_orders_parent_wallet (parent_wallet_id, status),
            INDEX idx_cart_orders_merchant_wallet (merchant_wallet_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Parent shop-cart (2026-08-27). Additive only — existing student rows keep
    // their values and default to buyer_role 'student', so nothing is rewritten.
    $cols = gjc_table_columns($db, 'cart_orders', true);

    if (!in_array('buyer_role', $cols, true)) {
        $db->exec("ALTER TABLE cart_orders ADD COLUMN buyer_role VARCHAR(10) NOT NULL DEFAULT 'student'");
    }
    if (!in_array('parent_wallet_id', $cols, true)) {
        $db->exec("ALTER TABLE cart_orders ADD COLUMN parent_wallet_id INT UNSIGNED NULL");
    }

    // A parent order has no student wallet, so that column must accept NULL.
    // Copied user IDs are retained only in pre-CONTRACT schemas.
    foreach (['student_wallet_id'] as $col) {
        try {
            $def = $db->query("SHOW COLUMNS FROM cart_orders LIKE " . $db->quote($col))->fetch(PDO::FETCH_ASSOC);
            if ($def && strtoupper((string) $def['Null']) === 'NO') {
                $db->exec("ALTER TABLE cart_orders MODIFY `$col` {$def['Type']} NULL");
            }
        } catch (\Throwable $ignored) {}
    }
}

/** Explicit CLI cleanup; export the empty legacy definitions before calling. */
function gjc_ensure_legacy_table_cleanup_schema(PDO $db): void
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Legacy table cleanup must be invoked explicitly from the CLI.');
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Legacy table cleanup cannot run inside a transaction.');
    }
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Legacy table cleanup requires the ewallet database.');
    }

    $expected = [
        'topup' => ['topupID', 'adminID', 'userID', 'wallet_id', 'amount', 'remarks', 'date_time'],
        'transaction' => ['transactionID', 'wallet_id', 'merchantID', 'amount', 'date_time', 'description', 'reference'],
        'wallet' => ['wallet_id', 'userID', 'balance', 'last_updated'],
    ];
    $existing = array_filter(array_keys($expected), fn($table) => gjc_table_exists($db, $table));
    if (!$existing) {
        return;
    }

    $archivePath = BASE_PATH . '/storage/backups/genpay-legacy-table-definitions-2026-10-08.sql';
    $archive = is_file($archivePath) ? file_get_contents($archivePath) : false;
    if ($archive === false) {
        throw new RuntimeException('The verified legacy table definition export is required before cleanup.');
    }
    foreach ($existing as $table) {
        $columns = gjc_table_columns($db, $table, true);
        $required = $expected[$table];
        sort($columns);
        sort($required);
        if ($columns !== $required) {
            throw new RuntimeException('Unexpected legacy table structure: ' . $table);
        }
        $ddl = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        if (!str_contains($archive, $ddl . ';')) {
            throw new RuntimeException('Legacy table export does not match the live definition: ' . $table);
        }
        if ((int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() !== 0) {
            throw new RuntimeException('Refusing to drop a nonempty legacy table: ' . $table);
        }
    }

    $db->exec('DROP TABLE IF EXISTS `ewallet`.`topup`, `ewallet`.`transaction`, `ewallet`.`wallet`');
}

/**
 * Explicit Group B B2 CONTRACT. Defaults to read-only planning.
 * Applying requires a private export of each affected table's exact rows and definition.
 */
function gjc_ensure_group_b_wallet_identity_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Group B identity contraction requires CLI, ewallet, and no transaction.');
    }
    $copies = [
        'cart_orders' => ['student_user_id', 'merchant_user_id', 'parent_user_id'],
        'merchant_qr_orders' => ['merchant_user_id'],
        'p2p_transfers' => ['from_user_id', 'to_user_id'],
        'parent_alerts' => ['student_user_id'],
        'tuition_credits' => ['student_user_id'],
    ];
    $checks = [
        'cart_orders' => "SELECT COUNT(*) FROM cart_orders o
            LEFT JOIN student_wallets sw ON sw.id = o.student_wallet_id
            LEFT JOIN merchant_wallets mw ON mw.id = o.merchant_wallet_id
            LEFT JOIN parent_wallets pw ON pw.id = o.parent_wallet_id
            LEFT JOIN parents p ON p.id = pw.parent_id
            WHERE mw.id IS NULL OR NOT(o.student_user_id <=> sw.user_id)
               OR NOT(o.merchant_user_id <=> mw.user_id) OR NOT(o.parent_user_id <=> p.user_id)
               OR (o.student_wallet_id IS NOT NULL AND sw.id IS NULL)
               OR (o.parent_wallet_id IS NOT NULL AND (pw.id IS NULL OR p.id IS NULL))
               OR o.buyer_role NOT IN ('student','parent')
               OR (o.buyer_role = 'student' AND (sw.id IS NULL OR o.parent_wallet_id IS NOT NULL))
               OR (o.buyer_role = 'parent' AND (p.id IS NULL OR o.student_wallet_id IS NOT NULL))",
        'merchant_qr_orders' => "SELECT COUNT(*) FROM merchant_qr_orders o
            LEFT JOIN merchant_wallets mw ON mw.id = o.merchant_wallet_id
            WHERE mw.id IS NULL OR NOT(o.merchant_user_id <=> mw.user_id)",
        'p2p_transfers' => "SELECT COUNT(*) FROM p2p_transfers p
            LEFT JOIN student_wallets f ON f.id = p.from_wallet_id
            LEFT JOIN student_wallets t ON t.id = p.to_wallet_id
            WHERE f.id IS NULL OR t.id IS NULL
               OR NOT(p.from_user_id <=> f.user_id) OR NOT(p.to_user_id <=> t.user_id)",
        'parent_alerts' => "SELECT COUNT(*) FROM parent_alerts a
            LEFT JOIN student_wallets sw ON sw.id = a.student_wallet_id
            WHERE sw.id IS NULL OR NOT(a.student_user_id <=> sw.user_id)",
        'tuition_credits' => "SELECT COUNT(*) FROM tuition_credits c
            LEFT JOIN student_wallets sw ON sw.id = c.student_wallet_id
            WHERE sw.id IS NULL OR NOT(c.student_user_id <=> sw.user_id)",
    ];
    $keys = $db->query("SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME,
        k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME
         AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL")
        ->fetchAll(PDO::FETCH_ASSOC);
    $indexes = $db->query("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $walletLinks = [
        'cart_orders' => ['student_wallet_id' => 'student_wallets', 'merchant_wallet_id' => 'merchant_wallets', 'parent_wallet_id' => 'parent_wallets'],
        'merchant_qr_orders' => ['merchant_wallet_id' => 'merchant_wallets'],
        'p2p_transfers' => ['from_wallet_id' => 'student_wallets', 'to_wallet_id' => 'student_wallets'],
        'parent_alerts' => ['student_wallet_id' => 'student_wallets'],
        'tuition_credits' => ['student_wallet_id' => 'student_wallets'],
    ];
    $plan = [];
    foreach ($copies as $table => $columns) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Contract source table is missing: ' . $table);
        }
        $live = gjc_table_columns($db, $table, true);
        $present = array_values(array_intersect($columns, $live));
        if (!$present) {
            continue;
        }
        if (count($present) !== count($columns)) {
            throw new RuntimeException('Partially contracted compatibility columns: ' . $table);
        }
        if ((int) $db->query($checks[$table])->fetchColumn() !== 0) {
            throw new RuntimeException('Conflicting identities or orphan wallets block CONTRACT: ' . $table);
        }
        foreach ($walletLinks[$table] as $column => $target) {
            $matching = array_values(array_filter($keys, static fn($key) =>
                $key['TABLE_NAME'] === $table && $key['COLUMN_NAME'] === $column
                && $key['REFERENCED_TABLE_NAME'] === $target && $key['REFERENCED_COLUMN_NAME'] === 'id'
                && $key['DELETE_RULE'] === 'RESTRICT' && $key['UPDATE_RULE'] === 'RESTRICT'));
            if (count($matching) !== 1) {
                throw new RuntimeException('Required protected wallet link is missing: ' . $table . '.' . $column);
            }
        }
        $clauses = [];
        $dropKeys = [];
        foreach ($keys as $key) {
            if ($key['REFERENCED_TABLE_NAME'] === $table
                && in_array($key['REFERENCED_COLUMN_NAME'], $columns, true)) {
                throw new RuntimeException('Another table references a copied ID: ' . $table);
            }
            if ($key['TABLE_NAME'] === $table && in_array($key['COLUMN_NAME'], $columns, true)) {
                $expected = 'fk_core_' . $table . '_' . $key['COLUMN_NAME'];
                if ($key['CONSTRAINT_NAME'] !== $expected || $key['REFERENCED_TABLE_NAME'] !== 'users'
                    || $key['REFERENCED_COLUMN_NAME'] !== 'userID'
                    || $key['DELETE_RULE'] !== 'RESTRICT' || $key['UPDATE_RULE'] !== 'RESTRICT') {
                    throw new RuntimeException('Unexpected copied-ID foreign key: ' . $table);
                }
                $dropKeys[$expected] = true;
            }
        }
        foreach (array_keys($dropKeys) as $name) {
            $clauses[] = 'DROP FOREIGN KEY `' . $name . '`';
        }
        $tableIndexes = [];
        foreach ($indexes as $index) {
            if ($index['TABLE_NAME'] === $table) {
                $tableIndexes[$index['INDEX_NAME']][] = $index;
            }
        }
        foreach ($tableIndexes as $name => $parts) {
            if (!array_intersect(array_column($parts, 'COLUMN_NAME'), $columns)) {
                continue;
            }
            if ($name === 'PRIMARY' || (int) $parts[0]['NON_UNIQUE'] !== 1
                || array_diff(array_column($parts, 'COLUMN_NAME'), array_merge($columns, ['status']))) {
                throw new RuntimeException('Unexpected index involving copied IDs: ' . $table . '.' . $name);
            }
            $clauses[] = 'DROP INDEX `' . str_replace('`', '``', $name) . '`';
        }
        foreach ($columns as $column) {
            $clauses[] = 'DROP COLUMN `' . $column . '`';
        }
        $plan[$table] = 'ALTER TABLE `' . $table . '` ' . implode(', ', $clauses);
    }
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS object_name, ACTION_STATEMENT AS body
            FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS object_name, VIEW_DEFINITION AS body
            FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS object_name, ROUTINE_DEFINITION AS body
            FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        foreach ($copies as $table => $columns) {
            if (!isset($plan[$table]) || !preg_match('/\\b' . preg_quote($table, '/') . '\\b/i', $object['body'] ?? '')) {
                continue;
            }
            foreach ($columns as $column) {
                if (preg_match('/\\b' . preg_quote($column, '/') . '\\b/i', $object['body'] ?? '')) {
                    throw new RuntimeException('Database object depends on copied IDs: ' . $object['object_name']);
                }
            }
        }
    }
    if (!$apply || !$plan) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A private pre-CONTRACT table export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($plan as $table => $sql) {
        $definition = $db->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $rows = $db->query('SELECT * FROM `' . $table . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (($archive[$table]['schema'] ?? null) !== $definition || ($archive[$table]['rows'] ?? null) !== $rows) {
            throw new RuntimeException('Private export differs from current table: ' . $table);
        }
    }
    foreach ($plan as $sql) {
        $db->exec($sql);
    }
    return $plan;
}

/**
 * Explicit Group B wallet-link EXPAND; preflight both tables before any DDL.
 */
function gjc_ensure_group_b_wallet_links_schema(PDO $db): void
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Group B wallet-link expansion requires an explicit CLI call outside a transaction.');
    }
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Group B wallet-link expansion requires the ewallet database.');
    }
    $plan = [
        'parent_alerts' => 'fk_group_b_parent_alerts_student_wallet',
        'tuition_credits' => 'fk_group_b_tuition_credits_student_wallet',
    ];
    foreach (array_merge(['student_wallets'], array_keys($plan)) as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Required wallet-link table is missing: ' . $table);
        }
    }
    $metadata = [];
    foreach ($db->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('student_wallets', 'parent_alerts', 'tuition_credits')")
        ->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $metadata[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
    }
    if (($metadata['student_wallets']['id']['COLUMN_TYPE'] ?? null) !== 'int unsigned') {
        throw new RuntimeException('Unexpected student wallet primary-key type.');
    }
    $existing = $db->query("SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME,
        k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
         AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL")
        ->fetchAll(PDO::FETCH_ASSOC);
    $additions = [];
    foreach ($plan as $table => $name) {
        $column = $metadata[$table]['student_wallet_id'] ?? null;
        if (!$column || $column['COLUMN_TYPE'] !== 'int unsigned' || $column['IS_NULLABLE'] !== 'NO') {
            throw new RuntimeException('Unexpected student-wallet reference definition: ' . $table);
        }
        if ((int) $db->query("SELECT COUNT(*) FROM `{$table}` c
            LEFT JOIN student_wallets sw ON sw.id = c.student_wallet_id
            WHERE sw.id IS NULL")->fetchColumn() !== 0) {
            throw new RuntimeException('Orphan student-wallet references block expansion: ' . $table);
        }
        $matches = array_values(array_filter($existing,
            static fn($row) => $row['CONSTRAINT_NAME'] === $name));
        foreach ($existing as $row) {
            if ($row['TABLE_NAME'] === $table && $row['COLUMN_NAME'] === 'student_wallet_id'
                && $row['CONSTRAINT_NAME'] !== $name) {
                throw new RuntimeException('An unplanned foreign key uses: ' . $table . '.student_wallet_id');
            }
        }
        if ($matches) {
            $row = $matches[0];
            if (count($matches) !== 1 || $row['TABLE_NAME'] !== $table
                || $row['COLUMN_NAME'] !== 'student_wallet_id'
                || $row['REFERENCED_TABLE_NAME'] !== 'student_wallets'
                || $row['REFERENCED_COLUMN_NAME'] !== 'id'
                || $row['DELETE_RULE'] !== 'RESTRICT' || $row['UPDATE_RULE'] !== 'RESTRICT') {
                throw new RuntimeException('Conflicting wallet-link foreign key: ' . $name);
            }
        } else {
            $additions[$table] = $name;
        }
    }
    foreach ($additions as $table => $name) {
        $db->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}`
            FOREIGN KEY (student_wallet_id) REFERENCES student_wallets(id)
            ON DELETE RESTRICT ON UPDATE RESTRICT");
    }
}

/** Explicit core-only migration; preflight all relationships before any DDL. */
function gjc_ensure_core_integrity_schema(PDO $db): void
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Core integrity migration requires an explicit CLI call outside a transaction.');
    }
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Core integrity migration requires the ewallet database.');
    }
    $plan = [
        'users' => [
            'roleID' => ['role', 'roleID', 'RESTRICT'],
            'suspended_by' => ['users', 'userID', 'RESTRICT'],
        ],
        'auth_remember_tokens' => [
            'user_id' => ['users', 'userID', 'CASCADE'],
        ],
        'student_wallets' => [
            'user_id' => ['users', 'userID', 'RESTRICT'],
        ],
        'parents' => [
            'user_id' => ['users', 'userID', 'RESTRICT'],
        ],
        'parent_wallets' => [
            'parent_id' => ['parents', 'id', 'RESTRICT'],
        ],
        'parent_student_links' => [
            'parent_id' => ['parents', 'id', 'CASCADE'],
            'student_user_id' => ['users', 'userID', 'CASCADE'],
        ],
        'merchant_accounts' => [
            'user_id' => ['users', 'userID', 'RESTRICT'],
            'created_by' => ['users', 'userID', 'RESTRICT'],
        ],
        'cart_orders' => [
            'student_wallet_id' => ['student_wallets', 'id', 'RESTRICT'],
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
            'parent_wallet_id' => ['parent_wallets', 'id', 'RESTRICT'],
            'served_by' => ['users', 'userID', 'RESTRICT'],
            'paid_ref' => ['transactions', 'reference_no', 'RESTRICT'],
        ],
        'merchant_qr_orders' => [
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
            'paid_by' => ['users', 'userID', 'RESTRICT'],
            'paid_ref' => ['transactions', 'reference_no', 'RESTRICT'],
        ],
        'transactions' => [
            'initiated_by' => ['users', 'userID', 'RESTRICT'],
            'student_wallet_id' => ['student_wallets', 'id', 'RESTRICT'],
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
            'parent_wallet_id' => ['parent_wallets', 'id', 'RESTRICT'],
            'voucher_id' => ['vouchers', 'id', 'RESTRICT'],
            'school_year_id' => ['school_years', 'id', 'RESTRICT'],
        ],
        'transaction_items' => [
            'transaction_id' => ['transactions', 'id', 'RESTRICT'],
        ],
        'p2p_transfers' => [
            'from_wallet_id' => ['student_wallets', 'id', 'RESTRICT'],
            'to_wallet_id' => ['student_wallets', 'id', 'RESTRICT'],
            'reference_no' => ['transactions', 'reference_no', 'RESTRICT'],
        ],
        'topup_requests' => [
            'student_wallet_id' => ['student_wallets', 'id', 'RESTRICT'],
            'approved_by' => ['users', 'userID', 'RESTRICT'],
            'rejected_by' => ['users', 'userID', 'RESTRICT'],
        ],
        'withdrawal_requests' => [
            'student_wallet_id' => ['student_wallets', 'id', 'RESTRICT'],
            'released_by' => ['users', 'userID', 'RESTRICT'],
            'rejected_by' => ['users', 'userID', 'RESTRICT'],
        ],
        'encashment_requests' => [
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
            'released_by' => ['users', 'userID', 'RESTRICT'],
            'rejected_by' => ['users', 'userID', 'RESTRICT'],
        ],
        'sales_remittances' => [
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
        ],
        'vouchers' => [
            'issued_by' => ['users', 'userID', 'RESTRICT'],
        ],
        'voucher_payment_log' => [
            'voucher_id' => ['vouchers', 'id', 'RESTRICT'],
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
            'scanned_by' => ['users', 'userID', 'RESTRICT'],
            'transaction_ref' => ['transactions', 'reference_no', 'RESTRICT'],
        ],
    ];
    // The normalized schema owns this relationship after suspension CONTRACT.
    if (!gjc_has_legacy_user_suspension($db)) {
        unset($plan['users']['suspended_by']);
    }
    $metadata = [];
    foreach ($db->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $metadata[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
    }
    $existing = [];
    foreach ($db->query('SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME,
        k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
            AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[$row['CONSTRAINT_NAME']][] = $row;
    }
    $typeChanges = [];
    $additions = [];
    foreach ($plan as $table => $columns) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Core integrity source table is missing: ' . $table);
        }
        $liveColumns = gjc_table_columns($db, $table, true);
        $ddl = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        foreach ($columns as $column => [$parent, $parentKey, $deleteRule]) {
            if (!in_array($column, $liveColumns, true) || !isset($metadata[$parent][$parentKey])) {
                throw new RuntimeException('Core integrity column is missing: ' . $table . '.' . $column);
            }
            $source = $metadata[$table][$column];
            $target = $metadata[$parent][$parentKey];
            $isUserReference = $parent === 'users' && $parentKey === 'userID';
            if ($isUserReference) {
                if ($target['COLUMN_TYPE'] !== 'int' || !in_array($source['COLUMN_TYPE'], ['int', 'int unsigned'], true)) {
                    throw new RuntimeException('Unexpected user reference type: ' . $table . '.' . $column);
                }
                $invalidRange = (int) $db->query("SELECT COUNT(*) FROM `{$table}`
                    WHERE `{$column}` < 0 OR `{$column}` > 2147483647")->fetchColumn();
                if ($invalidRange) {
                    throw new RuntimeException('User reference is outside the approved signed range: ' . $table . '.' . $column);
                }
                if ($source['COLUMN_TYPE'] === 'int unsigned') {
                    $pattern = '/^  (`' . preg_quote($column, '/') . '` )int unsigned(.*),$/mi';
                    if (!preg_match($pattern, $ddl, $match)) {
                        throw new RuntimeException('Cannot preserve the live column definition: ' . $table . '.' . $column);
                    }
                    $typeChanges[$table][] = 'MODIFY COLUMN ' . $match[1] . 'int' . $match[2];
                }
            } elseif ($source['CHARACTER_SET_NAME'] !== null || $target['CHARACTER_SET_NAME'] !== null) {
                if (!str_starts_with($source['COLUMN_TYPE'], 'varchar(')
                    || !str_starts_with($target['COLUMN_TYPE'], 'varchar(')
                    || $source['CHARACTER_SET_NAME'] !== $target['CHARACTER_SET_NAME']
                    || $source['COLLATION_NAME'] !== $target['COLLATION_NAME']) {
                    throw new RuntimeException('Incompatible text reference: ' . $table . '.' . $column);
                }
            } elseif ($source['COLUMN_TYPE'] !== $target['COLUMN_TYPE']) {
                throw new RuntimeException('Incompatible numeric reference: ' . $table . '.' . $column);
            }
            $orphans = (int) $db->query("SELECT COUNT(*) FROM `{$table}` c
                LEFT JOIN `{$parent}` p ON c.`{$column}` = p.`{$parentKey}`
                WHERE c.`{$column}` IS NOT NULL AND p.`{$parentKey}` IS NULL")->fetchColumn();
            if ($orphans) {
                throw new RuntimeException('Orphan records block core integrity: ' . $table . '.' . $column);
            }
            $name = 'fk_core_' . $table . '_' . $column;
            if (strlen($name) > 64) {
                throw new RuntimeException('Core constraint name is too long.');
            }
            if (isset($existing[$name])) {
                $rows = $existing[$name];
                $row = $rows[0];
                if (count($rows) !== 1 || $row['TABLE_NAME'] !== $table || $row['COLUMN_NAME'] !== $column
                    || $row['REFERENCED_TABLE_NAME'] !== $parent || $row['REFERENCED_COLUMN_NAME'] !== $parentKey
                    || $row['DELETE_RULE'] !== $deleteRule || $row['UPDATE_RULE'] !== 'RESTRICT') {
                    throw new RuntimeException('Conflicting core constraint: ' . $name);
                }
            } else {
                foreach ($existing as $rows) {
                    foreach ($rows as $row) {
                        if ($row['TABLE_NAME'] === $table && $row['COLUMN_NAME'] === $column) {
                            throw new RuntimeException('An unplanned foreign key already uses: ' . $table . '.' . $column);
                        }
                    }
                }
                $additions[$table][] = "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`)
                    REFERENCES `{$parent}` (`{$parentKey}`) ON DELETE {$deleteRule} ON UPDATE RESTRICT";
            }
        }
    }

    if (!$typeChanges && !$additions) {
        return;
    }
    $archivePath = BASE_PATH . '/storage/backups/genpay-core-integrity-before-2026-10-08.sql';
    if (!is_file($archivePath) || !is_readable($archivePath)) {
        throw new RuntimeException('The core integrity definition export is required before DDL.');
    }
    // No foreign-key checks are disabled. Each ALTER is independently committed by MySQL.
    foreach ($typeChanges as $table => $changes) {
        $db->exec("ALTER TABLE `{$table}` " . implode(', ', $changes));
        gjc_table_columns($db, $table, true);
    }
    foreach ($additions as $table => $changes) {
        $db->exec("ALTER TABLE `{$table}` " . implode(', ', $changes));
    }
}

function gjc_ensure_merchant_application_product_types_schema(PDO $db): void
{
    if ($db->inTransaction()) {
        throw new RuntimeException('Initialize product-type schema before a transaction.');
    }
    if (!gjc_table_exists($db, 'merchant_applications')) {
        throw new RuntimeException('Merchant applications table is missing.');
    }

    $parent = $db->query(
        "SHOW COLUMNS FROM merchant_applications LIKE 'id'"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$parent || strtolower($parent['Type']) !== 'int unsigned') {
        throw new RuntimeException('Review application ID type before adding a foreign key.');
    }

    if (!gjc_table_exists($db, 'merchant_application_product_types')) {
        $db->exec("CREATE TABLE merchant_application_product_types (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            merchant_application_id INT UNSIGNED NOT NULL,
            product_type VARCHAR(120) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_map_application (merchant_application_id),
            CONSTRAINT fk_map_application
                FOREIGN KEY (merchant_application_id)
                REFERENCES merchant_applications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_general_ci");
    }

    $columns = gjc_table_columns($db, 'merchant_application_product_types', true);
    foreach (['id', 'merchant_application_id', 'product_type'] as $column) {
        if (!in_array($column, $columns, true)) {
            throw new RuntimeException('Unexpected product-type table structure.');
        }
    }

    $duplicates = $db->query(
        "SELECT 1 FROM merchant_application_product_types
         GROUP BY merchant_application_id, product_type
         HAVING COUNT(*) > 1 LIMIT 1"
    )->fetchColumn();
    if ($duplicates !== false) {
        throw new RuntimeException('Resolve duplicate product types before adding uniqueness.');
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'merchant_application_product_types'
           AND INDEX_NAME = 'uq_map_application_product_type'"
    );
    $stmt->execute();
    if (!(int) $stmt->fetchColumn()) {
        $db->exec(
            "ALTER TABLE merchant_application_product_types
             ADD UNIQUE KEY uq_map_application_product_type
                 (merchant_application_id, product_type)"
        );
    }
}

function gjc_ensure_merchant_wallet_owner_schema(PDO $db): void
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Merchant-wallet owner schema requires an explicit CLI call outside a transaction.');
    }
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Expected the ewallet database.');
    }
    foreach (['users' => 'userID', 'merchant_wallets' => 'user_id'] as $table => $column) {
        if (!gjc_table_exists($db, $table)
            || !in_array($column, gjc_table_columns($db, $table, true), true)) {
            throw new RuntimeException('Required wallet-owner table or column is missing.');
        }
    }
    $parent = $db->query("SHOW COLUMNS FROM users LIKE 'userID'")->fetch(PDO::FETCH_ASSOC);
    $child = $db->query("SHOW COLUMNS FROM merchant_wallets LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
    if ($parent['Type'] !== 'int' || $parent['Null'] !== 'NO'
        || !in_array($child['Type'], ['int', 'int unsigned'], true)
        || $child['Null'] !== 'NO') {
        throw new RuntimeException('Unexpected owner column definitions; review before changing types.');
    }
    $invalid = $db->query(
        'SELECT 1 FROM merchant_wallets w
         LEFT JOIN users u ON u.userID = w.user_id
         WHERE u.userID IS NULL OR w.user_id > 2147483647 LIMIT 1'
    )->fetchColumn();
    if ($invalid !== false) {
        throw new RuntimeException('Missing wallet owners or owner IDs outside signed INT range.');
    }
    $foreignKeys = $db->query(
        "SELECT k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                r.DELETE_RULE, r.UPDATE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
          AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          AND r.TABLE_NAME = k.TABLE_NAME
         WHERE k.TABLE_SCHEMA = DATABASE()
           AND k.TABLE_NAME = 'merchant_wallets'
           AND k.COLUMN_NAME = 'user_id'"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($foreignKeys) {
        $fk = $foreignKeys[0];
        if (count($foreignKeys) !== 1 || $child['Type'] !== 'int'
            || $fk['REFERENCED_TABLE_NAME'] !== 'users'
            || $fk['REFERENCED_COLUMN_NAME'] !== 'userID'
            || $fk['DELETE_RULE'] !== 'RESTRICT' || $fk['UPDATE_RULE'] !== 'RESTRICT') {
            throw new RuntimeException('Unexpected existing owner foreign key; no changes applied.');
        }
        return;
    }
    if ((int) $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND CONSTRAINT_NAME = 'fk_core_merchant_wallets_user_id'"
    )->fetchColumn()) {
        throw new RuntimeException('Owner foreign-key name is already in use.');
    }
    $changes = [];
    if ($child['Type'] === 'int unsigned') {
        $changes[] = "MODIFY COLUMN user_id INT NOT NULL COMMENT 'FK -> users.userID (merchant role)'";
    }
    $changes[] = 'ADD CONSTRAINT fk_core_merchant_wallets_user_id
                  FOREIGN KEY (user_id) REFERENCES users(userID)
                  ON DELETE RESTRICT ON UPDATE RESTRICT';
    $db->exec('ALTER TABLE merchant_wallets ' . implode(', ', $changes));
    gjc_table_columns($db, 'merchant_wallets', true);
}

function gjc_ensure_group_a_normalized_schema(PDO $db): void
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Group A CONTRACT requires an explicit CLI call outside a transaction.');
    }
    $targets = [
        'cart_orders' => 'items_json',
        'merchant_qr_orders' => 'items_json',
        'merchant_applications' => 'product_types',
    ];
    $hasLegacy = [];
    foreach ($targets as $table => $column) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Missing Group A table: ' . $table);
        }
        $hasLegacy[$table] = in_array($column, gjc_table_columns($db, $table, true), true);
    }
    foreach (['cart_order_items', 'qr_order_items', 'merchant_application_product_types'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Missing normalized table: ' . $table);
        }
    }

    // Validate all sources before the first DROP.
    foreach (['cart' => 'cart_orders', 'qr' => 'merchant_qr_orders'] as $source => $table) {
        $select = 'id' . ($hasLegacy[$table] ? ', items_json' : '');
        foreach ($db->query("SELECT {$select} FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actual = gjc_order_item_lines($db, $source, (int) $row['id']);
            if (!$actual) {
                throw new RuntimeException('Missing official items for ' . $table . ' #' . $row['id']);
            }
            if (!$hasLegacy[$table]) {
                continue;
            }
            $expected = gjc_validate_order_item_lines(json_decode($row['items_json'], true, 512, JSON_THROW_ON_ERROR));
            if (count($actual) !== count($expected)) {
                throw new RuntimeException('Order-item count mismatch.');
            }
            $fields = $source === 'cart'
                ? ['id', 'name', 'qty', 'price', 'sku', 'stock_qty', 'line_total']
                : ['id', 'name', 'qty', 'price'];
            foreach ($expected as $i => $line) {
                if (array_diff(array_keys($line), $fields)) {
                    throw new RuntimeException('Unmapped JSON item fields; stop before CONTRACT.');
                }
                foreach ($fields as $field) {
                    $value = $line[$field] ?? null;
                    if ($field === 'line_total' && $value === null) {
                        $value = round($line['qty'] * $line['price'], 2);
                    }
                    $saved = $actual[$i][$field] ?? null;
                    $matches = in_array($field, ['name', 'sku'], true) ? $saved === $value : $saved == $value;
                    if (!$matches) {
                        throw new RuntimeException('Order-item snapshot mismatch.');
                    }
                }
            }
        }
    }
    if ($hasLegacy['merchant_applications']) {
        foreach ($db->query('SELECT id, product_types FROM merchant_applications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $expected = array_values(array_filter(array_map('trim', explode(',', $row['product_types'])), static fn($value) => $value !== ''));
            $stmt = $db->prepare('SELECT product_type FROM merchant_application_product_types WHERE merchant_application_id = ? ORDER BY id');
            $stmt->execute([$row['id']]);
            $actual = $stmt->fetchAll(PDO::FETCH_COLUMN);
            sort($expected);
            sort($actual);
            if ($expected !== $actual) {
                throw new RuntimeException('Application product types do not match.');
            }
        }
    }

    foreach ($targets as $table => $column) {
        if ($hasLegacy[$table]) {
            $db->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            gjc_table_columns($db, $table, true);
        }
    }
}

function gjc_order_has_legacy_items_json(PDO $db, string $source): bool
{
    if (!in_array($source, ['cart', 'qr'], true)) {
        throw new InvalidArgumentException('Unknown order-item source.');
    }
    $table = $source === 'cart' ? 'cart_orders' : 'merchant_qr_orders';
    return in_array('items_json', gjc_table_columns($db, $table, true), true);
}

function gjc_ensure_order_items_schema(PDO $db): void
{
    static $ready = [];
    $key = spl_object_id($db);
    if (isset($ready[$key])) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Order-item schema must be initialized before a transaction.');
    }
    foreach (['cart_orders', 'merchant_qr_orders', 'merchant_inventory', 'transactions'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Order-item parent table is missing: ' . $table);
        }
    }
    $db->exec("CREATE TABLE IF NOT EXISTS cart_order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        cart_order_id INT UNSIGNED NOT NULL,
        line_no INT UNSIGNED NOT NULL,
        original_inventory_id INT UNSIGNED NOT NULL,
        merchant_inventory_id INT UNSIGNED NULL,
        item_name VARCHAR(150) NOT NULL,
        qty INT UNSIGNED NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        sku VARCHAR(50) NULL,
        stock_qty_snapshot INT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cart_item_line (cart_order_id, line_no),
        KEY idx_cart_item_inventory (merchant_inventory_id),
        CONSTRAINT fk_cart_item_order FOREIGN KEY (cart_order_id) REFERENCES cart_orders(id)
            ON DELETE CASCADE ON UPDATE RESTRICT,
        CONSTRAINT fk_cart_item_inventory FOREIGN KEY (merchant_inventory_id) REFERENCES merchant_inventory(id)
            ON DELETE SET NULL ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS qr_order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        merchant_qr_order_id INT UNSIGNED NOT NULL,
        merchant_inventory_id INT UNSIGNED NULL,
        item_name VARCHAR(150) NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id),
        KEY idx_qoi_order (merchant_qr_order_id),
        KEY idx_qoi_inventory (merchant_inventory_id),
        CONSTRAINT fk_qoi_inventory FOREIGN KEY (merchant_inventory_id) REFERENCES merchant_inventory(id) ON DELETE SET NULL,
        CONSTRAINT fk_qoi_order FOREIGN KEY (merchant_qr_order_id) REFERENCES merchant_qr_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS transaction_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_id BIGINT UNSIGNED NOT NULL,
        item_name VARCHAR(150) NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL,
        subtotal DECIMAL(10,2) GENERATED ALWAYS AS (ROUND(quantity * unit_price, 2)) STORED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_txn_items_transaction (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    foreach (['qr_order_items' => ['original_inventory_id' => 'INT UNSIGNED NULL', 'line_no' => 'INT UNSIGNED NULL'],
              'transaction_items' => ['line_no' => 'INT UNSIGNED NULL']] as $table => $adds) {
        $columns = gjc_table_columns($db, $table, true);
        foreach ($adds as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }
    foreach (['qr_order_items' => ['uq_qr_item_line', 'merchant_qr_order_id'],
              'transaction_items' => ['uq_transaction_item_line', 'transaction_id']] as $table => [$index, $parent]) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        if (!(int) $stmt->fetchColumn()) {
            $db->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` (`{$parent}`, line_no)");
        }
    }
    $ready[$key] = true;
}

/** Validate recorded item facts without consulting today's prices. */
function gjc_validate_order_item_lines(array $lines): array
{
    if (!$lines || !array_is_list($lines)) {
        throw new RuntimeException('An order must have a nonempty list of items.');
    }
    foreach ($lines as &$line) {
        if (!is_array($line) || !isset($line['id'], $line['qty'], $line['name'], $line['price'])
            || !is_numeric($line['id']) || (float) $line['id'] !== (float) (int) $line['id'] || (int) $line['id'] <= 0
            || !is_numeric($line['qty']) || (float) $line['qty'] !== (float) (int) $line['qty'] || (int) $line['qty'] <= 0
            || !is_string($line['name']) || trim($line['name']) === '' || mb_strlen($line['name']) > 150
            || !is_numeric($line['price']) || !is_finite((float) $line['price'])
            || (float) $line['price'] < 0 || (float) $line['price'] > 99999999.99
            || abs((float) $line['price'] - round((float) $line['price'], 2)) > 0.000001) {
            throw new RuntimeException('Invalid recorded order item.');
        }
        $line['id'] = (int) $line['id'];
        $line['qty'] = (int) $line['qty'];
        $line['price'] = round((float) $line['price'], 2);
        $subtotal = round($line['qty'] * $line['price'], 2);
        if ($subtotal > 99999999.99 || (isset($line['line_total']) && abs((float) $line['line_total'] - $subtotal) > 0.000001)
            || (isset($line['sku']) && (!is_string($line['sku']) || mb_strlen($line['sku']) > 50))) {
            throw new RuntimeException('Invalid recorded item subtotal or SKU.');
        }
    }
    unset($line);
    return $lines;
}

function gjc_order_item_total(array $lines): float
{
    $cents = 0;
    foreach ($lines as $line) {
        $cents += (int) round((float) $line['price'] * 100) * (int) $line['qty'];
    }
    return $cents / 100;
}

function gjc_order_item_lines(PDO $db, string $source, int $orderId): array
{
    if (!in_array($source, ['cart', 'qr'], true)) {
        throw new InvalidArgumentException('Unknown order-item source.');
    }
    $table = $source === 'cart' ? 'cart_order_items' : 'qr_order_items';
    $parent = $source === 'cart' ? 'cart_order_id' : 'merchant_qr_order_id';
    $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE `{$parent}` = ? ORDER BY line_no, id");
    $stmt->execute([$orderId]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['line_no'] === null || (int) $row['line_no'] !== count($lines) + 1 || $row['original_inventory_id'] === null) {
            throw new RuntimeException('Order-item backfill is incomplete.');
        }
        $line = ['id' => (int) $row['original_inventory_id'], 'name' => $row['item_name'],
                 'qty' => (int) $row['qty'], 'price' => (float) $row['unit_price']];
        if ($source === 'cart') {
            $line['sku'] = $row['sku'];
            $line['stock_qty'] = $row['stock_qty_snapshot'] === null ? null : (int) $row['stock_qty_snapshot'];
            $line['line_total'] = round($line['qty'] * $line['price'], 2);
        }
        $lines[] = $line;
    }
    if ($lines) {
        $lines = gjc_validate_order_item_lines($lines);
        $orders = $source === 'cart' ? 'cart_orders' : 'merchant_qr_orders';
        $stmt = $db->prepare("SELECT amount FROM `{$orders}` WHERE id = ?");
        $stmt->execute([$orderId]);
        $amount = $stmt->fetchColumn();
        if ($amount === false || abs(gjc_order_item_total($lines) - (float) $amount) > 0.000001) {
            throw new RuntimeException('Official items do not match the recorded order amount.');
        }
    }
    return $lines;
}

/** Caller owns the order/child transaction; JSON is never read here. */
function gjc_store_order_items(PDO $db, string $source, int $orderId, array $lines): void
{
    if (!$db->inTransaction() || !in_array($source, ['cart', 'qr'], true)) {
        throw new RuntimeException('Order items require a valid source and an open transaction.');
    }
    $lines = gjc_validate_order_item_lines($lines);
    $orders = $source === 'cart' ? 'cart_orders' : 'merchant_qr_orders';
    $stmt = $db->prepare("SELECT
        (SELECT mw.user_id FROM merchant_wallets mw WHERE mw.id = o.merchant_wallet_id) AS merchant_user_id,
        o.amount FROM `{$orders}` o WHERE o.id = ? FOR UPDATE");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || abs(gjc_order_item_total($lines) - (float) $order['amount']) > 0.000001) {
        throw new RuntimeException('Order items do not match the recorded order amount.');
    }
    if (gjc_order_item_lines($db, $source, $orderId)) {
        throw new RuntimeException('Official order items already exist; refusing to overwrite them.');
    }
    foreach ($lines as $i => $line) {
        $inventory = $db->prepare('SELECT merchant_user_id FROM merchant_inventory WHERE id = ?');
        $inventory->execute([$line['id']]);
        $owner = $inventory->fetchColumn();
        if ($owner !== false && (int) $owner !== (int) $order['merchant_user_id']) {
            throw new RuntimeException('Order item belongs to a different merchant.');
        }
        $link = $owner === false ? null : $line['id'];
        if ($source === 'cart') {
            $db->prepare('INSERT INTO cart_order_items (cart_order_id, line_no, original_inventory_id, merchant_inventory_id, item_name, qty, unit_price, sku, stock_qty_snapshot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$orderId, $i + 1, $line['id'], $link, $line['name'], $line['qty'], $line['price'], $line['sku'] ?? null, $line['stock_qty'] ?? null]);
        } else {
            $db->prepare('INSERT INTO qr_order_items (merchant_qr_order_id, line_no, original_inventory_id, merchant_inventory_id, item_name, qty, unit_price) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$orderId, $i + 1, $line['id'], $link, $line['name'], $line['qty'], $line['price']]);
        }
    }
}

function gjc_store_transaction_items(PDO $db, int $transactionId, array $lines): void
{
    if (!$db->inTransaction()) {
        throw new RuntimeException('Receipt items require an open transaction.');
    }
    $lines = gjc_validate_order_item_lines($lines);
    $stmt = $db->prepare('SELECT amount FROM transactions WHERE id = ? FOR UPDATE');
    $stmt->execute([$transactionId]);
    $amount = $stmt->fetchColumn();
    if ($amount === false || abs(gjc_order_item_total($lines) - (float) $amount) > 0.000001) {
        throw new RuntimeException('Receipt items do not match the recorded payment amount.');
    }
    $stmt = $db->prepare('SELECT item_name, quantity, unit_price, subtotal, line_no FROM transaction_items WHERE transaction_id = ? ORDER BY line_no, id');
    $stmt->execute([$transactionId]);
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($existing) {
        if (count($existing) !== count($lines)) {
            throw new RuntimeException('Conflicting existing receipt items.');
        }
        foreach ($existing as $i => $row) {
            $line = $lines[$i];
            if ((int) $row['line_no'] !== $i + 1 || $row['item_name'] !== $line['name']
                || (int) $row['quantity'] !== $line['qty'] || (float) $row['unit_price'] !== $line['price']
                || (float) $row['subtotal'] !== round($line['qty'] * $line['price'], 2)) {
                throw new RuntimeException('Conflicting existing receipt items.');
            }
        }
        return;
    }
    // subtotal is derived (quantity x unit_price). Once it is a generated column
    // MySQL computes it; until then it is still written here.
    $writeSubtotal = !gjc_transaction_items_subtotal_is_generated($db);
    $insert = $db->prepare($writeSubtotal
        ? 'INSERT INTO transaction_items (transaction_id, line_no, item_name, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)'
        : 'INSERT INTO transaction_items (transaction_id, line_no, item_name, quantity, unit_price) VALUES (?, ?, ?, ?, ?)');
    foreach ($lines as $i => $line) {
        $insert->execute(array_merge(
            [$transactionId, $i + 1, $line['name'], $line['qty'], $line['price']],
            $writeSubtotal ? [round($line['qty'] * $line['price'], 2)] : []
        ));
    }
}

/**
 * True once transaction_items.subtotal is a generated column (3NF contract).
 * SHOW COLUMNS, not information_schema, so a TEMPORARY test table is honoured.
 */
function gjc_transaction_items_subtotal_is_generated(PDO $db): bool
{
    $col = $db->query("SHOW COLUMNS FROM transaction_items LIKE 'subtotal'")->fetch(PDO::FETCH_ASSOC);
    return $col !== false && stripos((string) ($col['Extra'] ?? $col['EXTRA'] ?? ''), 'GENERATED') !== false;
}

/** Explicit, atomic historical backfill; refuses to replace conflicting facts. */
function gjc_backfill_order_items(PDO $db): void
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Order-item backfill requires an explicit CLI call outside a transaction.');
    }
    foreach (['cart_orders', 'merchant_qr_orders'] as $table) {
        if (!in_array('items_json', gjc_table_columns($db, $table, true), true)) {
            throw new RuntimeException('Legacy JSON backfill is unavailable after Group A CONTRACT.');
        }
    }
    gjc_ensure_order_items_schema($db);
    $db->beginTransaction();
    try {
        $plans = [];
        foreach (['cart' => 'cart_orders', 'qr' => 'merchant_qr_orders'] as $source => $table) {
            foreach ($db->query("SELECT id, items_json, amount, status, paid_ref FROM `{$table}` ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC) as $order) {
                $decoded = json_decode($order['items_json'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Invalid historical item snapshot.');
                }
                $lines = gjc_validate_order_item_lines($decoded);
                if (abs(gjc_order_item_total($lines) - (float) $order['amount']) > 0.000001) {
                    throw new RuntimeException('Historical order total mismatch.');
                }
                $plans[] = [$source, $order, $lines];
            }
        }
        foreach ($plans as [$source, $order, $lines]) {
            $orderId = (int) $order['id'];
            if ($source === 'qr') {
                $stmt = $db->prepare('SELECT * FROM qr_order_items WHERE merchant_qr_order_id = ? ORDER BY id FOR UPDATE');
                $stmt->execute([$orderId]);
                $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($existing) {
                    foreach ($lines as $i => $line) {
                        $match = null;
                        foreach ($existing as $j => $row) {
                            if ($row['item_name'] === $line['name'] && (int) $row['qty'] === $line['qty']
                                && (float) $row['unit_price'] === $line['price']
                                && ($row['merchant_inventory_id'] === null || (int) $row['merchant_inventory_id'] === $line['id'])
                                && ($row['original_inventory_id'] === null || (int) $row['original_inventory_id'] === $line['id'])
                                && ($row['line_no'] === null || (int) $row['line_no'] === $i + 1)) {
                                $match = $j;
                                break;
                            }
                        }
                        if ($match === null) {
                            throw new RuntimeException('Conflicting existing QR items for order ' . $orderId);
                        }
                        $row = $existing[$match];
                        if ($row['original_inventory_id'] === null || $row['line_no'] === null) {
                            $db->prepare('UPDATE qr_order_items SET original_inventory_id = ?, line_no = ? WHERE id = ?')
                                ->execute([$line['id'], $i + 1, $row['id']]);
                        }
                        unset($existing[$match]);
                    }
                    if ($existing) {
                        throw new RuntimeException('Unexpected extra QR items for order ' . $orderId);
                    }
                } else {
                    gjc_store_order_items($db, $source, $orderId, $lines);
                }
            } else {
                $existing = gjc_order_item_lines($db, $source, $orderId);
                if ($existing) {
                    if (count($existing) !== count($lines)) {
                        throw new RuntimeException('Conflicting existing cart items.');
                    }
                    foreach ($lines as $i => $line) {
                        $saved = $existing[$i];
                        foreach (['id', 'name', 'qty', 'price', 'sku', 'stock_qty', 'line_total'] as $field) {
                            if (($saved[$field] ?? null) != ($line[$field] ?? null)) {
                                throw new RuntimeException('Conflicting existing cart items.');
                            }
                        }
                    }
                } else {
                    gjc_store_order_items($db, $source, $orderId, $lines);
                }
            }
            if ($order['status'] === 'paid') {
                $stmt = $db->prepare('SELECT id, amount FROM transactions WHERE reference_no = ?');
                $stmt->execute([$order['paid_ref']]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment || abs((float) $payment['amount'] - (float) $order['amount']) > 0.000001) {
                    throw new RuntimeException('Historical order has no matching payment.');
                }
                gjc_store_transaction_items($db, (int) $payment['id'], $lines);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function gjc_student_wallet(PDO $db, int $userId): array
{
    if ($userId && gjc_table_exists($db, "student_wallets")) {
        $stmt = $db->prepare(
            "SELECT * FROM student_wallets WHERE user_id = ? LIMIT 1",
        );
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wallet) {
            // New wallets inherit the configured default spend cap (0 = none).
            // Parents can still override it per student afterwards.
            $defaultCap = gjc_setting($db, 'wallet_default_spend_cap');
            try {
                $db->prepare(
                    "INSERT IGNORE INTO student_wallets (user_id, balance, daily_spend_limit)
                     VALUES (?, 0, ?)",
                )->execute([$userId, $defaultCap]);
            } catch (Throwable) {
                // Older schema without daily_spend_limit — fall back.
                $db->prepare(
                    "INSERT IGNORE INTO student_wallets (user_id, balance) VALUES (?, 0)",
                )->execute([$userId]);
            }
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($wallet) {
            return [
                "id" => (int) $wallet["id"],
                "balance" => (float) $wallet["balance"],
                "source" => "student_wallets",
            ];
        }
    }

    return ["id" => 0, "balance" => 0.0, "source" => "none"];
}

function gjc_merchant_wallet(PDO $db, int $userId): array
{
    if ($userId && gjc_table_exists($db, "merchant_wallets")) {
        $stmt = $db->prepare(
            "SELECT * FROM merchant_wallets WHERE user_id = ? LIMIT 1",
        );
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wallet) {
            $db->prepare(
                "INSERT IGNORE INTO merchant_wallets (user_id, balance) VALUES (?, 0)",
            )->execute([$userId]);
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($wallet) {
            return [
                "id" => (int) $wallet["id"],
                "balance" => (float) $wallet["balance"],
                "source" => "merchant_wallets",
            ];
        }
    }

    return ["id" => 0, "balance" => 0.0, "source" => "none"];
}

/**
 * Staff-operated sales a stall hasn't remitted yet, since the wallet's last
 * remittance today, or since midnight if it hasn't remitted at all today.
 * This is what the Remit Sales button submits, and what the merchant
 * dashboard shows as the pending amount waiting to be remitted — see
 * gjc_merchant_remitted_sales_today() for the confirmed "Today's Sales"
 * figure, and gjc_ensure_sales_remittances_schema() for the table.
 */
function gjc_merchant_unremitted_sales(PDO $db, int $walletId): array
{
    $since = date("Y-m-d 00:00:00");
    $amount = 0.0;
    $count = 0;

    if ($walletId > 0 && gjc_table_exists($db, "sales_remittances")) {
        $stmt = $db->prepare(
            "SELECT period_end FROM sales_remittances
              WHERE merchant_wallet_id = ? AND DATE(period_end) = CURDATE()
              ORDER BY period_end DESC LIMIT 1",
        );
        $stmt->execute([$walletId]);
        $lastRemit = $stmt->fetchColumn();
        if ($lastRemit) {
            $since = $lastRemit;
        }
    }

    if ($walletId > 0 && gjc_table_exists($db, "transactions")) {
        $earningTypes = [
            CirculationEngine::TXN_PAYMENT,
            CirculationEngine::TXN_VOUCHER_PAYMENT,
        ];
        $placeholders = implode(", ", array_fill(0, count($earningTypes), "?"));
        $staffSale = gjc_merchant_staff_sale_sql($db);
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0), COUNT(*) FROM transactions t
              WHERE merchant_wallet_id = ?
                AND transaction_type IN ({$placeholders})
                AND status = 'completed' AND {$staffSale}
                AND created_at > ?",
        );
        $stmt->execute(array_merge([$walletId], $earningTypes, [$since]));
        [$amount, $count] = $stmt->fetch(PDO::FETCH_NUM);
    }

    return ["amount" => (float) $amount, "count" => (int) $count, "since" => $since];
}

/**
 * Today's completed sales: direct/admin sales count immediately, while
 * staff-operated sales count after remittance. Each ledger row counts once.
 */
function gjc_merchant_remitted_sales_today(PDO $db, int $walletId): array
{
    $amount = 0.0;
    $count = 0;
    $periodStart = null;
    $periodEnd = null;

    if ($walletId > 0 && gjc_table_exists($db, "transactions")) {
        $confirmed = gjc_merchant_confirmed_sale_sql($db);
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(t.amount), 0), COUNT(*), MIN(t.created_at), MAX(t.created_at)
               FROM transactions t
              WHERE t.merchant_wallet_id = ? AND DATE(t.created_at) = CURDATE()
                AND t.transaction_type IN (?, ?) AND t.status = 'completed' AND {$confirmed}",
        );
        $stmt->execute([$walletId, CirculationEngine::TXN_PAYMENT, CirculationEngine::TXN_VOUCHER_PAYMENT]);
        [$amount, $count, $periodStart, $periodEnd] = $stmt->fetch(PDO::FETCH_NUM);
    }

    return [
        "amount" => (float) $amount,
        "count" => (int) $count,
        "period_start" => $periodStart,
        "period_end" => $periodEnd,
    ];
}

/** Identify the register operator separately from the student/parent payer. */
function gjc_merchant_staff_sale_sql(PDO $db): string
{
    $operator = 't.initiated_by';
    if (gjc_table_exists($db, 'merchant_qr_orders')
        && in_array('created_by', gjc_table_columns($db, 'merchant_qr_orders'), true)) {
        $operator = "COALESCE((SELECT q.created_by FROM merchant_qr_orders q
            WHERE q.merchant_wallet_id = t.merchant_wallet_id AND q.paid_ref = t.reference_no
            LIMIT 1), t.initiated_by)";
    }
    return "EXISTS (SELECT 1 FROM users operator_user
        JOIN merchant_wallets operator_wallet ON operator_wallet.id = t.merchant_wallet_id
        WHERE operator_user.userID = {$operator}
          AND operator_user.userID <> operator_wallet.user_id
          AND operator_user.merchant_owner_id = operator_wallet.user_id)";
}

/** Direct sales count immediately; staff sales count after remittance, once each. */
function gjc_merchant_confirmed_sale_sql(PDO $db): string
{
    $staffSale = gjc_merchant_staff_sale_sql($db);
    return "(NOT ({$staffSale}) OR EXISTS (SELECT 1 FROM sales_remittances r
        WHERE r.merchant_wallet_id = t.merchant_wallet_id AND DATE(r.period_end) = CURDATE()
          AND t.created_at > r.period_start AND t.created_at <= r.period_end))";
}

function gjc_merchant_owner_id(PDO $db, ?int $userId = null): int
{
    $userId = $userId ?: gjc_user_id();
    if (!$userId) {
        return 0;
    }

    if (!gjc_is_merchant_staff()) {
        return $userId;
    }

    $sessionOwnerId = (int) ($_SESSION["merchant_owner_id"] ?? 0);
    if ($sessionOwnerId > 0) {
        return $sessionOwnerId;
    }

    if (!gjc_table_exists($db, "users")) {
        return $userId;
    }

    $idColumn = gjc_column($db, "users", ["id", "userID"]);
    if (!$idColumn || !in_array("merchant_owner_id", gjc_table_columns($db, "users"), true)) {
        return $userId;
    }

    $stmt = $db->prepare("SELECT merchant_owner_id FROM users WHERE {$idColumn} = ? LIMIT 1");
    $stmt->execute([$userId]);
    $ownerId = (int) $stmt->fetchColumn();

    if ($ownerId > 0) {
        $_SESSION["merchant_owner_id"] = $ownerId;
        return $ownerId;
    }

    return $userId;
}

function gjc_reference(string $prefix): string
{
    return $prefix .
        "-" .
        date("Ymd") .
        "-" .
        strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Session-scoped CSRF token for state-changing fetch endpoints (Scan & Pay
 * payment/validation and POS QR creation). Pages embed gjc_csrf_token() and
 * send it back as an X-CSRF-Token header or csrf_token form field;
 * gjc_csrf_verify() accepts either.
 */
function gjc_csrf_token(): string
{
    if (empty($_SESSION["gjc_csrf_token"])) {
        $_SESSION["gjc_csrf_token"] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION["gjc_csrf_token"];
}

function gjc_csrf_verify(?string $token = null): bool
{
    $known = (string) ($_SESSION["gjc_csrf_token"] ?? "");
    if ($known === "") {
        return false;
    }

    $given = $token
        ?? $_SERVER["HTTP_X_CSRF_TOKEN"]
        ?? $_POST["csrf_token"]
        ?? "";

    return is_string($given) && $given !== "" && hash_equals($known, $given);
}

/** Fixed bookable times for stall application Step 2 meetings - one applicant per slot. */
function gjc_meeting_time_slots(): array
{
    // One-hour meeting slots, 9:00 AM – 5:00 PM with a noon lunch break.
    // The last slot starts at 4:00 PM so no meeting runs past the 5:00 PM close.
    return ['09:00','10:00','11:00','13:00','14:00','15:00','16:00'];
}

/**
 * Holiday calendar (admin-managed) and default meeting location, used by the
 * auto-scheduler so it never books a weekend/holiday and always has a place
 * to put on the meeting email when no admin is filling the form by hand.
 */
function gjc_ensure_meeting_scheduling_schema(PDO $db): void
{
    if(gjc_table_exists($db,'meeting_holidays')&&gjc_table_exists($db,'meeting_settings')
        &&in_array('down_payment_default_amount',gjc_table_columns($db,'meeting_settings'),true)
        &&$db->query('SELECT id FROM meeting_settings WHERE id=1')->fetchColumn()!==false)return;
    if($db->inTransaction())throw new RuntimeException('Meeting schema setup must precede a transaction.');
    $db->exec(
        "CREATE TABLE IF NOT EXISTS meeting_holidays (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            holiday_date DATE NOT NULL,
            name         VARCHAR(150) NOT NULL,
            created_by   INT UNSIGNED NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mh_date (holiday_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS meeting_settings (
            id                           TINYINT UNSIGNED NOT NULL,
            default_location             VARCHAR(255) NOT NULL DEFAULT 'GJC Finance Office',
            down_payment_default_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            updated_by                   INT UNSIGNED NULL,
            updated_at                   DATETIME NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );
    $db->exec("INSERT IGNORE INTO meeting_settings (id, default_location) VALUES (1, 'GJC Finance Office')");

    // One-time: add the down_payment_default_amount column to existing installs.
    try {
        $db->exec("ALTER TABLE meeting_settings ADD COLUMN down_payment_default_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    } catch (\Throwable $ignored) {
    }

}

/**
 * Shared "last checked" timestamp per merchant card on the finance dashboard.
 * The notification badge counts audited merchant management actions newer than
 * this; opening the stall detail view stamps it, clearing the badge for every
 * finance admin (one team, one read state).
 */
function gjc_ensure_merchant_card_views_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS merchant_card_views (
            merchant_id     INT NOT NULL,
            last_viewed_at  DATETIME NOT NULL,
            viewed_by       INT NULL,
            PRIMARY KEY (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );
}

/** Holiday dates the auto-scheduler must skip, as a Y-m-d => name map. */
function gjc_meeting_holiday_dates(PDO $db): array
{
    $rows = $db->query("SELECT holiday_date, name FROM meeting_holidays ORDER BY holiday_date ASC")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows;
}

function gjc_meeting_default_location(PDO $db): string
{
    $location = $db->query("SELECT default_location FROM meeting_settings WHERE id = 1")->fetchColumn();
    return $location !== false && $location !== '' ? $location : 'GJC Finance Office';
}

function gjc_wallet_user_stats(PDO $db): array
{
    $days = 30;
    $row  = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN last_txn >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS active_count
         FROM (
             SELECT u.userID, MAX(t.created_at) AS last_txn
             FROM users u
             LEFT JOIN student_wallets sw ON sw.user_id = u.userID
             LEFT JOIN transactions t ON t.student_wallet_id = sw.id
             WHERE u.roleID = 1
             GROUP BY u.userID
         ) sub"
    );
    $row->execute([$days]);
    $data           = $row->fetch(PDO::FETCH_ASSOC);
    $total          = (int) ($data['total']        ?? 0);
    $active         = (int) ($data['active_count'] ?? 0);
    return [
        'total'    => $total,
        'active'   => $active,
        'inactive' => $total - $active,
        'days'     => $days,
    ];
}

function gjc_merchant_wallet_user_stats(PDO $db): array
{
    $days = 30;
    $row  = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN last_txn >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS active_count
         FROM (
             SELECT mw.id, MAX(t.created_at) AS last_txn
             FROM merchant_wallets mw
             LEFT JOIN transactions t ON t.merchant_wallet_id = mw.id
             GROUP BY mw.id
         ) sub"
    );
    $row->execute([$days]);
    $data   = $row->fetch(PDO::FETCH_ASSOC);
    $total  = (int) ($data['total']        ?? 0);
    $active = (int) ($data['active_count'] ?? 0);
    return [
        'total'    => $total,
        'active'   => $active,
        'inactive' => $total - $active,
        'days'     => $days,
    ];
}

/**
 * Lists every wallet user (students + merchants) with name, type, current
 * balance, and last-activity date. Powers the Total Wallet Users drill-in
 * table on the economy page. Mirrors the 30-day "active" rule used by
 * gjc_wallet_user_stats() / gjc_merchant_wallet_user_stats().
 */
function gjc_wallet_users_list(PDO $db): array
{
    // Definitions mirror the economy pool cards: a "student wallet user" is any
    // student-role user (wallet row optional), a "merchant wallet user" is any
    // merchant_wallets row. This keeps the combined card count == the two pool
    // cards == this table's row count.
    $sql = "
        SELECT
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), '(unknown)') AS name,
            'Student' AS type,
            COALESCE(sw.balance, 0) AS balance,
            (SELECT MAX(t.created_at) FROM transactions t
              WHERE t.student_wallet_id = sw.id) AS last_txn
        FROM users u
        LEFT JOIN student_wallets sw ON sw.user_id = u.userID
        WHERE u.roleID = 1
        UNION ALL
        SELECT
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), '(unknown)') AS name,
            'Merchant' AS type,
            mw.balance AS balance,
            (SELECT MAX(t.created_at) FROM transactions t
              WHERE t.merchant_wallet_id = mw.id) AS last_txn
        FROM merchant_wallets mw
        LEFT JOIN users u ON u.userID = mw.user_id
        ORDER BY name ASC
    ";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $cutoff = strtotime('-30 days');
    foreach ($rows as &$r) {
        $r['active'] = !empty($r['last_txn']) && strtotime($r['last_txn']) >= $cutoff;
    }
    unset($r);
    return $rows;
}

/**
 * Pure slot-finding core — no database, no clock, so it can be unit-tested
 * against the edge cases (Friday submission → Monday, full day rolls over,
 * weekend submission → Monday, day-before-holiday skip, etc.).
 *
 * Next-business-day model: a meeting is never booked the same day as
 * submission. Starting from the day AFTER `$now`, roll forward through business
 * days (skipping weekends and $holidays) and return the first free slot.
 *
 * @param DateTimeImmutable $now            The reference "now".
 * @param callable          $bookedProvider fn(string $ymd): string[] of taken 'H:i' times.
 * @param array             $holidays       Map of 'Y-m-d' => name to skip.
 * @param string[]          $slots          Ordered 'H:i' slot start times.
 * @return array{date:string,time:string}|null
 */
function gjc_compute_next_meeting_slot(
    DateTimeImmutable $now,
    callable $bookedProvider,
    array $holidays,
    array $slots,
    int $lookaheadDays = 180
): ?array {
    // Never same-day: the earliest a meeting can land is the day after
    // submission. Scan from tomorrow, rolling past weekends/holidays/full days.
    $cursor = $now->setTime(0, 0, 0)->modify('+1 day');

    for ($i = 0; $i < $lookaheadDays; $i++) {
        $dateStr   = $cursor->format('Y-m-d');
        $dayOfWeek = (int) $cursor->format('N'); // 6 = Saturday, 7 = Sunday

        if ($dayOfWeek < 6 && !isset($holidays[$dateStr])) {
            $booked = array_flip($bookedProvider($dateStr));
            foreach ($slots as $slot) {
                if (isset($booked[$slot])) {
                    continue;
                }
                return ['date' => $dateStr, 'time' => $slot];
            }
        }

        $cursor = $cursor->modify('+1 day');
    }

    return null;
}

/**
 * Finds the earliest open meeting slot from now, skipping weekends,
 * admin-defined holidays, and times already held by another live application.
 * A slot is "held" by any application with a meeting date on that day whose
 * status is not cancelled/expired/rejected — so cancellations free their slot.
 * Returns ['date' => 'Y-m-d', 'time' => 'H:i'] or null if nothing is free
 * within the lookahead window.
 */
function gjc_find_next_meeting_slot(PDO $db, int $lookaheadDays = 180): ?array
{
    $holidays = gjc_meeting_holiday_dates($db);
    $slots    = gjc_meeting_time_slots();

    $bookedStmt = $db->prepare(
        "SELECT TIME_FORMAT(meetup_scheduled_at, '%H:%i') AS t
           FROM " . gjc_stall_application_source($db) . "
          WHERE meetup_scheduled_at IS NOT NULL
            AND status NOT IN ('cancelled', 'expired', 'rejected')
            AND DATE(meetup_scheduled_at) = ?"
    );
    $provider = static function (string $date) use ($bookedStmt): array {
        $bookedStmt->execute([$date]);
        return $bookedStmt->fetchAll(PDO::FETCH_COLUMN);
    };

    return gjc_compute_next_meeting_slot(
        new DateTimeImmutable('now'),
        $provider,
        $holidays,
        $slots,
        $lookaheadDays
    );
}

/**
 * Atomically assign the next free meeting slot to an application and persist it.
 *
 * Concurrency guard: a named advisory lock (GET_LOCK) serialises the
 * find-then-write critical section so two near-simultaneous submissions can
 * never be handed the same slot — the second caller sees the first's committed
 * assignment and rolls to the next free slot.
 *
 * MUST be called outside an open transaction so the UPDATE autocommits and is
 * visible to the next lock holder before the lock is released.
 *
 * @return array{date:string,time:string,datetime:string,location:string}|null
 */
function gjc_assign_meeting_slot(PDO $db, int $appId, ?string $location = null): ?array
{
    if($db->inTransaction())throw new RuntimeException('Meeting assignment must own its transaction.');
    $location=$location??gjc_meeting_default_location($db);
    if((int)$db->query("SELECT GET_LOCK('gjc_stall_scheduler',10)")->fetchColumn()!==1)throw new RuntimeException('Meeting scheduler is busy.');
    try{return gjc_stall_application_write($db,function()use($db,$appId,$location):?array{
        $slot=gjc_find_next_meeting_slot($db);if($slot===null)return null;
        $scheduledAt=$slot['date'].' '.$slot['time'].':00';
        if(!gjc_stall_application_update($db,$appId,['meetup_scheduled_at'=>$scheduledAt,'meetup_location'=>$location],['status'=>'pending_verification']))return null;
        return ['date'=>$slot['date'],'time'=>$slot['time'],'datetime'=>$scheduledAt,'location'=>$location];
    });}finally{$db->query("SELECT RELEASE_LOCK('gjc_stall_scheduler')")->fetchColumn();}
}

function gjc_transaction_type_options(): array
{
    return [
        "" => "All Types",
        "payment" => "Payment",
        "topup" => "Top-up",
        "encashment" => "Encashment",
        "voucher_payment" => "Voucher Payment",
        "voucher_create" => "Voucher Creation",
        "voucher_expire" => "Voucher Expiry",
        "cap_increase" => "Cap Increase",
        "refund" => "Refund",
        "allowance" => "Allowance",
        "fee_waiver_claim" => "Fee Waiver Claim",
    ];
}

function gjc_transaction_status_options(): array
{
    return [
        "" => "All Status",
        "completed" => "Completed",
        "approved" => "Approved",
        "released" => "Released",
        "pending" => "Pending",
        "processing" => "Processing",
        "failed" => "Failed",
        "rejected" => "Rejected",
        "reversed" => "Reversed",
    ];
}

function gjc_transaction_type_label(string $type): string
{
    $labels = [
        "cash_in" => "Top-up",
        "payment" => "Payment",
        "voucher_payment" => "Voucher Payment",
        "merchant_settle" => "Encashment",
        "voucher_create" => "Voucher Creation",
        "voucher_expire" => "Voucher Expiry",
        "cap_increase" => "Cap Increase",
        "refund" => "Refund",
        "topup" => "Top-up",
        "encashment" => "Encashment",
        "allowance" => "Allowance",
        "fee_waiver_claim" => "Fee Waiver Claim",
    ];

    return $labels[$type] ?? ucwords(str_replace("_", " ", $type));
}

function gjc_transaction_type_slug(string $type): string
{
    $map = [
        "cash_in" => "topup",
        "merchant_settle" => "encashment",
    ];

    return $map[$type] ?? strtolower($type);
}

// Icon / colour-slug / direction metadata for a student-facing transaction
// row. Shared by student/dashboard.php and student/api/wallet_summary.php so
// the server-rendered list and the AJAX-refreshed list can't drift apart.
function gjc_student_txn_meta(string $type): array
{
    $slugs = [
        "cash_in" => "topup",
        "topup" => "topup",
        "top_up" => "topup",
        "refund" => "topup",
        "payment" => "payment",
        "voucher_payment" => "voucher",
        "p2p_transfer" => "transfer",
        "allowance" => "allowance",
        "fee_waiver_claim" => "fee_waiver",
    ];
    $icons = [
        "topup" => "fa-circle-plus",
        "payment" => "fa-bag-shopping",
        "voucher" => "fa-credit-card",
        "transfer" => "fa-paper-plane",
        "allowance" => "fa-hand-holding-dollar",
        "fee_waiver" => "fa-hand-holding-dollar",
        "other" => "fa-receipt",
    ];
    $slug = $slugs[$type] ?? "other";

    return [
        "slug" => $slug,
        "icon" => $icons[$slug],
        "incoming" => in_array($slug, ["topup", "allowance", "fee_waiver"], true),
        "label" => gjc_transaction_type_label($type),
    ];
}

function gjc_transaction_status_label(string $status): string
{
    return ucwords(str_replace("_", " ", $status));
}

function gjc_transaction_status_slug(string $status): string
{
    return strtolower(str_replace([" ", "_"], "-", $status));
}

function gjc_transaction_is_pending(string $status): bool
{
    return in_array(strtolower($status), ["pending", "processing"], true);
}

function gjc_transaction_is_success(string $status): bool
{
    return in_array(
        strtolower($status),
        ["completed", "approved", "released"],
        true,
    );
}

/**
 * Line items behind a paid order, keyed by the transaction's reference_no.
 *
 * Receipt rows are authoritative for paid items. Relational order rows are
 * the fallback for an order-linked payment; historical JSON is never read.
 *
 * Returns a normalised list of ['name', 'qty', 'price', 'line_total'], or []
 * for a transaction with no order behind it (top-ups, transfers, encashments).
 */
function gjc_transaction_line_items(PDO $db, string $referenceNo): array
{
    $referenceNo = trim($referenceNo);
    if ($referenceNo === "") {
        return [];
    }

    gjc_ensure_order_items_schema($db);
    $stmt = $db->prepare('SELECT i.item_name, i.quantity, i.unit_price, i.subtotal, i.line_no, t.amount AS recorded_amount
        FROM transaction_items i JOIN transactions t ON t.id = i.transaction_id
        WHERE t.reference_no = ? ORDER BY i.line_no, i.id');
    $stmt->execute([$referenceNo]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['line_no'] === null || (int) $row['line_no'] !== count($items) + 1
            || abs((float) $row['subtotal'] - round((int) $row['quantity'] * (float) $row['unit_price'], 2)) > 0.000001) {
            throw new RuntimeException('Receipt-item backfill is incomplete.');
        }
        $items[] = ['name' => $row['item_name'], 'qty' => (int) $row['quantity'],
                    'price' => (float) $row['unit_price'], 'line_total' => (float) $row['subtotal']];
    }
    if ($items) {
        $total = array_sum(array_column($items, 'line_total'));
        if (abs($total - (float) $row['recorded_amount']) > 0.000001) {
            throw new RuntimeException('Official receipt items do not match the recorded payment amount.');
        }
        return $items;
    }
    foreach (['cart' => 'cart_orders', 'qr' => 'merchant_qr_orders'] as $source => $table) {
        $stmt = $db->prepare("SELECT id FROM `{$table}` WHERE paid_ref = ? ORDER BY id LIMIT 1");
        $stmt->execute([$referenceNo]);
        $orderId = $stmt->fetchColumn();
        if ($orderId !== false) {
            foreach (gjc_order_item_lines($db, $source, (int) $orderId) as $line) {
                $items[] = ['name' => $line['name'], 'qty' => $line['qty'], 'price' => $line['price'],
                            'line_total' => round($line['qty'] * $line['price'], 2)];
            }
            break;
        }
    }

    return $items;
}

function gjc_user_label_cached(PDO $db, int $userId): string
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $cache[$userId] = gjc_user_label($db, $userId);
    }
    return $cache[$userId];
}

function gjc_student_wallet_owner_label(PDO $db, int $walletId): string
{
    static $cache = [];
    if (!$walletId) {
        return "Student Wallet";
    }
    if (isset($cache[$walletId])) {
        return $cache[$walletId];
    }

    $stmt = $db->prepare(
        "SELECT user_id FROM student_wallets WHERE id = ? LIMIT 1",
    );
    $stmt->execute([$walletId]);
    $userId = (int) $stmt->fetchColumn();

    return $cache[$walletId] = $userId
        ? gjc_user_label_cached($db, $userId)
        : "Student Wallet #" . $walletId;
}

function gjc_merchant_wallet_owner_label(PDO $db, int $walletId): string
{
    static $cache = [];
    if (!$walletId) {
        return "Merchant Wallet";
    }
    if (isset($cache[$walletId])) {
        return $cache[$walletId];
    }

    $stmt = $db->prepare(
        "SELECT user_id FROM merchant_wallets WHERE id = ? LIMIT 1",
    );
    $stmt->execute([$walletId]);
    $userId = (int) $stmt->fetchColumn();

    return $cache[$walletId] = $userId
        ? gjc_user_label_cached($db, $userId)
        : "Merchant Wallet #" . $walletId;
}

function gjc_transaction_sender_receiver(PDO $db, array $row): array
{
    $type = (string) ($row["type"] ?? "");

    switch ($type) {
        case "cash_in":
            return [
                "sender" => "Cashier Vault",
                "receiver" => gjc_student_wallet_owner_label(
                    $db,
                    (int) ($row["student_wallet_id"] ?? 0),
                ),
            ];

        case "payment":
            return [
                "sender" => gjc_student_wallet_owner_label(
                    $db,
                    (int) ($row["student_wallet_id"] ?? 0),
                ),
                "receiver" => gjc_merchant_wallet_owner_label(
                    $db,
                    (int) ($row["merchant_wallet_id"] ?? 0),
                ),
            ];

        case "voucher_payment":
            return [
                "sender" => "Visitor Voucher",
                "receiver" => gjc_merchant_wallet_owner_label(
                    $db,
                    (int) ($row["merchant_wallet_id"] ?? 0),
                ),
            ];

        case "merchant_settle":
            return [
                "sender" => gjc_merchant_wallet_owner_label(
                    $db,
                    (int) ($row["merchant_wallet_id"] ?? 0),
                ),
                "receiver" => "Cashier Vault",
            ];

        case "voucher_create":
            return [
                "sender" => "Cashier Vault",
                "receiver" => "Visitor Voucher",
            ];

        case "voucher_expire":
            return [
                "sender" => "Expired Voucher",
                "receiver" => "Cashier Vault",
            ];

        case "cap_increase":
            return [
                "sender" => gjc_user_label_cached(
                    $db,
                    (int) ($row["initiated_by"] ?? 0),
                ),
                "receiver" => "Cashier Vault",
            ];

        default:
            return [
                "sender" => "System",
                "receiver" => "System",
            ];
    }
}

function gjc_build_transaction_row(PDO $db, array $base): array
{
    $source = (string) ($base["source"] ?? "ledger");
    $type = (string) ($base["type"] ?? "");
    $status = strtolower((string) ($base["status"] ?? "completed"));
    $createdAt = (string) ($base["created_at"] ?? date("Y-m-d H:i:s"));
    $party = [
        "sender" => $base["sender"] ?? "System",
        "receiver" => $base["receiver"] ?? "System",
    ];

    if ($source === "ledger") {
        $party = gjc_transaction_sender_receiver($db, $base);
    }

    return [
        "source" => $source,
        "id" => (int) ($base["id"] ?? 0),
        "ref" => (string) ($base["ref"] ?? ""),
        "type" => $type,
        "type_label" => gjc_transaction_type_label($type),
        "type_slug" => gjc_transaction_type_slug($type),
        "amount" => (float) ($base["amount"] ?? 0),
        "sender" => (string) $party["sender"],
        "receiver" => (string) $party["receiver"],
        "status" => $status,
        "status_label" => gjc_transaction_status_label($status),
        "status_slug" => gjc_transaction_status_slug($status),
        "created_at" => $createdAt,
        "time_label" => date("M d, Y h:i A", strtotime($createdAt)),
        "notes" => (string) ($base["notes"] ?? ""),
        "meta" => $base,
    ];
}

function gjc_fetch_admin_transactions(
    PDO $db,
    array $filters = [],
    int $limit = 100,
    ?int $sqlCap = null,
): array {
    gjc_ensure_operational_tables($db);

    // How many rows each source SELECT may return. null keeps the historical
    // 500/300/300, which is what every screen wants - they show at most 150.
    // 0 means no cap: the CSV export is a reconciliation document and a
    // silently short one is worse than a slow one. $limit alone could not
    // express this, because it only ever controlled the array_slice below.
    $ledgerCap = $sqlCap === null ? 500 : $sqlCap;
    $requestCap = $sqlCap === null ? 300 : $sqlCap;
    $ledgerLimit = $ledgerCap > 0 ? ' LIMIT ' . (int) $ledgerCap : '';
    $requestLimit = $requestCap > 0 ? ' LIMIT ' . (int) $requestCap : '';

    $rows = [];

    if (gjc_table_exists($db, "transactions")) {
        $stmt = $db->query(
            "SELECT id, reference_no, transaction_type, initiated_by, student_wallet_id, merchant_wallet_id,
                    voucher_id, amount, status, notes, created_at, vault_before, vault_after,
                    total_in_circulation
               FROM transactions
              ORDER BY created_at DESC, id DESC" . $ledgerLimit,
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = gjc_build_transaction_row($db, [
                "source" => "ledger",
                "id" => $row["id"],
                "ref" => $row["reference_no"],
                "type" => $row["transaction_type"],
                "amount" => $row["amount"],
                "status" => $row["status"],
                "notes" => $row["notes"],
                "created_at" => $row["created_at"],
                "initiated_by" => $row["initiated_by"],
                "student_wallet_id" => $row["student_wallet_id"],
                "merchant_wallet_id" => $row["merchant_wallet_id"],
                "voucher_id" => $row["voucher_id"],
                "vault_before" => $row["vault_before"],
                "vault_after" => $row["vault_after"],
                "total_in_circulation" => $row["total_in_circulation"],
            ]);
        }
    }

    if (gjc_table_exists($db, "topup_requests")) {
        $stmt = $db->query(
            "SELECT id, reference_no,
                    (SELECT sw.user_id FROM student_wallets sw WHERE sw.id = topup_requests.student_wallet_id) AS user_id,
                    student_wallet_id, amount, payment_method, status,
                    approved_by, approved_at, rejected_by, rejected_at, created_at
               FROM topup_requests
              WHERE status <> 'approved' OR reference_no IS NULL OR reference_no = ''
              ORDER BY created_at DESC, id DESC" . $requestLimit,
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = gjc_build_transaction_row($db, [
                "source" => "topup_request",
                "id" => $row["id"],
                "ref" =>
                    $row["reference_no"] ?:
                    "TOPUP-REQ-" .
                        str_pad((string) $row["id"], 5, "0", STR_PAD_LEFT),
                "type" => "topup",
                "amount" => $row["amount"],
                "status" => $row["status"],
                "notes" => "Payment method: " . $row["payment_method"],
                "created_at" => $row["created_at"],
                "sender" => gjc_user_label_cached($db, (int) $row["user_id"]),
                "receiver" => "Cashier Review",
                "payment_method" => $row["payment_method"],
                "user_id" => $row["user_id"],
                "student_wallet_id" => $row["student_wallet_id"],
            ]);
        }
    }

    if (gjc_table_exists($db, "encashment_requests")) {
        $stmt = $db->query(
            "SELECT id, reference_no,
                    (SELECT mw.user_id FROM merchant_wallets mw WHERE mw.id = encashment_requests.merchant_wallet_id) AS user_id,
                    merchant_wallet_id, amount, method, status,
                    released_by, released_at, rejected_by, rejected_at, created_at
               FROM encashment_requests
              WHERE status <> 'released' OR reference_no IS NULL OR reference_no = ''
              ORDER BY created_at DESC, id DESC" . $requestLimit,
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = gjc_build_transaction_row($db, [
                "source" => "encashment_request",
                "id" => $row["id"],
                "ref" =>
                    $row["reference_no"] ?:
                    "ENCASH-REQ-" .
                        str_pad((string) $row["id"], 5, "0", STR_PAD_LEFT),
                "type" => "encashment",
                "amount" => $row["amount"],
                "status" => $row["status"],
                "notes" => "Method: " . $row["method"],
                "created_at" => $row["created_at"],
                "sender" => gjc_user_label_cached($db, (int) $row["user_id"]),
                "receiver" => "Cashier Release",
                "method" => $row["method"],
                "user_id" => $row["user_id"],
                "merchant_wallet_id" => $row["merchant_wallet_id"],
            ]);
        }
    }

    $search = trim((string) ($filters["search"] ?? ""));
    $typeFilter = strtolower(trim((string) ($filters["type"] ?? "")));
    $statusFilter = strtolower(trim((string) ($filters["status"] ?? "")));

    $rows = array_values(
        array_filter($rows, function (array $row) use (
            $search,
            $typeFilter,
            $statusFilter,
        ): bool {
            if ($typeFilter !== "" && $row["type_slug"] !== $typeFilter) {
                return false;
            }

            if ($statusFilter !== "" && $row["status"] !== $statusFilter) {
                return false;
            }

            if ($search === "") {
                return true;
            }

            $needle = strtolower($search);
            $haystacks = [
                $row["ref"],
                $row["type_label"],
                $row["sender"],
                $row["receiver"],
                $row["status_label"],
                number_format((float) $row["amount"], 2, ".", ""),
                $row["notes"],
            ];

            foreach ($haystacks as $value) {
                if (strpos(strtolower((string) $value), $needle) !== false) {
                    return true;
                }
            }

            return false;
        }),
    );

    usort($rows, function (array $a, array $b): int {
        $timeCompare = strcmp($b["created_at"], $a["created_at"]);
        if ($timeCompare !== 0) {
            return $timeCompare;
        }
        return $b["id"] <=> $a["id"];
    });

    if ($limit > 0) {
        $rows = array_slice($rows, 0, $limit);
    }

    return $rows;
}

/**
 * The four Transactions stat cards, counted by SQL over the whole tables.
 *
 * gjc_admin_transaction_stats() counts an array that gjc_fetch_admin_transactions()
 * has already capped, so "Total Transactions - All wallet activities" stopped
 * being true at roughly 1,100 rows. These figures do not depend on how many
 * rows a page happens to have loaded.
 *
 * The source set mirrors the fetch exactly: the ledger, plus the top-up and
 * encashment requests that have not yet become ledger rows (same WHERE clauses,
 * so a request is never counted twice). Success and pending statuses match
 * gjc_transaction_is_success() / gjc_transaction_is_pending().
 */
function gjc_admin_transaction_counts(PDO $db): array
{
    gjc_ensure_operational_tables($db);

    $success = "('completed', 'approved', 'released')";
    $pending = "('pending', 'processing')";

    $sources = [
        ['transactions', '1 = 1'],
        ['topup_requests', "status <> 'approved' OR reference_no IS NULL OR reference_no = ''"],
        ['encashment_requests', "status <> 'released' OR reference_no IS NULL OR reference_no = ''"],
    ];

    $counts = [
        'total_transactions' => 0,
        'todays_volume' => 0.0,
        'pending_transactions' => 0,
        'completed_today' => 0,
    ];

    foreach ($sources as [$table, $where]) {
        if (!gjc_table_exists($db, $table)) {
            continue;
        }
        try {
            $row = $db->query(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(CASE WHEN LOWER(status) IN {$success}
                                           AND DATE(created_at) = CURDATE()
                                          THEN amount ELSE 0 END), 0) AS volume_today,
                        SUM(CASE WHEN LOWER(status) IN {$pending} THEN 1 ELSE 0 END) AS pending,
                        SUM(CASE WHEN LOWER(status) IN {$success}
                                  AND DATE(created_at) = CURDATE()
                                 THEN 1 ELSE 0 END) AS done_today
                   FROM {$table}
                  WHERE {$where}",
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // A missing column on one source must not blank all four cards.
            continue;
        }

        $counts['total_transactions'] += (int) ($row['total'] ?? 0);
        $counts['todays_volume'] += (float) ($row['volume_today'] ?? 0);
        $counts['pending_transactions'] += (int) ($row['pending'] ?? 0);
        $counts['completed_today'] += (int) ($row['done_today'] ?? 0);
    }

    return $counts;
}

function gjc_admin_transaction_stats(array $rows): array
{
    $today = date("Y-m-d");
    $stats = [
        "total_transactions" => count($rows),
        "todays_volume" => 0.0,
        "pending_transactions" => 0,
        "completed_today" => 0,
    ];

    foreach ($rows as $row) {
        $isToday = substr((string) $row["created_at"], 0, 10) === $today;

        if (gjc_transaction_is_pending($row["status"])) {
            $stats["pending_transactions"]++;
        }

        if ($isToday && gjc_transaction_is_success($row["status"])) {
            $stats["todays_volume"] += (float) $row["amount"];
            $stats["completed_today"]++;
        }
    }

    return $stats;
}

function gjc_find_admin_transaction(
    PDO $db,
    string $source,
    ?string $ref = null,
    ?int $id = null,
): ?array {
    $transactions = gjc_fetch_admin_transactions($db, [], 0);

    foreach ($transactions as $transaction) {
        if ($transaction["source"] !== $source) {
            continue;
        }

        if ($ref !== null && $ref !== "" && $transaction["ref"] === $ref) {
            return $transaction;
        }

        if ($id !== null && $transaction["id"] === $id) {
            return $transaction;
        }
    }

    return null;
}

function gjc_count_users_by_role(PDO $db, string $roleName): int
{
    if (!gjc_table_exists($db, "users")) {
        return 0;
    }

    $roleName = strtolower(trim($roleName));

    if (gjc_table_exists($db, "role")) {
        $stmt = $db->prepare(
            "SELECT COUNT(*)
               FROM users u
               LEFT JOIN role r ON u.roleID = r.roleID
              WHERE LOWER(COALESCE(r.role_name, '')) = ?",
        );
        $stmt->execute([$roleName]);
        return (int) $stmt->fetchColumn();
    }

    $roleMap = [
        "student" => 1,
        "merchant" => 2,
        "finance" => 4,
        "admin" => 4,
        "super-admin" => 4,
    ];

    if (
        isset($roleMap[$roleName]) &&
        in_array("roleID", gjc_table_columns($db, "users"), true)
    ) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE roleID = ?");
        $stmt->execute([$roleMap[$roleName]]);
        return (int) $stmt->fetchColumn();
    }

    return 0;
}

/**
 * Daily money flow for the dashboard chart: how much entered wallets, how much
 * was spent at stalls, and how much left the system as cash.
 *
 * Read straight from the ledger with one grouped query rather than from the
 * recent-transactions list, which is capped at a few hundred rows and would
 * undercount a busy week. Only completed rows count; the vault-side types
 * (voucher create/expire, service fee, P2P) are internal shuffles, not flow.
 *
 *   loaded      top-ups + tuition credits   (money entering student wallets)
 *   spent       stall payments + vouchers   (money moving to merchants)
 *   cashed_out  merchant encashments + student withdrawals (money leaving)
 */
function gjc_dashboard_money_flow(PDO $db, int $days = 7): array
{
    $days = max(1, $days);
    $labels = [];
    $flow = [];
    for ($offset = $days - 1; $offset >= 0; $offset--) {
        $date = date("Y-m-d", strtotime("-{$offset} days"));
        $labels[] = date("M d", strtotime($date));
        $flow[$date] = ["loaded" => 0.0, "spent" => 0.0, "cashed_out" => 0.0];
    }

    $bucketOf = [
        "cash_in" => "loaded",
        "tuition_credit" => "loaded",
        "payment" => "spent",
        "voucher_payment" => "spent",
        "merchant_settle" => "cashed_out",
        "student_withdraw" => "cashed_out",
    ];

    if (gjc_table_exists($db, "transactions")) {
        $marks = implode(",", array_fill(0, count($bucketOf), "?"));
        $stmt = $db->prepare(
            "SELECT DATE(created_at) AS d, transaction_type, SUM(amount) AS total
               FROM transactions
              WHERE status = 'completed'
                AND created_at >= ?
                AND transaction_type IN ($marks)
              GROUP BY DATE(created_at), transaction_type",
        );
        $stmt->execute([
            date("Y-m-d", strtotime("-" . ($days - 1) . " days")) . " 00:00:00",
            ...array_keys($bucketOf),
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bucket = $bucketOf[$row["transaction_type"]] ?? null;
            if ($bucket !== null && isset($flow[$row["d"]])) {
                $flow[$row["d"]][$bucket] += (float) $row["total"];
            }
        }
    }

    $series = ["loaded" => [], "spent" => [], "cashed_out" => []];
    foreach ($flow as $day) {
        foreach ($series as $key => $_) {
            $series[$key][] = round($day[$key], 2);
        }
    }

    return ["labels" => $labels, "series" => $series];
}

/**
 * The two queues a finance officer is responsible for clearing.
 *
 * One source for both places that show them: the "Needs your attention" strip
 * on the dashboard and the count badges in the sidebar. When those two were
 * computed separately they could disagree, which is worse than showing
 * nothing — a badge saying 3 next to a tile saying 2 makes the user distrust
 * both numbers.
 *
 * Every count is guarded by gjc_table_exists() and the whole thing by a
 * try/catch, because a missing table or a bad connection must degrade to
 * "no badge" rather than take the sidebar down on every admin page.
 */
function gjc_finance_queue_counts(PDO $db): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $counts = [
        'encashments' => 0,
        'stall_applications' => 0,
    ];

    $queries = [
        'encashments' => ['encashment_requests', "SELECT COUNT(*) FROM encashment_requests WHERE status = 'pending'"],
        'stall_applications' => ['stall_applications', "SELECT COUNT(*) FROM stall_applications WHERE status = 'pending_verification'"],
    ];

    foreach ($queries as $key => [$table, $sql]) {
        try {
            if (gjc_table_exists($db, $table)) {
                $counts[$key] = (int) $db->query($sql)->fetchColumn();
            }
        } catch (Throwable $e) {
            // Leave it at zero: a broken count must not break the page.
        }
    }

    $cached = $counts;
    return $counts;
}

function gjc_admin_dashboard_data(PDO $db): array
{
    gjc_ensure_operational_tables($db);

    $snapshot = [];
    if (gjc_table_exists($db, "system_settings")) {
        try {
            require_once __DIR__ . "/CirculationEngine.php";
            $engine = new CirculationEngine($db);
            $snapshot = $engine->getCirculationSnapshot();
        } catch (\Throwable) {
            $snapshot = [];
        }
    }

    $transactions = gjc_fetch_admin_transactions($db, [], 0);
    $stats = gjc_admin_transaction_stats($transactions);
    $recentTransactions = array_slice($transactions, 0, 10);
    $moneyFlow = gjc_dashboard_money_flow($db, 7);

    $studentWalletsTotal = (float) ($snapshot["student_wallets_total"] ?? 0);
    $merchantWalletsTotal = (float) ($snapshot["merchant_wallets_total"] ?? 0);
    // Kept after the visitor feature was removed, for the same reason the
    // engine keeps its active-voucher term: every voucher was expired back into
    // the vault, so this is zero — but if an 'active' row ever reappears the
    // money still shows as circulating rather than quietly disappearing.
    $activeVouchersTotal = (float) ($snapshot["active_vouchers_total"] ?? 0);
    $circulatingBalance =
        $studentWalletsTotal + $merchantWalletsTotal + $activeVouchersTotal;

    $pendingEncashments = 0;
    if (gjc_table_exists($db, "encashment_requests")) {
        $pendingEncashments = (int) $db
            ->query(
                "SELECT COUNT(*) FROM encashment_requests WHERE status = 'pending'",
            )
            ->fetchColumn();
    }

    $totalUsers = 0;
    if (gjc_table_exists($db, "users")) {
        $totalUsers = gjc_table_exists($db, "role")
            ? (int) $db->query(
                "SELECT COUNT(*)
                   FROM users u
                   LEFT JOIN role r ON u.roleID = r.roleID
                  WHERE LOWER(COALESCE(r.role_name, '')) != 'finance'"
            )->fetchColumn()
            : (int) $db->query(
                "SELECT COUNT(*) FROM users WHERE roleID NOT IN (3, 4)"
            )->fetchColumn();
    }

    return [
        "system_financials" => [
            "circulating_balance" => $circulatingBalance,
            "todays_volume" => (float) $stats["todays_volume"],
            "pending_encashments" => $pendingEncashments,
        ],
        "user_demographics" => [
            "total_users" => $totalUsers,
            "active_students" => gjc_count_users_by_role($db, "student"),
            "active_merchants" => gjc_count_users_by_role($db, "merchant"),
        ],
        "recent_transactions" => $recentTransactions,
        "money_flow" => $moneyFlow,
    ];
}

// ─── Phase 2: Role & Auth Layer helpers ──────────────────────────────────────

function gjc_sub_role(): string
{
    return strtolower((string) ($_SESSION["sub_role"] ?? ""));
}

function gjc_is_merchant_admin(): bool
{
    return gjc_sub_role() === "merchant_admin";
}

function gjc_is_merchant_staff(): bool
{
    return gjc_sub_role() === "merchant_staff";
}

function gjc_token_display(float $phpAmount): string
{
    // Cosmetic display: ₱10 = 1 GenCoin
    $tokens = $phpAmount / 10.0;
    return number_format($tokens, 1) . " GC";
}

/**
 * Retain copied wallet-owner IDs until a separately approved CONTRACT.
 * Metadata only: a table's compatibility columns must be all present or all absent.
 */
function gjc_has_legacy_wallet_user_ids(PDO $db, string $table): bool
{
    $legacyColumns = [
        'cart_orders' => ['student_user_id', 'merchant_user_id', 'parent_user_id'],
        'merchant_qr_orders' => ['merchant_user_id'],
        'parent_alerts' => ['student_user_id'],
    ];
    if (!isset($legacyColumns[$table])) {
        throw new InvalidArgumentException('Unsupported wallet-owner compatibility table.');
    }
    $columns = gjc_table_columns($db, $table, true);
    $present = count(array_intersect($legacyColumns[$table], $columns));
    if ($present !== 0 && $present !== count($legacyColumns[$table])) {
        throw new RuntimeException('Wallet-owner compatibility columns must be all present or all absent.');
    }
    return $present !== 0;
}

/**
 * Explicit historical-voucher CONTRACT. Read-only unless applying with a private export.
 */
function gjc_ensure_group_b_voucher_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Voucher CONTRACT requires CLI, ewallet, and no transaction.');
    }
    if (!gjc_table_exists($db, 'vouchers')) {
        throw new RuntimeException('Historical vouchers table is missing.');
    }
    $columns = gjc_table_columns($db, 'vouchers', true);
    $removed = ['original_amount', 'is_non_refundable'];
    $present = array_intersect($removed, $columns);
    if (!$present) {
        return [];
    }
    if (count($present) !== 2) {
        throw new RuntimeException('Voucher duplicates are partially contracted.');
    }
    foreach (['id', 'initial_value', 'is_refundable', 'remaining_balance', 'status'] as $column) {
        if (!in_array($column, $columns, true)) {
            throw new RuntimeException('Required historical voucher fact is missing.');
        }
    }
    $rows = $db->query('SELECT * FROM vouchers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 2 || array_map(static fn($row) => (int) $row['id'], $rows) !== [1, 2]) {
        throw new RuntimeException('Historical voucher cohort changed; reapprove the plan.');
    }
    $ledger = $db->prepare("SELECT transaction_type, amount, vault_before, vault_after
        FROM transactions WHERE voucher_id = ? AND status = 'completed'
          AND transaction_type IN ('voucher_create', 'voucher_expire') ORDER BY id");
    foreach ($rows as $voucher) {
        $initial = (float) $voucher['initial_value'];
        if ($voucher['status'] !== 'expired' || $initial <= 0 || (int) $voucher['is_refundable'] !== 1
            || (float) $voucher['original_amount'] !== 0.0 || (int) $voucher['is_non_refundable'] !== 1) {
            throw new RuntimeException('Unexpected voucher facts; no DROP applied.');
        }
        $ledger->execute([(int) $voucher['id']]);
        $entries = $ledger->fetchAll(PDO::FETCH_ASSOC);
        if (count($entries) !== 2 || $entries[0]['transaction_type'] !== 'voucher_create'
            || $entries[1]['transaction_type'] !== 'voucher_expire'
            || (float) $entries[0]['amount'] !== $initial || (float) $entries[1]['amount'] !== $initial
            || round((float) $entries[0]['vault_before'] - (float) $entries[0]['vault_after'], 2) !== $initial
            || round((float) $entries[1]['vault_after'] - (float) $entries[1]['vault_before'], 2) !== $initial) {
            throw new RuntimeException('Voucher facts conflict with the immutable ledger.');
        }
    }
    $dependencies = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'vouchers'
        AND COLUMN_NAME IN ('original_amount', 'is_non_refundable')) OR
        (REFERENCED_TABLE_NAME = 'vouchers' AND REFERENCED_COLUMN_NAME IN ('original_amount', 'is_non_refundable')))")
        ->fetchAll(PDO::FETCH_ASSOC);
    $indexes = $db->query("SELECT INDEX_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vouchers'
          AND COLUMN_NAME IN ('original_amount', 'is_non_refundable')")->fetchAll(PDO::FETCH_ASSOC);
    if ($dependencies || $indexes) {
        throw new RuntimeException('Unexpected voucher constraint or index depends on duplicates.');
    }
    $objects = array_merge(
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc
            JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'vouchers'")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if (preg_match('/\\b(original_amount|is_non_refundable)\\b/i', $object['body'] ?? '')) {
            throw new RuntimeException('Database object depends on voucher duplicates: ' . $object['name']);
        }
    }
    $plan = ['vouchers' => 'ALTER TABLE vouchers DROP COLUMN original_amount, DROP COLUMN is_non_refundable'];
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A private voucher export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    $schema = $db->query('SHOW CREATE TABLE vouchers')->fetch(PDO::FETCH_NUM)[1];
    if (($archive['schema'] ?? null) !== $schema || ($archive['rows'] ?? null) !== $rows) {
        throw new RuntimeException('Private voucher export differs from current data.');
    }
    $db->exec($plan['vouchers']);
    return $plan;
}

/**
 * Explicit Group C CONTRACT; read-only by default and requires a matching private export.
 * MySQL commits each ALTER independently; a rerun checks any remaining column.
 */
function gjc_ensure_group_c_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Group C CONTRACT requires CLI, ewallet, and no transaction.');
    }
    $targets = ['cap_increase_log' => 'amount_added', 'system_settings' => 'school_revenue_balance'];
    $plan = [];
    $current = [];
    foreach ($targets as $table => $column) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Required Group C table is missing: ' . $table);
        }
        if (!in_array($column, gjc_table_columns($db, $table, true), true)) {
            continue;
        }
        if ($table === 'cap_increase_log') {
            $invalid = $db->query('SELECT COUNT(*) FROM cap_increase_log
                WHERE amount_added IS NULL OR old_cap IS NULL OR new_cap IS NULL
                   OR amount_added <> new_cap - old_cap')->fetchColumn();
            if ((int) $invalid !== 0) {
                throw new RuntimeException('Cap log contains conflicting derived values.');
            }
        } else {
            $settings = $db->query('SELECT id, school_revenue_balance FROM system_settings ORDER BY id')
                ->fetchAll(PDO::FETCH_ASSOC);
            if (count($settings) !== 1 || (int) $settings[0]['id'] !== 1
                || $settings[0]['school_revenue_balance'] === null
                || (float) $settings[0]['school_revenue_balance'] !== 0.0
                || !gjc_table_exists($db, 'school_revenue_ledger')
                || (int) $db->query('SELECT COUNT(*) FROM school_revenue_ledger')->fetchColumn() !== 0) {
                throw new RuntimeException('Obsolete revenue facts changed; reapprove the plan.');
            }
        }
        $key = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = ? AND COLUMN_NAME = ?)
                OR (REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?))");
        $key->execute([$table, $column, $table, $column]);
        $index = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $index->execute([$table, $column]);
        if ((int) $key->fetchColumn() !== 0 || (int) $index->fetchColumn() !== 0) {
            throw new RuntimeException('A key or index depends on the Group C column.');
        }
        $current[$table] = [
            'schema' => $db->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1],
            'rows' => $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
        $plan[$table] = 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $column;
    }
    if (!$plan) {
        return [];
    }
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT EVENT_NAME AS name, EVENT_DEFINITION AS body FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name, GENERATION_EXPRESSION AS body
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND GENERATION_EXPRESSION <> ''")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if ($object['body'] === null) {
            throw new RuntimeException('Cannot inspect database object: ' . $object['name']);
        }
        foreach ($plan as $table => $sql) {
            if (preg_match('/\\b' . preg_quote($targets[$table], '/') . '\\b/i', $object['body'])) {
                throw new RuntimeException('Database object depends on Group C column: ' . $object['name']);
            }
        }
    }
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A matching private Group C export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($current as $table => $facts) {
        if (($archive['tables'][$table] ?? null) !== $facts) {
            throw new RuntimeException('Private Group C export differs from current table: ' . $table);
        }
    }
    foreach ($plan as $table => $sql) {
        $db->exec($sql);
        gjc_table_columns($db, $table, true);
    }
    return $plan;
}

/** Defines normalized cap logs for fresh installs; preserves existing tables. */
function gjc_ensure_cap_increase_log_schema(PDO $db): void
{
    if (gjc_table_exists($db, 'cap_increase_log')) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Cap log schema creation must run before a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `cap_increase_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `super_admin_id` int unsigned NOT NULL COMMENT 'FK -> users.id -- must be super-admin role',
  `old_cap` decimal(15,2) NOT NULL,
  `new_cap` decimal(15,2) NOT NULL,
  `reason` text COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Mandatory justification for audit compliance',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source` enum('super_admin_mint','tuition_credit','fee_waiver_claim') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `source_ref_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    gjc_table_columns($db, 'cap_increase_log', true);
}

/** Preserve cap-log compatibility writes until amount_added is contracted. */
function gjc_cap_log_has_legacy_amount_added(PDO $db): bool
{
    if (!gjc_table_exists($db, 'cap_increase_log')) {
        throw new RuntimeException('The cap increase log table is missing.');
    }
    return in_array('amount_added', gjc_table_columns($db, 'cap_increase_log', true), true);
}

/** Defines historical voucher tables; preserves existing rows and schema. */
function gjc_ensure_historical_vouchers_schema(PDO $db): void
{
    if (gjc_table_exists($db, 'vouchers')) {
        return;
    }
    if (!gjc_table_exists($db, 'users')) {
        throw new RuntimeException('Historical voucher schema requires the users table.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `vouchers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `qr_code_hash` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `voucher_code` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `issued_by` int NOT NULL COMMENT 'FK -> users.id (cashier or admin who created it)',
  `is_refundable` tinyint(1) NOT NULL DEFAULT '0',
  `visitor_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `visitor_contact` varchar(60) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `initial_value` decimal(15,2) DEFAULT NULL,
  `remaining_balance` decimal(10,2) NOT NULL COMMENT 'Unspent points -- stays in the economy, non-refundable',
  `status` enum('active','used','expired','void') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `expires_at` datetime NOT NULL,
  `expired_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_code` (`voucher_code`),
  UNIQUE KEY `qr_code_hash` (`qr_code_hash`),
  KEY `idx_v_hash` (`qr_code_hash`),
  KEY `idx_v_status` (`status`),
  KEY `idx_v_expiry` (`expires_at`,`status`),
  KEY `fk_core_vouchers_issued_by` (`issued_by`),
  CONSTRAINT `fk_core_vouchers_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`userID`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

/** Defines new P2P tables with wallet IDs; existing tables are preserved. */
function gjc_ensure_p2p_transfers_schema(PDO $db): void
{
    if (gjc_table_exists($db, 'p2p_transfers')) {
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `p2p_transfers` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reference_no`      VARCHAR(40) NOT NULL,
    `from_wallet_id`    INT UNSIGNED NOT NULL COMMENT 'FK -> student_wallets.id',
    `to_wallet_id`      INT UNSIGNED NOT NULL COMMENT 'FK -> student_wallets.id',
    `amount`            DECIMAL(15,2) NOT NULL,
    `message`           VARCHAR(255) NULL DEFAULT NULL,
    `status`            ENUM('completed','failed','reversed') NOT NULL DEFAULT 'completed',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reference_no` (`reference_no`),
    KEY `idx_p2p_from` (`from_wallet_id`),
    KEY `idx_p2p_to` (`to_wallet_id`),
    KEY `idx_p2p_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Peer-to-peer token transfers between students (atomic, transactionally safe)'"
    );
}

/**
 * Keep P2P compatibility writes until the copied user IDs are contracted.
 * This checks metadata only; it does not change the schema.
 */
function gjc_p2p_has_legacy_user_ids(PDO $db): bool
{
    $columns = gjc_table_columns($db, 'p2p_transfers', true);
    $hasFrom = in_array('from_user_id', $columns, true);
    $hasTo = in_array('to_user_id', $columns, true);
    if ($hasFrom !== $hasTo) {
        throw new RuntimeException('P2P user-ID compatibility columns must be present or absent together.');
    }
    return $hasFrom;
}

function gjc_p2p_daily_sent(PDO $db, int $userId): float
{
    if (!gjc_table_exists($db, "p2p_transfers")) {
        return 0.0;
    }
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(p.amount), 0) FROM p2p_transfers p
           JOIN student_wallets sw ON sw.id = p.from_wallet_id
          WHERE sw.user_id = ? AND DATE(p.created_at) = CURDATE() AND p.status = 'completed'",
    );
    $stmt->execute([$userId]);
    return (float) $stmt->fetchColumn();
}

// ─── Parent Account Module ────────────────────────────────────────────────────

function gjc_ensure_parent_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS parents (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id               INT NOT NULL UNIQUE,
            low_balance_threshold DECIMAL(10,2) NOT NULL DEFAULT 50.00,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS parent_student_links (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_id       INT UNSIGNED NOT NULL,
            student_user_id INT NOT NULL,
            linked_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_link (parent_id, student_user_id),
            INDEX idx_parent  (parent_id),
            INDEX idx_student (student_user_id)
        ) ENGINE=InnoDB"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS parent_alerts (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_id         INT UNSIGNED NOT NULL,
            student_wallet_id INT UNSIGNED NOT NULL,
            balance_at_alert  DECIMAL(10,2) NOT NULL,
            threshold         DECIMAL(10,2) NOT NULL,
            is_read           TINYINT(1) NOT NULL DEFAULT 0,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_parent_unread (parent_id, is_read)
        ) ENGINE=InnoDB"
    );

    // Add spending-control columns to student_wallets if absent
    if (gjc_table_exists($db, 'student_wallets')) {
        $cols = gjc_table_columns($db, 'student_wallets');
        $walletAdds = [
            'daily_spend_limit' => 'DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER balance',
            'is_frozen'         => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER daily_spend_limit',
        ];
        foreach ($walletAdds as $col => $def) {
            if (!in_array($col, $cols, true)) {
                try {
                    $db->exec("ALTER TABLE student_wallets ADD COLUMN {$col} {$def}");
                } catch (\Throwable $ignored) {}
            }
        }
    }
}

function gjc_check_parent_balance_alert(PDO $db, int $walletId): void
{
    if (!gjc_table_exists($db, 'parents') || !gjc_table_exists($db, 'parent_student_links') || !gjc_table_exists($db, 'parent_alerts')) {
        return;
    }

    $wStmt = $db->prepare("SELECT user_id, balance FROM student_wallets WHERE id = ?");
    $wStmt->execute([$walletId]);
    $wallet = $wStmt->fetch(PDO::FETCH_ASSOC);
    if (!$wallet) return;

    $pStmt = $db->prepare(
        "SELECT p.id AS parent_id, p.low_balance_threshold
           FROM parents p
           JOIN parent_student_links psl ON psl.parent_id = p.id
          WHERE psl.student_user_id = ?
            AND p.low_balance_threshold > 0
            AND p.low_balance_threshold >= ?"
    );
    $pStmt->execute([$wallet['user_id'], $wallet['balance']]);
    $parents = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($parents as $parent) {
        // Throttle: one unread alert per parent-student pair per day
        $chk = $db->prepare(
            "SELECT COUNT(*) FROM parent_alerts pa
               JOIN student_wallets sw ON sw.id = pa.student_wallet_id
              WHERE pa.parent_id = ? AND sw.user_id = ?
                AND DATE(pa.created_at) = CURDATE() AND pa.is_read = 0"
        );
        $chk->execute([$parent['parent_id'], $wallet['user_id']]);
        if ((int) $chk->fetchColumn() === 0) {
            $legacyUserIds = gjc_has_legacy_wallet_user_ids($db, 'parent_alerts');
            $db->prepare(
                "INSERT INTO parent_alerts
                    (parent_id" . ($legacyUserIds ? ", student_user_id" : "") . ", student_wallet_id, balance_at_alert, threshold)
                 VALUES (?, " . ($legacyUserIds ? "?, " : "") . "?, ?, ?)"
            )->execute([
                $parent['parent_id'],
                ...($legacyUserIds ? [$wallet['user_id']] : []),
                $walletId,
                $wallet['balance'],
                $parent['low_balance_threshold'],
            ]);
        }
    }
}

/**
 * Parent wallet: parents top up with cash (Finance or a merchant), then send
 * one-time allowance transfers to a linked student. Keyed on parents.id (not
 * users.userID directly) because the rest of the parent portal already
 * resolves identity through that table — see gjc_parent_id_for_user().
 */
function gjc_ensure_parent_wallet_schema(PDO $db): void
{
    // DDL — must run before any transaction starts (MySQL implicitly commits
    // an open transaction the instant it sees DDL).
    gjc_ensure_transaction_types($db, ['allowance']);

    $db->exec(
        "CREATE TABLE IF NOT EXISTS parent_wallets (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_id  INT UNSIGNED NOT NULL UNIQUE,
            balance    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );


    if (gjc_table_exists($db, 'transactions')) {
        $cols = gjc_table_columns($db, 'transactions');
        if (!in_array('parent_wallet_id', $cols, true)) {
            try {
                $db->exec("ALTER TABLE transactions ADD COLUMN parent_wallet_id INT UNSIGNED NULL");
            } catch (\Throwable $ignored) {}
        }
        $hasIndex = (bool) $db->query(
            "SHOW INDEX FROM transactions WHERE Key_name = 'idx_txn_parent_wallet'"
        )->fetchColumn();
        if (!$hasIndex) {
            try {
                $db->exec("ALTER TABLE transactions ADD INDEX idx_txn_parent_wallet (parent_wallet_id, id)");
            } catch (\Throwable $ignored) {}
        }
    }
}

/** Get-or-create the parents row for a logged-in parent user, return its id. */
function gjc_parent_id_for_user(PDO $db, int $userId): int
{
    if (!$userId) {
        return 0;
    }

    $stmt = $db->prepare("SELECT id FROM parents WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->prepare("INSERT IGNORE INTO parents (user_id) VALUES (?)")->execute([$userId]);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return $row ? (int) $row['id'] : 0;
}

/** Get-or-create a parent_wallets row by parents.id — mirrors gjc_student_wallet(). */
function gjc_parent_wallet(PDO $db, int $parentId): array
{
    if ($parentId && gjc_table_exists($db, 'parent_wallets')) {
        $stmt = $db->prepare("SELECT * FROM parent_wallets WHERE parent_id = ? LIMIT 1");
        $stmt->execute([$parentId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wallet) {
            $db->prepare(
                "INSERT IGNORE INTO parent_wallets (parent_id, balance) VALUES (?, 0)"
            )->execute([$parentId]);
            $stmt->execute([$parentId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($wallet) {
            return [
                "id" => (int) $wallet["id"],
                "balance" => (float) $wallet["balance"],
                "source" => "parent_wallets",
            ];
        }
    }

    return ["id" => 0, "balance" => 0.0, "source" => "none"];
}

/**
 * School-managed Fee Waiver Credit: a non-wallet, non-GenCoin misc. credit
 * that finance creates (amount + signed parent waiver upload) to be applied
 * against a student's tuition by whichever system actually owns tuition fee
 * — GenPay does not track or manage tuition fee itself, only this credit.
 * Finance's own set_amount/upload_waiver/cancel workflow (admin/api/fee_waiver_credits.php)
 * never touches CirculationEngine, student_wallets, or system_settings.
 *
 * Once a credit is 'posted', the student or a linked parent may separately
 * choose to claim it into real GenCoin (gjc_claim_fee_waiver_credit()) —
 * that is the only path that mints, and it is self-service, one-way, and
 * unrelated to finance's workflow above.
 */
function gjc_ensure_fee_waiver_credits_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS fee_waiver_credits (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_user_id INT UNSIGNED NOT NULL,
            amount          DECIMAL(10,2) NULL,
            status          ENUM('empty', 'pending', 'posted', 'claimed') NOT NULL DEFAULT 'empty',
            waiver_file     VARCHAR(255) NULL,
            claimed_at         DATETIME NULL,
            claimed_by_user_id INT UNSIGNED NULL,
            claim_reference    VARCHAR(30) NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fee_waiver_student (student_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Existing installs: widen the status enum and add the claim columns.
    gjc_ensure_enum_values($db, 'fee_waiver_credits', 'status', ['empty', 'pending', 'posted', 'claimed']);
    $fwcCols = gjc_table_columns($db, 'fee_waiver_credits');
    $fwcAltered = false;
    foreach ([
        'claimed_at'         => 'DATETIME NULL',
        'claimed_by_user_id' => 'INT UNSIGNED NULL',
        'claim_reference'    => 'VARCHAR(30) NULL',
    ] as $col => $def) {
        if (!in_array($col, $fwcCols, true)) {
            try {
                $db->exec("ALTER TABLE fee_waiver_credits ADD COLUMN {$col} {$def}");
                $fwcAltered = true;
            } catch (\Throwable $ignored) {}
        }
    }
    if ($fwcAltered) {
        gjc_table_columns($db, 'fee_waiver_credits', true);
    }

    // Append-only transition history — no updates/deletes, ever.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS fee_waiver_credit_logs (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fee_waiver_credit_id  INT UNSIGNED NOT NULL,
            old_status            VARCHAR(20) NOT NULL,
            new_status            VARCHAR(20) NOT NULL,
            amount                DECIMAL(10,2) NULL,
            changed_by_user_id    INT UNSIGNED NOT NULL,
            changed_by_role       VARCHAR(20) NOT NULL,
            changed_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fwcl_credit (fee_waiver_credit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

/**
 * Backfills fee_waiver_credits for students who existed before this feature
 * shipped (the CSV import hook only creates rows for students created from
 * here on). Idempotent — INSERT IGNORE against the unique student_user_id
 * key, so already-populated rows are never touched.
 */
function gjc_backfill_fee_waiver_credits(PDO $db): void
{
    $db->exec(
        "INSERT IGNORE INTO fee_waiver_credits (student_user_id, status)
         SELECT u.userID, 'empty' FROM users u
          WHERE u.roleID = 1
            AND NOT EXISTS (SELECT 1 FROM fee_waiver_credits fwc WHERE fwc.student_user_id = u.userID)"
    );
}

/**
 * The Fee Waiver Credit for one student — reused as-is by the admin,
 * student, and parent views so nobody re-implements this lookup
 * differently. GenPay does not own tuition fee, so this returns only the
 * credit itself (amount, status, signed file), not any tuition total.
 */
function gjc_student_waiver_credit(PDO $db, int $studentUserId): array
{
    gjc_ensure_fee_waiver_credits_schema($db);

    $stmt = $db->prepare(
        "SELECT amount, status, waiver_file, claimed_at, claim_reference
           FROM fee_waiver_credits WHERE student_user_id = ? LIMIT 1"
    );
    $stmt->execute([$studentUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'amount' => null, 'status' => 'empty', 'waiver_file' => null,
        'claimed_at' => null, 'claim_reference' => null,
    ];

    return [
        'status'         => (string) $row['status'],
        'amount'         => $row['amount'] !== null ? (float) $row['amount'] : null,
        'waiver_file'    => $row['waiver_file'],
        'claimed_at'     => $row['claimed_at'],
        'claim_reference' => $row['claim_reference'],
    ];
}

/**
 * Claims a posted Fee Waiver Credit into real GenCoin, minted straight into
 * the student's wallet via CirculationEngine::feeWaiverClaim(). Self-service
 * — callable by the student themselves or by a linked parent (the caller is
 * responsible for that access check; this function only re-checks the row's
 * status). Atomic test-and-set on the status column makes "whoever claims
 * first wins" race-safe between the student and parent hitting this at the
 * same time — see references/patterns.md's race-safety pattern.
 *
 * @throws RuntimeException if the credit isn't currently 'posted', or the
 *         student has no wallet yet.
 */
function gjc_claim_fee_waiver_credit(PDO $db, int $studentUserId, int $actingUserId): array
{
    gjc_ensure_fee_waiver_credits_schema($db);
    require_once __DIR__ . '/CirculationEngine.php';

    // DDL — must run before any beginTransaction() below.
    gjc_ensure_transaction_types($db, ['fee_waiver_claim']);
    gjc_ensure_enum_values($db, 'cap_increase_log', 'source', ['super_admin_mint', 'tuition_credit', 'fee_waiver_claim']);

    $stmt = $db->prepare("SELECT id, amount FROM fee_waiver_credits WHERE student_user_id = ? LIMIT 1");
    $stmt->execute([$studentUserId]);
    $credit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$credit) {
        throw new RuntimeException('No Fee Waiver Credit record exists for this student.');
    }
    $creditId = (int) $credit['id'];
    $amount = (float) $credit['amount'];

    $claim = $db->prepare(
        "UPDATE fee_waiver_credits
            SET status = 'claimed', claimed_at = NOW(), claimed_by_user_id = ?
          WHERE id = ? AND status = 'posted'"
    );
    $claim->execute([$actingUserId, $creditId]);
    if ($claim->rowCount() === 0) {
        throw new RuntimeException('This credit is not available to claim — it may already have been claimed.');
    }

    try {
        $wallet = gjc_student_wallet($db, $studentUserId);
        if ((int) $wallet['id'] === 0) {
            throw new RuntimeException('Student wallet not found.');
        }

        $engine = new CirculationEngine($db);
        $result = $engine->feeWaiverClaim((int) $wallet['id'], $amount, $actingUserId, $creditId);

        $db->prepare("UPDATE fee_waiver_credits SET claim_reference = ? WHERE id = ?")
            ->execute([$result['reference'], $creditId]);

        logAudit(
            $db, $actingUserId, gjc_current_role(), 'FEE_WAIVER_STATUS_CHANGE', 'fee_waiver_credits',
            ['student_user_id' => $studentUserId, 'status' => 'posted'],
            ['student_user_id' => $studentUserId, 'status' => 'claimed', 'amount' => $amount, 'reference' => $result['reference']],
            null,
            ['target_id' => $studentUserId]
        );

        gjc_notify(
            $db,
            $studentUserId,
            'fee_waiver',
            'Fee Waiver Credit Added to Wallet',
            gjc_money_plain($amount) . ' from your Fee Waiver Credit is now in your GenPay wallet.',
            'hand-holding-dollar',
            STUDENT_URL . '/profile.php'
        );

        return ['reference' => $result['reference'], 'amount' => $amount, 'status' => 'claimed'];
    } catch (\Throwable $e) {
        // Mint failed after the claim was taken — give the credit back to the queue
        // instead of leaving it stuck 'claimed' with no money behind it.
        $db->prepare(
            "UPDATE fee_waiver_credits SET status = 'posted', claimed_at = NULL, claimed_by_user_id = NULL WHERE id = ? AND status = 'claimed'"
        )->execute([$creditId]);
        throw $e;
    }
}

/**
 * Sibling to gjc_claim_fee_waiver_credit(), for the Bulk Student Import's
 * "Additional Fee" column (admin/maintenance.php): a brand-new student whose
 * import row carries an amount goes straight from 'empty' to 'claimed' —
 * there is no waiver document and no separate self-service claim step,
 * because finance reviewing the import preview and clicking Apply is itself
 * the approval. MUST be called AFTER the caller's own beginTransaction()/
 * commit() around the student-creation insert, never nested inside it —
 * CirculationEngine opens its own transaction and PDO does not support
 * nested transactions.
 *
 * @throws RuntimeException if the credit isn't 'empty' (defensive — the
 *         row was just created moments ago by the same import), or the
 *         student has no wallet yet.
 */
function gjc_import_fee_waiver_credit(PDO $db, int $studentUserId, float $amount, int $creditId, int $initiatedBy): array
{
    require_once __DIR__ . '/CirculationEngine.php';

    // DDL — must run before any beginTransaction() below.
    gjc_ensure_transaction_types($db, ['fee_waiver_claim']);
    gjc_ensure_enum_values($db, 'cap_increase_log', 'source', ['super_admin_mint', 'tuition_credit', 'fee_waiver_claim']);

    $claim = $db->prepare(
        "UPDATE fee_waiver_credits
            SET status = 'claimed', amount = ?, claimed_at = NOW(), claimed_by_user_id = ?
          WHERE id = ? AND status = 'empty'"
    );
    $claim->execute([$amount, $initiatedBy, $creditId]);
    if ($claim->rowCount() === 0) {
        throw new RuntimeException("Fee Waiver Credit #{$creditId} was not in the 'empty' state.");
    }

    try {
        $wallet = gjc_student_wallet($db, $studentUserId);
        if ((int) $wallet['id'] === 0) {
            throw new RuntimeException('Student wallet not found.');
        }

        $engine = new CirculationEngine($db);
        $result = $engine->feeWaiverClaim((int) $wallet['id'], $amount, $initiatedBy, $creditId);

        $db->prepare("UPDATE fee_waiver_credits SET claim_reference = ? WHERE id = ?")
            ->execute([$result['reference'], $creditId]);

        logAudit(
            $db, $initiatedBy, gjc_current_role(), 'FEE_WAIVER_STATUS_CHANGE', 'fee_waiver_credits',
            ['student_user_id' => $studentUserId, 'status' => 'empty'],
            ['student_user_id' => $studentUserId, 'status' => 'claimed', 'amount' => $amount, 'reference' => $result['reference'], 'source' => 'bulk_import'],
            null,
            ['target_id' => $studentUserId]
        );

        gjc_notify(
            $db,
            $studentUserId,
            'fee_waiver',
            'Fee Waiver Credit Added to Wallet',
            gjc_money_plain($amount) . ' from your Fee Waiver Credit is now in your GenPay wallet.',
            'hand-holding-dollar',
            STUDENT_URL . '/profile.php'
        );

        return ['reference' => $result['reference'], 'amount' => $amount, 'status' => 'claimed'];
    } catch (\Throwable $e) {
        $db->prepare(
            "UPDATE fee_waiver_credits SET status = 'empty', amount = NULL, claimed_at = NULL, claimed_by_user_id = NULL WHERE id = ? AND status = 'claimed'"
        )->execute([$creditId]);
        throw $e;
    }
}

/**
 * Maintenance fee: a percentage charge on a student's POS payment or
 * P2P transfer. Like the Fee Waiver Credit it is NOT wallet money — the student's
 * balance is never touched and CirculationEngine/system_settings are never
 * involved. Each row is one tuition-assessment line finance bills later, so the
 * lifecycle is accrued -> collected (or voided when the sale is returned).
 *
 * Call this BEFORE beginTransaction(): CREATE TABLE is DDL and would implicitly
 * commit the payment transaction. It also primes the settings cache for the same
 * reason, so gjc_setting() inside the transaction never runs DDL.
 */
function gjc_ensure_maintenance_fees_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db->exec(
        "CREATE TABLE IF NOT EXISTS maintenance_fees (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_user_id INT UNSIGNED NOT NULL,
            reference_no    VARCHAR(40) NOT NULL,
            source_type     ENUM('payment', 'p2p_transfer') NOT NULL,
            txn_amount      DECIMAL(12,2) NOT NULL,
            fee_amount      DECIMAL(10,2) NOT NULL,
            status          ENUM('accrued', 'collected', 'voided') NOT NULL DEFAULT 'accrued',
            school_year_id  INT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            collected_at    DATETIME NULL,
            collected_by    INT UNSIGNED NULL,
            UNIQUE KEY uq_mfee_ref_student (reference_no, student_user_id),
            INDEX idx_mfee_student_status (student_user_id, status),
            INDEX idx_mfee_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // One row per "Mark collected" action on one student — the receipt finance
    // can point to. maintenance_fees.collection_id links each settled fee to it.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS maintenance_fee_collections (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            receipt_no      VARCHAR(40) NOT NULL,
            student_user_id INT UNSIGNED NOT NULL,
            collected_by    INT UNSIGNED NOT NULL,
            collected_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mfc_receipt (receipt_no),
            INDEX idx_mfc_student (student_user_id),
            INDEX idx_mfc_collected_at (collected_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    if (!in_array('collection_id', gjc_table_columns($db, 'maintenance_fees', true), true)) {
        $db->exec("ALTER TABLE maintenance_fees ADD COLUMN collection_id INT UNSIGNED NULL, ADD INDEX idx_mfee_collection (collection_id)");
    }

    // Fees collected before receipts existed: group them into one legacy
    // receipt per (student, collected_at, collector) so the history is complete.
    $legacy = $db->query(
        "SELECT student_user_id, collected_at, collected_by, COUNT(*) AS n, SUM(fee_amount) AS total
           FROM maintenance_fees
          WHERE status = 'collected' AND collection_id IS NULL AND collected_at IS NOT NULL
          GROUP BY student_user_id, collected_at, collected_by"
    )->fetchAll(PDO::FETCH_ASSOC);
    $writeTotals = gjc_mfc_has_stored_totals($db);
    foreach ($legacy as $g) {
        $db->prepare(
            "INSERT INTO maintenance_fee_collections
                (receipt_no, student_user_id" . ($writeTotals ? ", entry_count, total_amount" : "") . ", collected_by, collected_at)
             VALUES (?, ?, " . ($writeTotals ? "?, ?, " : "") . "?, ?)"
        )->execute(array_merge(
            [gjc_reference('MFR'), (int) $g['student_user_id']],
            $writeTotals ? [(int) $g['n'], (float) $g['total']] : [],
            [(int) ($g['collected_by'] ?? 0), $g['collected_at']]
        ));
        $db->prepare(
            "UPDATE maintenance_fees SET collection_id = ?
              WHERE student_user_id = ? AND status = 'collected' AND collection_id IS NULL
                AND collected_at = ? AND collected_by <=> ?"
        )->execute([(int) $db->lastInsertId(), (int) $g['student_user_id'], $g['collected_at'], $g['collected_by'] !== null ? (int) $g['collected_by'] : null]);
    }

    gjc_settings_all($db);
}

/**
 * Self-declared sales remittance: merchant staff hand over their counted cash
 * and click Remit Sales, which logs a report of everything since the wallet's
 * last remittance and moves the "since" boundary forward — no admin approval,
 * no wallet balance change (see gjc_merchant_unremitted_sales()). Printable
 * via merchant/remittance_report.php.
 */
function gjc_ensure_sales_remittances_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db->exec(
        "CREATE TABLE IF NOT EXISTS sales_remittances (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            merchant_wallet_id  INT UNSIGNED NOT NULL,
            staff_user_id       INT UNSIGNED NOT NULL,
            reference_no        VARCHAR(40) NULL UNIQUE,
            amount              DECIMAL(15,2) NOT NULL,
            txn_count           INT UNSIGNED NOT NULL DEFAULT 0,
            period_start        DATETIME NOT NULL,
            period_end          DATETIME NOT NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_remit_wallet (merchant_wallet_id),
            INDEX idx_remit_wallet_period (merchant_wallet_id, period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // 3NF: merchant_user_id is implied by merchant_wallet_id and is no longer
    // written. Until the CLI contract drops it, it must accept NULL.
    if (!$db->inTransaction()) {
        $nullable = $db->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_remittances'
              AND COLUMN_NAME = 'merchant_user_id'")->fetchColumn();
        if ($nullable === 'NO') {
            $db->exec("ALTER TABLE sales_remittances MODIFY COLUMN merchant_user_id INT UNSIGNED NULL");
        }
    }
}

/** True while maintenance_fee_collections still stores entry_count/total_amount (before the 3NF contract). */
function gjc_mfc_has_stored_totals(PDO $db): bool
{
    return in_array('entry_count', gjc_table_columns($db, 'maintenance_fee_collections'), true);
}

/**
 * Settle every still-accrued fee for one student under a fresh receipt. Runs in
 * the caller's transaction. Returns ['receipt_no', 'entries', 'total'], or null
 * when the student has nothing accrued.
 */
function gjc_collect_maintenance_fees(PDO $db, int $studentUserId, int $collectedBy, ?string $createdBefore = null): ?array
{
    // $createdBefore limits the settle to fees created before that datetime
    // (the auto-collect sweep leaves today's open so a same-day return can still void them).
    $cutoffSql = $createdBefore !== null ? ' AND created_at < ' . $db->quote($createdBefore) : '';

    // Lock first so the receipt total is exactly what flips.
    $sumStmt = $db->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(fee_amount), 0) AS total
           FROM maintenance_fees
          WHERE student_user_id = ? AND status = 'accrued'{$cutoffSql}
            FOR UPDATE"
    );
    $sumStmt->execute([$studentUserId]);
    $sum = $sumStmt->fetch(PDO::FETCH_ASSOC);
    if ((int) $sum['n'] === 0) {
        return null;
    }

    $receiptNo = gjc_reference('MFR');
    // A receipt's count/total are its maintenance_fees rows (collection_id); the
    // stored copies are only written until the 3NF contract drops them.
    $writeTotals = gjc_mfc_has_stored_totals($db);
    $db->prepare(
        "INSERT INTO maintenance_fee_collections
            (receipt_no, student_user_id" . ($writeTotals ? ", entry_count, total_amount" : "") . ", collected_by)
         VALUES (?, ?, " . ($writeTotals ? "?, ?, " : "") . "?)"
    )->execute(array_merge(
        [$receiptNo, $studentUserId],
        $writeTotals ? [(int) $sum['n'], (float) $sum['total']] : [],
        [$collectedBy]
    ));
    $collectionId = (int) $db->lastInsertId();

    $db->prepare(
        "UPDATE maintenance_fees
            SET status = 'collected', collected_at = NOW(), collected_by = ?, collection_id = ?
          WHERE student_user_id = ? AND status = 'accrued'{$cutoffSql}"
    )->execute([$collectedBy, $collectionId, $studentUserId]);

    return ['receipt_no' => $receiptNo, 'entries' => (int) $sum['n'], 'total' => (float) $sum['total']];
}

/**
 * Auto-collect: settle every student's accrued fees from before today, one
 * receipt per student (collected_by 0 = system). Today's fees stay accrued so a
 * same-day return can still void them. Called when the admin wallet loads, so
 * no one has to press "Mark collected". Returns the receipts keyed by student.
 */
function gjc_auto_collect_maintenance_fees(PDO $db): array
{
    $cutoff = date('Y-m-d 00:00:00');
    $ids = $db->query(
        "SELECT DISTINCT student_user_id FROM maintenance_fees
          WHERE status = 'accrued' AND created_at < " . $db->quote($cutoff)
    )->fetchAll(PDO::FETCH_COLUMN);

    $receipts = [];
    foreach ($ids as $sid) {
        $db->beginTransaction();
        try {
            $receipt = gjc_collect_maintenance_fees($db, (int) $sid, 0, $cutoff);
            if ($receipt === null) {
                $db->rollBack();
                continue;
            }
            $db->commit();
            $receipts[(int) $sid] = $receipt;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[auto-collect maintenance fees] ' . $e->getMessage());
        }
    }
    return $receipts;
}

/**
 * The maintenance fee attached to each given transaction reference, keyed by
 * reference_no: ['fee_amount' => float, 'status' => accrued|collected|voided,
 * 'student_user_id' => int, 'collected_at' => ?string, 'receipt_no' => ?string].
 *
 * The fee is a line ON its payment/transfer, not a row of its own, so every
 * transaction view looks it up by the transaction's reference. It is a tuition
 * charge, never wallet money — nothing here touches balances or circulation.
 * Pass $studentUserId to scope to one student (the sender); references with no
 * fee simply have no key.
 */
function gjc_maintenance_fees_by_reference(PDO $db, array $referenceNos, ?int $studentUserId = null): array
{
    $referenceNos = array_values(array_unique(array_filter(array_map('strval', $referenceNos), static fn($r): bool => $r !== '')));
    if (!$referenceNos) {
        return [];
    }
    gjc_ensure_maintenance_fees_schema($db);

    // Chunked so a long history page never builds an enormous IN list.
    $out = [];
    foreach (array_chunk($referenceNos, 500) as $chunk) {
        $sql = "SELECT mf.reference_no, mf.student_user_id, mf.fee_amount, mf.status, mf.collected_at, c.receipt_no
                  FROM maintenance_fees mf
                  LEFT JOIN maintenance_fee_collections c ON c.id = mf.collection_id
                 WHERE mf.reference_no IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")";
        $params = $chunk;
        if ($studentUserId !== null) {
            $sql .= " AND mf.student_user_id = ?";
            $params[] = $studentUserId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['reference_no']] = [
                'fee_amount'      => (float) $r['fee_amount'],
                'status'          => (string) $r['status'],
                'student_user_id' => (int) $r['student_user_id'],
                'collected_at'    => $r['collected_at'],
                'receipt_no'      => $r['receipt_no'],
            ];
        }
    }
    return $out;
}

/** One transaction's fee, or null when it carried none. See gjc_maintenance_fees_by_reference(). */
function gjc_maintenance_fee_for_reference(PDO $db, string $referenceNo, ?int $studentUserId = null): ?array
{
    return gjc_maintenance_fees_by_reference($db, [$referenceNo], $studentUserId)[$referenceNo] ?? null;
}

/** Plain-language state of a fee, for the "Maintenance fee" line on a transaction. */
function gjc_maintenance_fee_state_label(string $status): string
{
    return match ($status) {
        'collected' => 'Billed to tuition · collected',
        'voided'    => 'Voided · sale returned',
        default     => 'Billed to tuition',
    };
}

/**
 * The maintenance fee for a transaction of $amount pesos: the configured
 * percentage, rounded to the nearest centavo, never below ₱0.01 — so every
 * qualifying transaction pays at least one centavo. 0 when the fee is switched
 * off (rate 0) or the amount is not positive.
 */
function gjc_maintenance_fee_for_amount(PDO $db, float $amount): float
{
    $pct = gjc_setting($db, 'maintenance_fee_rate_pct');
    if ($pct <= 0 || $amount <= 0) {
        return 0.0;
    }
    return max(0.01, round($amount * $pct / 100, 2, PHP_ROUND_HALF_UP));
}

/** "0.01%", "1%", "2.5%" — a rate label with no trailing zeros. */
function gjc_fee_rate_label(float $pct): string
{
    return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
}

/**
 * Accrue the maintenance fee for one student transaction. Runs inside the
 * caller's transaction so the fee exists if and only if the payment committed.
 * Idempotent per (reference, student). Returns the fee accrued (0.0 when off).
 */
function gjc_record_maintenance_fee(PDO $db, int $studentUserId, string $referenceNo, string $sourceType, float $txnAmount): float
{
    $fee = gjc_maintenance_fee_for_amount($db, $txnAmount);
    if ($fee <= 0 || $studentUserId <= 0) {
        return 0.0;
    }

    $db->prepare(
        "INSERT IGNORE INTO maintenance_fees
            (student_user_id, reference_no, source_type, txn_amount, fee_amount, school_year_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$studentUserId, $referenceNo, $sourceType, round($txnAmount, 2), $fee, gjc_active_school_year_id($db)]);

    return $fee;
}

/**
 * Void the still-accrued fee attached to a reversed sale. A fee finance has
 * already collected is left alone — that is a billing correction, not automatic.
 */
function gjc_void_maintenance_fee(PDO $db, string $referenceNo): int
{
    $stmt = $db->prepare(
        "UPDATE maintenance_fees SET status = 'voided'
          WHERE reference_no = ? AND status = 'accrued'"
    );
    $stmt->execute([$referenceNo]);
    return $stmt->rowCount();
}

/** Accrued / collected totals for one student — shared by the student and parent views. */
function gjc_student_maintenance_fees(PDO $db, int $studentUserId): array
{
    gjc_ensure_maintenance_fees_schema($db);

    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'accrued'   THEN fee_amount END), 0) AS accrued,
            COALESCE(SUM(CASE WHEN status = 'collected' THEN fee_amount END), 0) AS collected,
            COALESCE(SUM(CASE WHEN status = 'accrued'   THEN 1 END), 0)          AS accrued_count
           FROM maintenance_fees
          WHERE student_user_id = ?"
    );
    $stmt->execute([$studentUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'accrued'       => (float) ($row['accrued'] ?? 0),
        'collected'     => (float) ($row['collected'] ?? 0),
        'accrued_count' => (int) ($row['accrued_count'] ?? 0),
        'rate_pct'      => gjc_setting($db, 'maintenance_fee_rate_pct'),
    ];
}

/**
 * Student notification feed (wallet events + a one-time welcome message).
 * Role-agnostic by design — `user_id` is just a `users` row — but today only
 * student pages read from it.
 */
function gjc_ensure_notifications_schema(PDO $db): void
{
    // Even CREATE TABLE IF NOT EXISTS can implicitly commit a caller's payment.
    if (gjc_table_exists($db, 'notifications')) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Notification schema must be prepared before starting a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            type       VARCHAR(40) NOT NULL DEFAULT 'general',
            icon       VARCHAR(40) NOT NULL DEFAULT 'bell',
            title      VARCHAR(150) NOT NULL,
            message    VARCHAR(500) NOT NULL,
            link       VARCHAR(255) NULL,
            is_read    TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_user (user_id, created_at),
            INDEX idx_notifications_user_unread (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function gjc_notify(PDO $db, int $userId, string $type, string $title, string $message, string $icon = 'bell', ?string $link = null): void
{
    if ($userId <= 0) {
        return;
    }
    gjc_ensure_notifications_schema($db);
    $db->prepare(
        "INSERT INTO notifications (user_id, type, icon, title, message, link) VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$userId, $type, $icon, $title, $message, $link]);
}

/** Same as gjc_notify(), but resolves a student_wallets.id into its owning user first. */
function gjc_notify_wallet(PDO $db, int $studentWalletId, string $type, string $title, string $message, string $icon = 'bell', ?string $link = null): void
{
    $stmt = $db->prepare("SELECT user_id FROM student_wallets WHERE id = ? LIMIT 1");
    $stmt->execute([$studentWalletId]);
    $userId = (int) $stmt->fetchColumn();
    if ($userId > 0) {
        gjc_notify($db, $userId, $type, $title, $message, $icon, $link);
    }
}

/**
 * Tell a stall tenant that rent they handed over has been logged, with the
 * reference number they can quote back.
 *
 * Collecting the money is the operation that matters here: the caller has
 * already committed the payment by the time this runs, so a notification that
 * cannot be written must never bubble up and make a successful collection look
 * like it failed. Everything below is best-effort.
 */
function gjc_notify_rent_payment(
    PDO $db,
    int $merchantUserId,
    float $amount,
    string $period,
    string $referenceNo,
    float $stillOwedThisMonth = 0.0,
    ?string $nextDueDate = null
): void {
    if ($merchantUserId <= 0) {
        return;
    }

    $month = gjc_rent_period_label($period);
    $message = gjc_money_plain($amount) . ' was recorded against your ' . $month .
               ' rent. Reference ' . $referenceNo . '.';

    if ($stillOwedThisMonth > 0.005) {
        $message .= ' That month is still ' . gjc_money_plain($stillOwedThisMonth) . ' short.';
    } elseif ($nextDueDate !== null && strtotime($nextDueDate)) {
        $message .= ' Your next rent is due ' . date('M j, Y', strtotime($nextDueDate)) . '.';
    } else {
        $message .= ' Your rent is fully settled.';
    }

    try {
        gjc_notify(
            $db,
            $merchantUserId,
            'rent',
            'Rent payment recorded',
            $message,
            'receipt',
            MERCHANT_URL . '/dashboard.php'
        );
    } catch (\Throwable $e) {
        // The tenant loses a notification, not their payment.
        error_log('gjc_notify_rent_payment failed for user ' . $merchantUserId . ': ' . $e->getMessage());
    }
}

/**
 * Counterpart to gjc_notify_rent_payment(): a receipt that was keyed by mistake
 * has been removed, so a month the tenant was told was settled is open again.
 */
function gjc_notify_rent_payment_voided(
    PDO $db,
    int $merchantUserId,
    float $amount,
    string $period,
    string $referenceNo
): void {
    if ($merchantUserId <= 0) {
        return;
    }

    try {
        gjc_notify(
            $db,
            $merchantUserId,
            'rent',
            'Rent payment removed',
            'The finance office removed a ' . gjc_money_plain($amount) . ' payment (' . $referenceNo .
            ') that had been recorded for your ' . gjc_rent_period_label($period) .
            ' rent. Please check with them if this was not expected.',
            'receipt',
            MERCHANT_URL . '/dashboard.php'
        );
    } catch (\Throwable $e) {
        error_log('gjc_notify_rent_payment_voided failed for user ' . $merchantUserId . ': ' . $e->getMessage());
    }
}

/**
 * Marks which billing period a lease has already been reminded about, so the
 * "rent due soon" notice fires once per month instead of on every page load
 * that happens to fall inside the reminder window.
 */
function gjc_ensure_rent_reminder_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'merchant_leases')) {
        return;
    }

    if (in_array('rent_reminder_period', gjc_table_columns($db, 'merchant_leases'), true)) {
        return;
    }

    try {
        $db->exec("ALTER TABLE merchant_leases ADD COLUMN rent_reminder_period VARCHAR(7) NULL");
        gjc_table_columns($db, 'merchant_leases', true);
    } catch (\Throwable $ignored) {}
}

/**
 * Heads-up that the next month's rent is coming due. Sent once per billing
 * period; see MerchantTenantDirectory::dispatchRentReminders() for when.
 */
function gjc_notify_rent_due_soon(
    PDO $db,
    int $merchantUserId,
    float $amount,
    string $period,
    string $dueDate,
    int $daysUntil,
    float $alsoOverdue = 0.0
): void {
    if ($merchantUserId <= 0) {
        return;
    }

    $when = $daysUntil === 1 ? 'tomorrow' : 'in ' . $daysUntil . ' days';
    $message = 'Your ' . gjc_money_plain($amount) . ' rent for ' . gjc_rent_period_label($period) .
               ' is due on ' . date('M j, Y', strtotime($dueDate)) . ' — ' . $when . '.';

    if ($alsoOverdue > 0.005) {
        $message .= ' You also have ' . gjc_money_plain($alsoOverdue) . ' still unpaid from earlier months.';
    }

    try {
        gjc_notify(
            $db,
            $merchantUserId,
            'rent',
            'Rent due ' . $when,
            $message,
            'calendar-day',
            MERCHANT_URL . '/dashboard.php'
        );
    } catch (\Throwable $e) {
        error_log('gjc_notify_rent_due_soon failed for user ' . $merchantUserId . ': ' . $e->getMessage());
    }
}

/** "2026-08" -> "August 2026", falling back to the raw label if it is malformed. */
function gjc_rent_period_label(string $period): string
{
    $ts = strtotime($period . '-01');

    return $ts ? date('F Y', $ts) : $period;
}

/** Fires once ever, the moment a user's very first notification would be created. */
function gjc_notify_welcome_if_new(PDO $db, int $userId): void
{
    gjc_ensure_notifications_schema($db);
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() === 0) {
        gjc_notify(
            $db,
            $userId,
            'welcome',
            'Welcome to GenPay!',
            'Your wallet is ready. Top up, pay at the canteen, or send GenCoin to a friend — every action shows up here.',
            'hand-sparkles'
        );
    }
}

function gjc_notifications_unread_count(PDO $db, int $userId): int
{
    gjc_ensure_notifications_schema($db);
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function gjc_notifications_recent(PDO $db, int $userId, int $limit = 20): array
{
    gjc_ensure_notifications_schema($db);
    $limit = max(1, min(100, $limit));
    $stmt = $db->prepare(
        "SELECT id, type, icon, title, message, link, is_read, created_at
           FROM notifications WHERE user_id = ?
          ORDER BY created_at DESC, id DESC LIMIT {$limit}"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Pass $id to mark one notification read (only if it belongs to $userId); omit to mark all read. */
function gjc_notifications_mark_read(PDO $db, int $userId, ?int $id = null): void
{
    gjc_ensure_notifications_schema($db);
    if ($id !== null) {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
    } else {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$userId]);
    }
}

/**
 * School Year Cycles & Balance Lifecycle. school_year_balances is the sole
 * record of a year-end carry-over — rollover() in admin/api/school_years.php
 * never touches CirculationEngine, student_wallets, or system_settings.
 */
function gjc_ensure_school_year_schema(PDO $db): void
{
    // CREATE/ALTER TABLE are DDL — MySQL implicitly commits any open
    // transaction the instant it sees one, before the statement even runs.
    // This must never execute from inside a CirculationEngine transaction
    // (or any other beginTransaction()/commit() block). The static cache
    // below also means it only actually runs once per request either way.
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $db->exec(
        "CREATE TABLE IF NOT EXISTS school_years (
            id               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_year_name VARCHAR(9) NOT NULL,
            start_date       DATE NULL,
            end_date         DATE NULL,
            is_active        TINYINT(1) NOT NULL DEFAULT 0,
            created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sy_name (school_year_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS school_year_balances (
            id                   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_user_id      INT NOT NULL,
            school_year_id       INT NOT NULL,
            starting_balance     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            final_ending_balance DECIMAL(10,2) NULL,
            archived_at          TIMESTAMP NULL,
            created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_student_sy (student_user_id, school_year_id),
            INDEX idx_syb_year (school_year_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    if (gjc_table_exists($db, 'transactions')) {
        $cols = gjc_table_columns($db, 'transactions');
        if (!in_array('school_year_id', $cols, true)) {
            try {
                $db->exec("ALTER TABLE transactions ADD COLUMN school_year_id INT NULL");
            } catch (\Throwable $ignored) {}
        }
        $hasIndex = (bool) $db->query(
            "SHOW INDEX FROM transactions WHERE Key_name = 'idx_txn_sy'"
        )->fetchColumn();
        if (!$hasIndex) {
            try {
                $db->exec("ALTER TABLE transactions ADD INDEX idx_txn_sy (school_year_id, id)");
            } catch (\Throwable $ignored) {}
        }
    }

    if (gjc_table_exists($db, 'student_info')) {
        $cols = gjc_table_columns($db, 'student_info');
        if (!in_array('graduated_at', $cols, true)) {
            try {
                $db->exec("ALTER TABLE student_info ADD COLUMN graduated_at DATETIME NULL");
            } catch (\Throwable $ignored) {}
        }
    }
}

/** '2025-2026' format, second year must be first + 1. No DB CHECK — validated here only. */
function gjc_validate_school_year_name(string $name): bool
{
    if (!preg_match('/^(\d{4})-(\d{4})$/', $name, $m)) {
        return false;
    }
    return ((int) $m[2]) === ((int) $m[1]) + 1;
}

/**
 * The one active school_years.id, or null if none has been activated yet
 * (or the table doesn't exist yet — this deliberately does NOT call
 * gjc_ensure_school_year_schema(), since this is called from inside
 * CirculationEngine::logTransaction() on every money-moving transaction, and
 * running DDL there would implicitly commit the ambient transaction (see the
 * comment on gjc_ensure_school_year_schema()). Schema creation happens
 * separately, from non-transactional pages (admin/school_years.php,
 * student/history.php, parent/student.php). Cached per request.
 */
function gjc_active_school_year_id(PDO $db): ?int
{
    static $cached = false;
    static $cachedId = null;

    if ($cached) {
        return $cachedId;
    }
    $cached = true;

    if (!gjc_table_exists($db, 'school_years')) {
        return $cachedId = null;
    }

    $id = $db->query("SELECT id FROM school_years WHERE is_active = 1 LIMIT 1")->fetchColumn();
    return $cachedId = ($id !== false ? (int) $id : null);
}

/** Display name of the active school year, or null if none is active. Safe to call from any context — see gjc_active_school_year_id(). */
function gjc_active_school_year_name(PDO $db): ?string
{
    if (!gjc_table_exists($db, 'school_years')) {
        return null;
    }
    $name = $db->query("SELECT school_year_name FROM school_years WHERE is_active = 1 LIMIT 1")->fetchColumn();
    return $name !== false ? (string) $name : null;
}

/**
 * Per-year enrollment records. student_enrollments is the authority on who is
 * enrolled in a given school year — the old "not graduated, therefore still
 * enrolled" assumption is gone. It is fed by the registrar's roster CSV today
 * (enrollment_roster_staging, promoted at rollover), and by the school's
 * enrollment system API later, through the same single write path.
 *
 * yr_lvl lives here rather than on student_info because year level is per-year
 * data. student_info only keeps entry_yr_lvl (see gjc_ensure_student_entry_year_schema).
 *
 * Same DDL rules as gjc_ensure_school_year_schema(): CREATE/ALTER implicitly
 * commit, so this must never run from inside an open transaction or any money
 * path. Static-cached, so it only ever runs once per request anyway.
 */
function gjc_ensure_enrollment_schema(PDO $db): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    gjc_ensure_school_year_schema($db);
    gjc_ensure_student_entry_year_schema($db);

    $db->exec(
        "CREATE TABLE IF NOT EXISTS student_enrollments (
            id                 INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_user_id    INT NOT NULL,
            school_year_id     INT NOT NULL,
            status             ENUM('enrolled','not_enrolled','on_leave','dropped','transferred','graduated')
                               NOT NULL DEFAULT 'enrolled',
            yr_lvl             VARCHAR(20) NULL,
            source             ENUM('roster_import','manual','api') NOT NULL DEFAULT 'manual',
            enrolled_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status_changed_at  DATETIME NULL,
            changed_by         INT NULL,
            UNIQUE KEY uq_student_year (student_user_id, school_year_id),
            INDEX idx_enr_year_status (school_year_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Staging holds an uploaded roster so the whole diff can be reviewed before
    // the year is activated. Rollover promotes these rows and clears them.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS enrollment_roster_staging (
            id                INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_year_id    INT NOT NULL,
            batch_id          VARCHAR(36) NOT NULL,
            student_id_number VARCHAR(50) NOT NULL,
            yr_lvl            VARCHAR(20) NULL,
            status            ENUM('enrolled','not_enrolled','on_leave','dropped','transferred','graduated')
                              NOT NULL DEFAULT 'enrolled',
            matched_user_id   INT NULL,
            match_status      ENUM('matched','unmatched') NOT NULL DEFAULT 'unmatched',
            uploaded_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_staging_batch (batch_id),
            INDEX idx_staging_year (school_year_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // status carries the CSV's optional status column through to the promoted
    // student_enrollments row. Guarded ADD so a staging table created before
    // this column existed still picks it up.
    $stagingCols = gjc_table_columns($db, 'enrollment_roster_staging', true);
    if (!in_array('status', $stagingCols, true)) {
        try {
            $db->exec(
                "ALTER TABLE enrollment_roster_staging
                 ADD COLUMN status ENUM('enrolled','not_enrolled','on_leave','dropped','transferred','graduated')
                 NOT NULL DEFAULT 'enrolled' AFTER yr_lvl"
            );
        } catch (\Throwable $ignored) {}
    }
}

/** The student_enrollments.status ENUM, in one place. */
function gjc_enrollment_statuses(): array
{
    return ['enrolled', 'not_enrolled', 'dropped', 'transferred', 'graduated'];
}

/** The student_enrollments.source ENUM. 'api' is reserved for the registrar integration. */
function gjc_enrollment_sources(): array
{
    return ['roster_import', 'manual', 'api'];
}

/**
 * The one write path for student_enrollments. Nothing else in the codebase may
 * INSERT or UPDATE that table directly — the registrar API, when it lands,
 * becomes a fourth caller of this function with $source = 'api', and every
 * status change keeps landing in the audit trail through here.
 *
 * Graduation is recorded in two places on purpose: student_info.graduated_at
 * stays the authoritative flag the exit lock reads, and the 'graduated' row
 * records which year it happened in. They are always written together, here.
 *
 * $yrLvl is optional — null preserves whatever year level the row already had
 * rather than blanking it. $audit = false is for the bulk roster promotion in
 * rollover, which writes one ROSTER_APPLIED summary instead of one audit row
 * per student.
 *
 * No money moves here. is_frozen is the only wallet column this touches.
 */
function gjc_set_enrollment_status(
    PDO $db,
    int $studentUserId,
    int $schoolYearId,
    string $status,
    string $source,
    ?int $changedBy,
    ?string $yrLvl = null,
    bool $audit = true
): bool {
    if ($studentUserId <= 0 || $schoolYearId <= 0) {
        return false;
    }

    $status = strtolower(trim($status));
    $source = strtolower(trim($source));
    if (!in_array($status, gjc_enrollment_statuses(), true)) {
        return false;
    }
    if (!in_array($source, gjc_enrollment_sources(), true)) {
        return false;
    }

    $yrLvl = ($yrLvl !== null && trim($yrLvl) !== '') ? substr(trim($yrLvl), 0, 20) : null;

    // Prior standing, needed to spot a move away from 'graduated'.
    $priorStmt = $db->prepare(
        "SELECT status FROM student_enrollments WHERE student_user_id = ? AND school_year_id = ? LIMIT 1"
    );
    $priorStmt->execute([$studentUserId, $schoolYearId]);
    $priorRaw = $priorStmt->fetchColumn();
    $priorStatus = $priorRaw === false ? null : (string) $priorRaw;

    $gradStmt = $db->prepare("SELECT graduated_at FROM student_info WHERE userID = ? LIMIT 1");
    $gradStmt->execute([$studentUserId]);
    $gradRaw = $gradStmt->fetchColumn();
    $priorGraduatedAt = ($gradRaw === false || $gradRaw === null) ? null : (string) $gradRaw;

    $upsert = $db->prepare(
        "INSERT INTO student_enrollments
            (student_user_id, school_year_id, status, yr_lvl, source, status_changed_at, changed_by)
         VALUES (?, ?, ?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE
            status            = VALUES(status),
            yr_lvl            = COALESCE(VALUES(yr_lvl), yr_lvl),
            source            = VALUES(source),
            status_changed_at = NOW(),
            changed_by        = VALUES(changed_by)"
    );
    $upsert->execute([$studentUserId, $schoolYearId, $status, $yrLvl, $source, $changedBy]);

    if ($status === 'graduated') {
        // graduated_at is only stamped once — a re-run keeps the original date.
        $db->prepare("UPDATE student_info SET graduated_at = NOW() WHERE userID = ? AND graduated_at IS NULL")
            ->execute([$studentUserId]);
        $db->prepare("UPDATE student_wallets SET is_frozen = 1 WHERE user_id = ?")
            ->execute([$studentUserId]);
    } elseif ($priorStatus === 'graduated' || $priorGraduatedAt !== null) {
        // Moving away from graduated reverses both halves. Only reached when the
        // student really was graduated, so a parent-frozen wallet that never
        // graduated is never silently unfrozen here.
        $db->prepare("UPDATE student_info SET graduated_at = NULL WHERE userID = ?")
            ->execute([$studentUserId]);
        $db->prepare("UPDATE student_wallets SET is_frozen = 0 WHERE user_id = ?")
            ->execute([$studentUserId]);
    }

    if ($audit && function_exists('logAudit')) {
        logAudit(
            $db,
            $changedBy ?? $studentUserId,
            gjc_current_role(),
            'ENROLLMENT_STATUS_SET',
            'student_enrollments',
            [
                'student_user_id' => $studentUserId,
                'school_year_id'  => $schoolYearId,
                'status'          => $priorStatus,
                'graduated_at'    => $priorGraduatedAt,
            ],
            [
                'student_user_id' => $studentUserId,
                'school_year_id'  => $schoolYearId,
                'status'          => $status,
                'source'          => $source,
                'yr_lvl'          => $yrLvl,
            ],
            null,
            ['target_id' => $studentUserId]
        );
    }

    return true;
}

/**
 * Three-state wallet gate, replacing the old graduated-or-not binary.
 *
 *   frozen_exit        graduated, or transferred out. Terminal.
 *   frozen_unenrolled  no enrollment row for the active school year, or a row
 *                      that is not 'enrolled'. Reversible — the balance is kept
 *                      indefinitely and stays visible to the student.
 *   active             'enrolled' for the active school year.
 *
 * Never runs DDL and never assumes the enrollment tables exist: this is called
 * from inside open transactions (student/api/checkout.php asks mid-transaction),
 * where DDL would implicitly commit. Same constraint as
 * gjc_active_school_year_id() — schema creation happens on the admin pages.
 *
 * Three bootstrap escapes return 'active' rather than locking the whole student
 * body out of a system nobody has configured yet: no enrollment table, no
 * active school year, and an active year with no roster loaded at all. Once a
 * single enrollment row exists for the active year, the absence of a row for
 * one student is a real answer and means unenrolled.
 */
function gjc_student_wallet_state(PDO $db, int $studentUserId): string
{
    static $cache = [];
    static $yearPopulated = -1;

    if ($studentUserId <= 0) {
        return 'active';
    }
    if (isset($cache[$studentUserId])) {
        return $cache[$studentUserId];
    }

    if (gjc_table_exists($db, 'student_info')) {
        $stmt = $db->prepare("SELECT graduated_at FROM student_info WHERE userID = ? LIMIT 1");
        $stmt->execute([$studentUserId]);
        $graduatedAt = $stmt->fetchColumn();
        if ($graduatedAt !== false && $graduatedAt !== null) {
            return $cache[$studentUserId] = 'frozen_exit';
        }
    }

    $yearId = gjc_active_school_year_id($db);
    if ($yearId === null || !gjc_table_exists($db, 'student_enrollments')) {
        return $cache[$studentUserId] = 'active';
    }

    if ($yearPopulated === -1) {
        $popStmt = $db->prepare("SELECT EXISTS(SELECT 1 FROM student_enrollments WHERE school_year_id = ?)");
        $popStmt->execute([$yearId]);
        $yearPopulated = (int) $popStmt->fetchColumn();
    }
    if ($yearPopulated === 0) {
        return $cache[$studentUserId] = 'active';
    }

    $stmt = $db->prepare(
        "SELECT status FROM student_enrollments WHERE student_user_id = ? AND school_year_id = ? LIMIT 1"
    );
    $stmt->execute([$studentUserId, $yearId]);
    $status = $stmt->fetchColumn();
    if ($status === false) {
        return $cache[$studentUserId] = 'frozen_unenrolled';
    }

    return $cache[$studentUserId] = match ((string) $status) {
        'enrolled'                 => 'active',
        'graduated', 'transferred' => 'frozen_exit',
        default                    => 'frozen_unenrolled',
    };
}

/**
 * Page-level lockout for student HTML pages. Call this right after
 * gjc_require_role(['student']). Do NOT call it on JSON endpoints
 * (student/api/*.php) — a redirect there would break the fetch() caller; those
 * branch on gjc_student_wallet_state() and return a JSON error instead.
 *
 *   frozen_exit        everything redirects to dashboard.php, which tells the
 *                      student to collect what is left as a cheque from finance
 *                      (handled off-system).
 *   frozen_unenrolled  the money-moving pages redirect to dashboard.php, which
 *                      explains the situation. Everything read-only stays open
 *                      and the balance stays visible — it is still their money.
 *
 * The unenrolled case is an allowlist rather than a blocklist so that a money
 * page added later fails closed instead of silently staying reachable.
 */
function gjc_enforce_student_lock(PDO $db): void
{
    if (gjc_current_role() !== 'student') {
        return;
    }

    $state = gjc_student_wallet_state($db, gjc_user_id());
    if ($state === 'active') {
        return;
    }

    $script = basename(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''));

    if ($state === 'frozen_exit') {
        if ($script === 'dashboard.php') {
            return;
        }
        header('Location: ' . STUDENT_URL . '/dashboard.php');
        exit();
    }

    $unenrolledAllowed = [
        'dashboard.php',
        'profile.php',
        'profile_edit.php',
        'history.php',
        'view_transaction.php',
        'security.php',
    ];
    if (in_array($script, $unenrolledAllowed, true)) {
        return;
    }

    header('Location: ' . STUDENT_URL . '/dashboard.php');
    exit();
}

// ─── Merchant compliance documents ───────────────────────────────────────────
// Permits a stall owner uploads from the Business Profile. The awarded stall
// application may already hold a copy (captured at apply time), but owners
// onboarded directly have no application row at all, and permits expire and
// get renewed — so uploads live in their own table, one current file per
// document, and the newest copy of each document wins.

/** The documents a stall is expected to keep on file: key => [label, hint]. */
function gjc_merchant_compliance_docs(): array
{
    return [
        'business_permit'  => ['Business permit', 'Mayor or business permit for the stall'],
        'sanitary_permit'  => ['Sanitary permit', 'Health and sanitation clearance'],
        'clearance'        => ['Barangay clearance', 'Clearance from your barangay'],
        'gjc_requirements' => ['GJC requirements', 'School-specific documents'],
    ];
}

function gjc_ensure_merchant_documents_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS merchant_documents (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            merchant_user_id INT UNSIGNED NOT NULL,
            doc_key          VARCHAR(40)  NOT NULL,
            file_path        VARCHAR(500) NOT NULL,
            original_name    VARCHAR(255) NULL,
            uploaded_by      INT UNSIGNED NOT NULL,
            uploaded_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_merchant_doc (merchant_user_id, doc_key)
        ) ENGINE=InnoDB"
    );
}

/**
 * The current file for each compliance document of one stall owner:
 * key => ['path' => relative path, 'source' => 'upload'|'application',
 *         'uploaded_at' => ?string]. Documents with no file are absent.
 * Pass the awarded application row when the caller already has it.
 */
function gjc_merchant_document_files(PDO $db, int $ownerId, ?array $application = null): array
{
    $files = [];
    foreach (array_keys(gjc_merchant_compliance_docs()) as $key) {
        $path = trim((string) ($application[$key] ?? ''));
        if ($path !== '' && $path !== 'pending_path') {
            $files[$key] = ['path' => $path, 'source' => 'application', 'uploaded_at' => $application['awarded_at'] ?? null];
        }
    }

    if ($ownerId > 0 && gjc_table_exists($db, 'merchant_documents')) {
        $stmt = $db->prepare("SELECT doc_key, file_path, uploaded_at FROM merchant_documents WHERE merchant_user_id = ?");
        $stmt->execute([$ownerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset(gjc_merchant_compliance_docs()[$row['doc_key']])) {
                $files[$row['doc_key']] = ['path' => $row['file_path'], 'source' => 'upload', 'uploaded_at' => $row['uploaded_at']];
            }
        }
    }

    return $files;
}

/** Explicit approved CLI CONTRACT only. Page loads never call this helper. */
function gjc_ensure_stall_application_contract_schema(PDO $db, bool $apply=false): array
{
    if(PHP_SAPI!=='cli'||$db->inTransaction()||$db->query('SELECT DATABASE()')->fetchColumn()!=='ewallet')
        throw new RuntimeException('D2 CONTRACT requires CLI, ewallet and no transaction.');
    $stored=[
        "SELECT VIEW_DEFINITION AS definition FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()",
        "SELECT ACTION_STATEMENT AS definition FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()",
        "SELECT ROUTINE_DEFINITION AS definition FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()",
        "SELECT EVENT_DEFINITION AS definition FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()"
    ];
    foreach($stored as $sql)foreach($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $definition)
        if(preg_match('/\b(stall_applications|archived_rejections)\b/i',(string)$definition))
            throw new RuntimeException('Stored database application dependency requires review.');
    if(gjc_ensure_stall_application_details_schema($db,false)!==[])
        throw new RuntimeException('Complete normalized application schema is required.');
    gjc_table_columns($db,'stall_applications',true);
    $legacy=gjc_stall_application_has_legacy($db);$plan=[];
    if($legacy){
        $m=gjc_stall_application_projection_metadata();
        $expressions=$m['normalized_expressions'];
        $names=[];foreach(['first_name','middle_name','last_name','suffix'] as $field)$names[]="NULLIF((".$expressions[$field]."),'')";
        $expressions['proprietor_name']="TRIM(CONCAT_WS(' ',".implode(',',$names).'))';
        $select=[];foreach($m['logical_columns'] as $field){$value=$expressions[$field]??'sa.'.$field;
            if(in_array($field,$m['actor_fields'],true))$value='CAST(('.$value.') AS SIGNED)';
            $select[]=$value.' AS '.$field;
        }
        $from=' FROM stall_applications sa '.implode(' ',$m['joins']);
        $raw=$db->query('SELECT * FROM stall_applications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $normal=$db->query('SELECT '.implode(',',$select).$from.' ORDER BY sa.id')->fetchAll(PDO::FETCH_ASSOC);
        if($normal!==$raw)throw new RuntimeException('Complete normalized application profiles must match before CONTRACT.');
        $missing=['ap.application_id','mt.application_id','py.application_id','ct.application_id','dc.application_id','ad.application_id','b.id','c.id','p.id'];
        foreach(range(0,4) as $i)$missing[]='nd'.$i.'.application_id';
        if($db->query('SELECT sa.id'.$from.' WHERE '.implode(' OR ',array_map(fn($field)=>$field.' IS NULL',$missing)).' LIMIT 1')->fetchColumn()!==false)
            throw new RuntimeException('Required normalized application details are missing.');
        $events=(int)$db->query("SELECT COUNT(*) FROM stall_applications a WHERE a.status='rejected' AND NOT EXISTS
            (SELECT 1 FROM stall_application_rejections r WHERE r.application_id=a.id
             AND BINARY r.rejection_reason <=> BINARY a.rejection_reason
             AND r.rejected_by <=> a.reviewed_by AND r.rejected_at <=> a.reviewed_at
             AND r.rejected_at_step=a.current_step AND r.reactivated_at IS NULL)")->fetchColumn();
        if($events)throw new RuntimeException('Current rejected applications require retained rejection history.');
        $fks=$db->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        foreach($fks as $fk)if(($fk['TABLE_NAME']==='stall_applications'&&in_array($fk['COLUMN_NAME'],$m['legacy_fields'],true))
            ||($fk['REFERENCED_TABLE_NAME']==='stall_applications'&&in_array($fk['REFERENCED_COLUMN_NAME'],$m['legacy_fields'],true)))
            throw new RuntimeException('Legacy field is still constrained by a foreign key.');
        $plan['stall_applications']='ALTER TABLE stall_applications '.implode(', ',array_map(fn($field)=>'DROP COLUMN '.$field,$m['legacy_fields']));
    }
    if(gjc_table_exists($db,'archived_rejections')){
        if((int)$db->query('SELECT COUNT(*) FROM archived_rejections')->fetchColumn()!==0)
            throw new RuntimeException('Legacy rejection archive is not empty; separate migration required.');
        if((int)$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME='archived_rejections' OR REFERENCED_TABLE_NAME='archived_rejections')")->fetchColumn())
            throw new RuntimeException('Legacy archive has foreign-key dependencies.');
        $plan['archived_rejections']='DROP TABLE archived_rejections';
    }
    if($apply){foreach($plan as $table=>$sql)$db->exec($sql);gjc_table_columns($db,'stall_applications',true);}
    return $plan;
}

/** Explicit approved CLI CONTRACT; defaults to a read-only plan. */
function gjc_ensure_lease_due_date_contract_schema(PDO $db, bool $apply=false): array
{
    if(PHP_SAPI!=='cli'||$db->inTransaction()||$db->query('SELECT DATABASE()')->fetchColumn()!=='ewallet')
        throw new RuntimeException('Lease due-date CONTRACT requires CLI, ewallet and no transaction.');
    if(!gjc_table_exists($db,'merchant_leases')||!gjc_table_exists($db,'merchant_rent_payments'))
        throw new RuntimeException('Existing lease and rent-payment schema is required.');
    $columns=gjc_table_columns($db,'merchant_leases',true);
    if(!in_array('next_due_date',$columns,true))return [];
    $definition=$db->query("SHOW COLUMNS FROM merchant_leases LIKE 'next_due_date'")->fetch(PDO::FETCH_ASSOC);
    if(!$definition||$definition['Type']!=='date'||$definition['Null']!=='NO')
        throw new RuntimeException('Unexpected legacy lease date definition.');
    $stored=[
        "SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()",
        "SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()",
        "SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()",
        "SELECT EVENT_DEFINITION FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()"
    ];
    foreach($stored as $sql)foreach($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $definition)
        if(preg_match('/\bmerchant_leases\b/i',(string)$definition))
            throw new RuntimeException('Stored lease dependency requires separate review.');
    $dependencies=(int)$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
        AND REFERENCED_TABLE_NAME IS NOT NULL AND
        ((TABLE_NAME='merchant_leases' AND COLUMN_NAME='next_due_date')
        OR (REFERENCED_TABLE_NAME='merchant_leases' AND REFERENCED_COLUMN_NAME='next_due_date'))")->fetchColumn();
    if($dependencies)throw new RuntimeException('Lease due date has foreign-key dependencies.');
    if(!class_exists('MerchantTenantDirectory'))require_once __DIR__.'/MerchantTenantDirectory.php';
    $directory=new MerchantTenantDirectory($db);
    $rows=$db->query('SELECT * FROM merchant_leases ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $directory->primePaymentCache(array_column($rows,'id'));
    foreach($rows as $row)if($directory->derivedNextDueDate($row)!==$row['next_due_date'])
        throw new RuntimeException('Lease due-date cache must match the current calculation before CONTRACT.');
    $indexes=$db->query("SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='merchant_leases' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $due=array_values(array_filter($indexes,fn($row)=>$row['COLUMN_NAME']==='next_due_date'));
    if($due!==[['INDEX_NAME'=>'idx_lease_due','COLUMN_NAME'=>'next_due_date','SEQ_IN_INDEX'=>1]]
        ||count(array_filter($indexes,fn($row)=>$row['INDEX_NAME']==='idx_lease_due'))!==1)
        throw new RuntimeException('Unexpected legacy due-date index.');
    $plan=['merchant_leases'=>'ALTER TABLE merchant_leases DROP COLUMN next_due_date'];
    if($apply){$db->exec($plan['merchant_leases']);gjc_table_columns($db,'merchant_leases',true);}
    return $plan;
}

