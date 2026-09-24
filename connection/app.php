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
 * Idempotent self-healer for the restricted-product violation counter —
 * see references/schema.md's gjc_ensure_<feature>_schema() convention.
 */
function gjc_ensure_product_violation_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        return;
    }
    $cols = gjc_table_columns($db, 'users');
    $altered = false;
    if (!in_array('restricted_violation_count', $cols, true)) {
        try {
            $db->exec("ALTER TABLE users ADD COLUMN restricted_violation_count INT UNSIGNED NOT NULL DEFAULT 0");
            $altered = true;
        } catch (\Throwable $ignored) {}
    }
    if (!in_array('restricted_violation_last_at', $cols, true)) {
        try {
            $db->exec("ALTER TABLE users ADD COLUMN restricted_violation_last_at DATETIME NULL");
            $altered = true;
        } catch (\Throwable $ignored) {}
    }
    if (!in_array('restricted_suspended_until', $cols, true)) {
        try {
            $db->exec("ALTER TABLE users ADD COLUMN restricted_suspended_until DATETIME NULL");
            $altered = true;
        } catch (\Throwable $ignored) {}
    }
    if ($altered) {
        // The per-request column cache was filled pre-ALTER; refresh it so
        // checks later in this same request see the new columns.
        gjc_table_columns($db, 'users', true);
    }
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
    if (!in_array('restricted_suspended_until', $cols, true)) {
        return null;
    }
    $idCol = gjc_column($db, 'users', ['id', 'userID']);
    if (!$idCol) {
        return null;
    }

    // Staff inherit their owner's suspension — the whole stall is frozen.
    if (in_array('merchant_owner_id', $cols, true)) {
        $stmt = $db->prepare("SELECT merchant_owner_id FROM users WHERE {$idCol} = ? LIMIT 1");
        $stmt->execute([$userId]);
        $ownerId = (int) $stmt->fetchColumn();
        if ($ownerId > 0) {
            $userId = $ownerId;
        }
    }

    $stmt = $db->prepare("SELECT restricted_suspended_until FROM users WHERE {$idCol} = ? LIMIT 1");
    $stmt->execute([$userId]);
    $until = $stmt->fetchColumn();
    if (!$until) {
        return null;
    }
    if (strtotime((string) $until) <= time()) {
        $db->prepare("UPDATE users SET restricted_suspended_until = NULL WHERE {$idCol} = ?")->execute([$userId]);
        return null;
    }
    return (string) $until;
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

    $db->prepare(
        "UPDATE users
            SET restricted_violation_count = restricted_violation_count + 1,
                restricted_violation_last_at = NOW()
          WHERE userID = ?"
    )->execute([$merchantUserId]);

    $stmt = $db->prepare("SELECT restricted_violation_count FROM users WHERE userID = ?");
    $stmt->execute([$merchantUserId]);
    $count = (int) $stmt->fetchColumn();

    // Finance-tunable policy (admin/settings.php); the GJC_VIOLATION_* constants
    // remain the fallback when no override is stored.
    $warnAt      = gjc_setting_int($db, 'violation_warn_at');
    $riskAt      = gjc_setting_int($db, 'violation_risk_at');
    $suspendDays = gjc_setting_int($db, 'violation_suspend_days');

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
        // $suspendDays is an int cast, so interpolating it here is injection-safe.
        $db->prepare(
            "UPDATE users
                SET restricted_suspended_until = DATE_ADD(NOW(), INTERVAL {$suspendDays} DAY),
                    restricted_violation_count = 0
              WHERE userID = ?"
        )->execute([$merchantUserId]);

        $untilStmt = $db->prepare("SELECT restricted_suspended_until FROM users WHERE userID = ?");
        $untilStmt->execute([$merchantUserId]);
        $untilLabel = date('M d, Y g:i A', strtotime((string) $untilStmt->fetchColumn()));

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
 * Idempotent self-healer for finance-issued account lockouts — the Suspend and
 * Ban actions in admin/users.php. Widens users.status with the 'Suspended' and
 * 'Banned' labels, reading the labels off the live enum rather than a hardcoded
 * list so this never clobbers one a later migration added, then adds the columns
 * that describe a single lockout: which kind it is, when it lifts (NULL =
 * indefinite, and always NULL for a ban), why, who issued it, and the two bits
 * needed to undo it cleanly — the status it interrupted, and whether the wallet
 * freeze was ours to reverse.
 *
 * Suspension and ban share one set of columns because an account can only be in
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

    $cols = gjc_table_columns($db, 'users');
    $altered = false;
    $adds = [
        'suspended_until'         => 'DATETIME NULL',
        'suspension_reason'       => 'VARCHAR(255) NULL',
        'suspended_at'            => 'DATETIME NULL',
        'suspended_by'            => 'INT NULL',
        'suspension_prev_status'  => 'VARCHAR(20) NULL',
        'suspension_froze_wallet' => 'TINYINT(1) NOT NULL DEFAULT 0',
        // Which kind of lockout the columns above currently describe:
        // 'suspension' (timed or indefinite, self-expiring) or 'ban'
        // (permanent, never expires, finance must lift it by hand). NULL on
        // rows written before this column existed — read as suspensions,
        // which is what they were.
        'lockout_kind'            => 'VARCHAR(12) NULL',
    ];
    foreach ($adds as $col => $def) {
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
    // Schema self-healer hasn't run against this database yet — nothing can be
    // suspended, so don't let the missing columns turn into a query error.
    if (!in_array('suspended_until', $cols, true)) {
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
    $cols = gjc_table_columns($db, 'users');
    $kindCol = in_array('lockout_kind', $cols, true) ? 'lockout_kind' : "NULL AS lockout_kind";

    $stmt = $db->prepare(
        "SELECT status, suspended_until, suspension_reason, suspended_at, suspended_by, {$kindCol}
           FROM users WHERE {$idCol} = ? LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $status = $row ? (string) $row['status'] : '';
    if (!$row || !in_array($status, ['Suspended', 'Banned'], true)) {
        return null;
    }

    // users.status is the authority on which kind this is; lockout_kind is only
    // a tiebreak for rows written before the status widened, so a NULL there on
    // a Suspended row still reads as a suspension.
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
    gjc_ensure_account_suspension_schema($db);
    $idCol = gjc_column($db, 'users', ['id', 'userID']) ?: 'userID';
    $hasKind = in_array('lockout_kind', gjc_table_columns($db, 'users'), true);

    $prevStmt = $db->prepare(
        "SELECT status, suspension_prev_status, suspension_froze_wallet
           FROM users WHERE {$idCol} = ? LIMIT 1"
    );
    $prevStmt->execute([$userId]);
    $row = $prevStmt->fetch(PDO::FETCH_ASSOC);
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

    $claim = $db->prepare(
        "UPDATE users
            SET status = ?, suspended_until = ?, suspension_reason = ?,
                suspended_at = NOW(), suspended_by = ?, suspension_prev_status = ?,
                suspension_froze_wallet = ?{$kindSet}
          WHERE {$idCol} = ? AND status NOT IN (" . implode(', ', $blocked) . ")"
    );
    $claim->execute([$target, $until, mb_substr($reason, 0, 255), $actorId, $prev, $frozeWallet, $userId]);
    if ($claim->rowCount() === 0) {
        return false;
    }

    if (gjc_table_exists($db, 'student_wallets')) {
        try {
            $froze = $db->prepare("UPDATE student_wallets SET is_frozen = 1 WHERE user_id = ? AND is_frozen = 0");
            $froze->execute([$userId]);
            if ($froze->rowCount() > 0) {
                $db->prepare("UPDATE users SET suspension_froze_wallet = 1 WHERE {$idCol} = ?")->execute([$userId]);
            }
        } catch (\Throwable $ignored) {}
    }

    return true;
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
    gjc_ensure_account_suspension_schema($db);
    $idCol = gjc_column($db, 'users', ['id', 'userID']) ?: 'userID';
    $isBan = $fromStatus === 'Banned';

    $stmt = $db->prepare(
        "SELECT status, suspended_until, suspension_reason, suspension_prev_status, suspension_froze_wallet
           FROM users WHERE {$idCol} = ? LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
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
    $claim = $db->prepare(
        "UPDATE users
            SET status = ?, suspended_until = NULL, suspension_reason = NULL,
                suspended_at = NULL, suspended_by = NULL,
                suspension_prev_status = NULL, suspension_froze_wallet = 0{$kindSet}
          WHERE {$idCol} = ? AND status = ?"
    );
    $claim->execute([$restored, $userId, $fromStatus]);
    if ($claim->rowCount() === 0) {
        return false;
    }

    if ((int) ($row['suspension_froze_wallet'] ?? 0) === 1 && gjc_table_exists($db, 'student_wallets')) {
        try {
            $db->prepare("UPDATE student_wallets SET is_frozen = 0 WHERE user_id = ?")->execute([$userId]);
        } catch (\Throwable $ignored) {}
    }

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
        $due = $db->query(
            "SELECT {$idCol} FROM users
              WHERE status = 'Suspended'
                AND suspended_until IS NOT NULL
                AND suspended_until <= NOW()"
        )->fetchAll(PDO::FETCH_COLUMN);
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

/**
 * Pipeline status vocabulary: review -> meeting -> down_payment -> approval -> active.
 * Maps 1:1 to the 4-step application pipeline (current_step 1-4).
 */
function gjc_ensure_stall_application_workflow_schema(PDO $db): void
{
    if (!gjc_table_exists($db, "stall_applications")) {
        return;
    }

    $columns = gjc_table_columns($db, "stall_applications");

    // One-time migration from the old pending/awaiting_*/approved chain to the
    // 4-step pipeline. Detected by the absence of the current_step column.
    if (!in_array("current_step", $columns, true)) {
        try {
            $db->exec(
                "ALTER TABLE stall_applications
                 MODIFY stall_id VARCHAR(10) NULL DEFAULT NULL,
                 ADD COLUMN current_step TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER status",
            );
        } catch (Throwable $ignored) {
        }

        // Widen the enum so old and new values coexist while data is migrated.
        try {
            $db->exec(
                "ALTER TABLE stall_applications
                 MODIFY status ENUM(
                    'pending','awaiting_meetup','awaiting_approval','active','rejected','expired',
                    'initially_approved','approved',
                    'review','meeting','down_payment','approval'
                 ) NOT NULL DEFAULT 'review'",
            );
        } catch (Throwable $ignored) {
        }

        $db->exec("UPDATE stall_applications SET status='review', current_step=1 WHERE status='pending'");
        $db->exec("UPDATE stall_applications SET status='meeting', current_step=2 WHERE status IN ('initially_approved','awaiting_meetup')");
        $db->exec("UPDATE stall_applications SET status='approval', current_step=4 WHERE status='awaiting_approval'");
        $db->exec("UPDATE stall_applications SET status='active', current_step=4 WHERE status IN ('approved', 'active')");

        // Early-rejection archiving: anything already rejected moves out of the
        // live pipeline into archived_rejections (step unknown for legacy rows -> 1).
        gjc_ensure_archived_rejections_schema($db);
        $db->exec(
            "INSERT INTO archived_rejections
                (original_application_id, rejected_at_step, business_name, proprietor_name,
                 contact_number, email, profile_picture, business_permit, sanitary_permit,
                 gjc_requirements, clearance, rejection_reason, rejected_by, rejected_at)
             SELECT id, 1, business_name, proprietor_name, contact_number, email,
                    profile_picture, business_permit, sanitary_permit, gjc_requirements, clearance,
                    COALESCE(rejection_reason, 'Rejected (reason not recorded prior to pipeline migration)'),
                    COALESCE(reviewed_by, 0), COALESCE(reviewed_at, updated_at)
             FROM stall_applications WHERE status = 'rejected'",
        );
        $db->exec("DELETE FROM stall_applications WHERE status = 'rejected'");

        // Narrow the enum down to the final 4-step vocabulary.
        try {
            $db->exec(
                "ALTER TABLE stall_applications
                 MODIFY status ENUM('review','meeting','down_payment','approval','active','expired')
                    NOT NULL DEFAULT 'review'",
            );
        } catch (Throwable $ignored) {
        }
    }

    // One-time rename from the earlier "gender" column to "sex".
    if (in_array("gender", $columns, true) && !in_array("sex", $columns, true)) {
        try {
            $db->exec("ALTER TABLE stall_applications CHANGE gender sex ENUM('male','female') NOT NULL DEFAULT 'male'");
            $columns = array_diff($columns, ["gender"]);
            $columns[] = "sex";
        } catch (Throwable $ignored) {
        }
    }

    $adds = [
        "first_name" => "VARCHAR(60) NOT NULL DEFAULT '' AFTER business_name",
        "middle_name" => "VARCHAR(60) NULL DEFAULT NULL AFTER first_name",
        "last_name" => "VARCHAR(60) NOT NULL DEFAULT '' AFTER middle_name",
        "suffix" => "VARCHAR(20) NULL DEFAULT NULL AFTER last_name",
        "sex" => "ENUM('male','female') NOT NULL DEFAULT 'male' AFTER suffix",
        "street" => "VARCHAR(150) NOT NULL DEFAULT '' AFTER sex",
        "barangay" => "VARCHAR(100) NOT NULL DEFAULT '' AFTER street",
        "city" => "VARCHAR(100) NOT NULL DEFAULT '' AFTER barangay",
        "province" => "VARCHAR(100) NOT NULL DEFAULT '' AFTER city",
        "meetup_scheduled_at" => "DATETIME NULL",
        "meetup_location" => "VARCHAR(255) NULL",
        "meetup_notes" => "TEXT NULL",
        "meetup_scheduled_by" => "INT UNSIGNED NULL",
        "meetup_scheduled_email_sent_at" => "DATETIME NULL",
        "down_payment_amount" => "DECIMAL(10,2) NULL",
        "down_payment_reference" => "VARCHAR(80) NULL",
        "down_payment_notes" => "TEXT NULL",
        "down_payment_recorded_by" => "INT UNSIGNED NULL",
        "down_payment_recorded_at" => "DATETIME NULL",
        "merchant_user_id" => "INT UNSIGNED NULL",
        "temp_password_plain" => "VARCHAR(100) NULL",
        "preferred_stall_id" => "VARCHAR(10) NULL DEFAULT NULL",
        // One-stop meeting: signed contract + payment recorded at the meeting.
        "contract_file" => "VARCHAR(500) NULL",
        "contract_uploaded_at" => "DATETIME NULL",
        "contract_uploaded_by" => "INT UNSIGNED NULL",
        "deposit_amount" => "DECIMAL(10,2) NULL",      // 2 months deposit
        "advance_amount" => "DECIMAL(10,2) NULL",      // 1 month advance
        "rental_start_date" => "DATE NULL",
        "payment_schedule_day" => "TINYINT UNSIGNED NULL", // 15 or 30
        "payment_method" => "VARCHAR(20) NULL",        // always 'cash'; no longer shown or chosen in the UI
        "payment_ref_no" => "VARCHAR(60) NULL",        // unused: held GCash/Maya reference numbers
        "awarded_by" => "INT UNSIGNED NULL",
        "awarded_at" => "DATETIME NULL",
        "cancelled_by" => "INT UNSIGNED NULL",
        "cancelled_at" => "DATETIME NULL",
        "cancel_reason" => "TEXT NULL",
        // "New" badge: NULL until an admin first opens the application.
        "first_viewed_at" => "DATETIME NULL",
        "first_viewed_by" => "INT UNSIGNED NULL",
    ];

    foreach ($adds as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $db->exec(
                "ALTER TABLE stall_applications ADD COLUMN {$column} {$definition}",
            );
        }
    }

    // One-stop pipeline: the meeting is auto-scheduled at submission and the
    // old Review/Meeting/Down-Payment stages are collapsed into a single
    // in-person verification meeting. Widen the ENUM (so old values still
    // resolve), remap any legacy rows onto the two-status model, then narrow to
    // the final vocabulary. Idempotent — safe to run on every page load.
    try {
        $db->exec(
            "ALTER TABLE stall_applications
             MODIFY status ENUM(
                'pending_verification','awarded','cancelled','rejected','expired',
                'review','meeting','down_payment','approval','active','pending','approved'
             ) NOT NULL DEFAULT 'pending_verification'",
        );
        // Anything mid-pipeline (submitted, not yet awarded) -> Pending for Verification.
        $db->exec(
            "UPDATE stall_applications
                SET status = 'pending_verification'
              WHERE status IN ('review','meeting','down_payment','approval','pending')",
        );
        // Completed/awarded applications -> Awarded.
        $db->exec(
            "UPDATE stall_applications
                SET status = 'awarded'
              WHERE status IN ('active','approved')",
        );
        // Narrow to the final one-stop vocabulary.
        $db->exec(
            "ALTER TABLE stall_applications
             MODIFY status ENUM('pending_verification','awarded','rejected','cancelled','expired')
                NOT NULL DEFAULT 'pending_verification'",
        );
    } catch (Throwable $ignored) {
    }
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
               FROM stall_applications
              WHERE status = 'pending_verification'
                AND meetup_scheduled_at IS NOT NULL
                AND DATE(meetup_scheduled_at) < CURDATE()",
        )
        ->fetchAll(PDO::FETCH_ASSOC);

    if (!$overdue) {
        return 0;
    }

    $update = $db->prepare(
        "UPDATE stall_applications
            SET status = 'expired', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ?
          WHERE id = ? AND status = 'pending_verification'",
    );

    $expiredCount = 0;
    foreach ($overdue as $app) {
        $meetingAt = new DateTime($app["meetup_scheduled_at"]);
        $reason = "Applicant did not attend the scheduled verification meeting on "
            . $meetingAt->format("M j, Y g:i A") . ".";

        $update->execute([$triggeredBy > 0 ? $triggeredBy : null, $reason, $app["id"]]);
        if ($update->rowCount() === 0) {
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
    $db->exec(
        "CREATE TABLE IF NOT EXISTS archived_rejections (
            id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            original_application_id INT UNSIGNED NOT NULL,
            rejected_at_step         TINYINT UNSIGNED NOT NULL,
            business_name            VARCHAR(120)  NOT NULL,
            proprietor_name          VARCHAR(120)  NOT NULL,
            contact_number           VARCHAR(15)   NOT NULL,
            email                    VARCHAR(255)  NOT NULL,
            profile_picture          VARCHAR(500)  NULL,
            business_permit          VARCHAR(500)  NULL,
            sanitary_permit          VARCHAR(500)  NULL,
            gjc_requirements         VARCHAR(500)  NULL,
            clearance                VARCHAR(500)  NULL,
            rejection_reason         TEXT          NOT NULL,
            rejected_by              INT UNSIGNED  NOT NULL,
            rejected_at              DATETIME      NOT NULL,
            archived_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reactivated              TINYINT(1)    NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_ar_original (original_application_id),
            KEY idx_ar_email    (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );
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
        $db->exec("ALTER TABLE users ADD COLUMN position VARCHAR(60) NULL DEFAULT NULL AFTER sub_role");
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

/** Populate the login session vars for a users row (shared by password login and remember-me). */
function gjc_establish_login_session(array $user): void
{
    $userId = (int) ($user['id'] ?? $user['userID'] ?? 0);
    $roleId = (int) ($user['roleID'] ?? 0);

    $_SESSION['userID'] = $userId;
    $_SESSION['user_id'] = $userId;
    $_SESSION['roleID'] = $roleId;

    // Soft remap to sub_role (no DB changes to roleID); DB sub_role wins.
    $subRole = match ($roleId) {
        1 => 'student',
        2 => 'merchant_admin',
        3 => 'super_admin',
        4 => 'super_admin',
        5 => 'merchant_admin',
        6 => 'merchant_staff',
        7 => 'parent',
        default => 'student',
    };
    if (!empty($user['sub_role'])) {
        $subRole = (string) $user['sub_role'];
    }

    $_SESSION['sub_role'] = $subRole;
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
                    $chk = $db->prepare("SELECT status FROM users WHERE {$idCol} = ? LIMIT 1");
                    $chk->execute([$userId]);
                    if ($chk->fetchColumn() === 'Inactive') {
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

    $cols = gjc_table_columns($db, 'users');
    $altered = false;
    foreach (gjc_user_profile_columns() as $col => $def) {
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
 * honest. Columns absent from $columns are skipped, mirroring how the profile
 * pages already guard their writes against a schema that never healed.
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

    return [
        'values' => array_intersect_key($values, array_flip($columns)),
        'error'  => null,
    ];
}

function gjc_ensure_operational_tables(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS topup_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            student_wallet_id INT UNSIGNED NULL,
            amount DECIMAL(15,2) NOT NULL,
            payment_method VARCHAR(80) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reference_no VARCHAR(40) NULL UNIQUE,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            rejected_by INT UNSIGNED NULL,
            rejected_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_topup_user (user_id),
            INDEX idx_topup_status (status)
        ) ENGINE=InnoDB",
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS encashment_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            merchant_wallet_id INT UNSIGNED NULL,
            amount DECIMAL(15,2) NOT NULL,
            method VARCHAR(80) NOT NULL DEFAULT 'Cashier Release',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reference_no VARCHAR(40) NULL UNIQUE,
            released_by INT UNSIGNED NULL,
            released_at DATETIME NULL,
            rejected_by INT UNSIGNED NULL,
            rejected_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_encash_user (user_id),
            INDEX idx_encash_status (status)
        ) ENGINE=InnoDB",
    );

    $topupAdds = [
        "user_id" => "INT UNSIGNED NULL",
        "student_wallet_id" => "INT UNSIGNED NULL",
        "payment_method" => "VARCHAR(80) NOT NULL DEFAULT 'Cash at Cashier'",
        "reference_no" => "VARCHAR(40) NULL",
        "approved_by" => "INT UNSIGNED NULL",
        "approved_at" => "DATETIME NULL",
        "rejected_by" => "INT UNSIGNED NULL",
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
        "user_id" => "INT UNSIGNED NULL",
        "merchant_wallet_id" => "INT UNSIGNED NULL",
        "method" => "VARCHAR(80) NOT NULL DEFAULT 'Cashier Release'",
        "reference_no" => "VARCHAR(40) NULL",
        "released_by" => "INT UNSIGNED NULL",
        "released_at" => "DATETIME NULL",
        "rejected_by" => "INT UNSIGNED NULL",
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
            user_id INT UNSIGNED NULL,
            student_wallet_id INT UNSIGNED NULL,
            amount DECIMAL(15,2) NOT NULL,
            method VARCHAR(80) NOT NULL DEFAULT 'Cashier Release',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reference_no VARCHAR(40) NULL UNIQUE,
            released_by INT UNSIGNED NULL,
            released_at DATETIME NULL,
            rejected_by INT UNSIGNED NULL,
            rejected_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_withdraw_user (user_id),
            INDEX idx_withdraw_status (status)
        ) ENGINE=InnoDB",
    );

    $withdrawAdds = [
        "user_id" => "INT UNSIGNED NULL",
        "student_wallet_id" => "INT UNSIGNED NULL",
        "method" => "VARCHAR(80) NOT NULL DEFAULT 'Cashier Release'",
        "reference_no" => "VARCHAR(40) NULL",
        "released_by" => "INT UNSIGNED NULL",
        "released_at" => "DATETIME NULL",
        "rejected_by" => "INT UNSIGNED NULL",
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
    // Fee columns on transactions
    foreach ([
        'top_up_source  VARCHAR(20) NULL',
        'base_amount    DECIMAL(15,2) NULL',
        'fee_amount     DECIMAL(15,2) NULL',
        'credited_amount DECIMAL(15,2) NULL',
    ] as $col) {
        try { $db->exec("ALTER TABLE transactions ADD COLUMN $col"); }
        catch (\PDOException $e) { /* already exists */ }
    }

    // Fee columns on topup_requests
    foreach ([
        'top_up_source   VARCHAR(20) NULL',
        'fee_amount      DECIMAL(15,2) NULL',
        'credited_amount DECIMAL(15,2) NULL',
    ] as $col) {
        try { $db->exec("ALTER TABLE topup_requests ADD COLUMN $col"); }
        catch (\PDOException $e) { /* already exists */ }
    }

    // Fee revenue ledger
    $db->exec(
        "CREATE TABLE IF NOT EXISTS fee_revenue_log (
            id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            transaction_ref    VARCHAR(40)  NOT NULL,
            top_up_source      VARCHAR(20)  NOT NULL,
            cash_amount        DECIMAL(15,2) NOT NULL,
            system_fee         DECIMAL(15,2) NOT NULL,
            merchant_fee       DECIMAL(15,2) NOT NULL DEFAULT 0,
            merchant_wallet_id INT UNSIGNED NULL,
            processed_by       INT UNSIGNED NOT NULL,
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fee_ref    (transaction_ref),
            INDEX idx_fee_source (top_up_source),
            INDEX idx_fee_date   (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
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

    $db->prepare("INSERT INTO student_info (userID, studentID, yr_lvl, courseID) VALUES (?, ?, '1', ?)")
       ->execute([$userId, $studentId, $courseId]);
}

function gjc_backfill_student_ids(PDO $db): void
{
    if (!gjc_table_exists($db, 'student_info') || !gjc_table_exists($db, 'users')) {
        return;
    }
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
            merchant_user_id INT UNSIGNED NOT NULL,
            merchant_wallet_id INT UNSIGNED NOT NULL,
            description TEXT NULL,
            items_json TEXT NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            paid_by INT UNSIGNED NULL,
            paid_ref VARCHAR(40) NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mqo_token (token),
            INDEX idx_mqo_status_expiry (status, expires_at),
            INDEX idx_mqo_merchant (merchant_user_id),
            UNIQUE INDEX uq_mqo_short_code (short_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );

    $adds = [
        "short_code" => "VARCHAR(12) NULL AFTER token",
        "description" => "TEXT NULL",
        "items_json" => "TEXT NOT NULL",
        "amount" => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
        "status" => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
        "expires_at" => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        "paid_by" => "INT UNSIGNED NULL",
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
            student_user_id INT UNSIGNED NULL,
            student_wallet_id INT UNSIGNED NULL,
            merchant_user_id INT UNSIGNED NOT NULL,
            merchant_wallet_id INT UNSIGNED NOT NULL,
            buyer_role VARCHAR(10) NOT NULL DEFAULT 'student',
            parent_user_id INT UNSIGNED NULL,
            parent_wallet_id INT UNSIGNED NULL,
            description TEXT NULL,
            items_json TEXT NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            paid_ref VARCHAR(40) NULL,
            INDEX idx_cart_orders_student (student_user_id, status),
            INDEX idx_cart_orders_parent (parent_user_id, status),
            INDEX idx_cart_orders_merchant (merchant_user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Parent shop-cart (2026-08-27). Additive only — existing student rows keep
    // their values and default to buyer_role 'student', so nothing is rewritten.
    $cols = gjc_table_columns($db, 'cart_orders', true);

    if (!in_array('buyer_role', $cols, true)) {
        $db->exec("ALTER TABLE cart_orders ADD COLUMN buyer_role VARCHAR(10) NOT NULL DEFAULT 'student'");
    }
    if (!in_array('parent_user_id', $cols, true)) {
        $db->exec("ALTER TABLE cart_orders ADD COLUMN parent_user_id INT UNSIGNED NULL");
        try { $db->exec("ALTER TABLE cart_orders ADD INDEX idx_cart_orders_parent (parent_user_id, status)"); } catch (\Throwable $ignored) {}
    }
    if (!in_array('parent_wallet_id', $cols, true)) {
        $db->exec("ALTER TABLE cart_orders ADD COLUMN parent_wallet_id INT UNSIGNED NULL");
    }

    // A parent order has no student, so the two student columns must accept
    // NULL. MODIFY only relaxes nullability — existing values are preserved.
    foreach (['student_user_id', 'student_wallet_id'] as $col) {
        try {
            $def = $db->query("SHOW COLUMNS FROM cart_orders LIKE " . $db->quote($col))->fetch(PDO::FETCH_ASSOC);
            if ($def && strtoupper((string) $def['Null']) === 'NO') {
                $db->exec("ALTER TABLE cart_orders MODIFY $col INT UNSIGNED NULL");
            }
        } catch (\Throwable $ignored) {}
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

    if ($userId && gjc_table_exists($db, "wallet")) {
        $userColumn = gjc_column($db, "wallet", ["userID", "user_id"]);
        if ($userColumn) {
            $stmt = $db->prepare(
                "SELECT * FROM wallet WHERE {$userColumn} = ? LIMIT 1",
            );
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($wallet) {
                return [
                    "id" => (int) ($wallet["walletID"] ?? ($wallet["id"] ?? 0)),
                    "balance" => (float) ($wallet["balance"] ?? 0),
                    "source" => "wallet",
                ];
            }
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
 * Sales a stall hasn't remitted yet: everything since the wallet's last
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
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0), COUNT(*) FROM transactions
              WHERE merchant_wallet_id = ?
                AND transaction_type IN ({$placeholders})
                AND created_at > ?",
        );
        $stmt->execute(array_merge([$walletId], $earningTypes, [$since]));
        [$amount, $count] = $stmt->fetch(PDO::FETCH_NUM);
    }

    return ["amount" => (float) $amount, "count" => (int) $count, "since" => $since];
}

/**
 * Sales a stall HAS remitted today: 0 until the staff remits at least once
 * today, then the sum of today's remittance(s). This is the confirmed
 * "Today's Sales" figure the merchant dashboard headlines — see
 * gjc_merchant_unremitted_sales() for the pending amount still waiting on
 * a remittance.
 */
function gjc_merchant_remitted_sales_today(PDO $db, int $walletId): array
{
    $amount = 0.0;
    $count = 0;
    $periodStart = null;
    $periodEnd = null;

    if ($walletId > 0 && gjc_table_exists($db, "sales_remittances")) {
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0), COALESCE(SUM(txn_count), 0), MIN(period_start), MAX(period_end)
               FROM sales_remittances
              WHERE merchant_wallet_id = ? AND DATE(period_end) = CURDATE()",
        );
        $stmt->execute([$walletId]);
        [$amount, $count, $periodStart, $periodEnd] = $stmt->fetch(PDO::FETCH_NUM);
    }

    return [
        "amount" => (float) $amount,
        "count" => (int) $count,
        "period_start" => $periodStart,
        "period_end" => $periodEnd,
    ];
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
           FROM stall_applications
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
    $location = $location ?? gjc_meeting_default_location($db);

    $db->prepare("SELECT GET_LOCK('gjc_stall_scheduler', 10)")->execute();
    try {
        $slot = gjc_find_next_meeting_slot($db);
        if ($slot === null) {
            return null;
        }
        $scheduledAt = $slot['date'] . ' ' . $slot['time'] . ':00';
        $db->prepare(
            "UPDATE stall_applications
                SET meetup_scheduled_at = ?, meetup_location = ?
              WHERE id = ?"
        )->execute([$scheduledAt, $location, $appId]);

        return [
            'date'     => $slot['date'],
            'time'     => $slot['time'],
            'datetime' => $scheduledAt,
            'location' => $location,
        ];
    } finally {
        $db->prepare("SELECT RELEASE_LOCK('gjc_stall_scheduler')")->execute();
    }
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
 * The transactions row itself never carries them: both checkout flows keep an
 * items_json snapshot on their own order row and link back with
 * paid_ref = transactions.reference_no — cart_orders for the in-app Shop Cart,
 * merchant_qr_orders for POS / QR pay. Same join parent/activity.php uses for
 * its Items column, narrowed to a single record.
 *
 * Returns a normalised list of ['name', 'qty', 'price', 'line_total'], or []
 * for a transaction with no order behind it (top-ups, transfers, encashments).
 * merchant_qr_orders items carry no line_total, so it is derived from qty x price.
 */
function gjc_transaction_line_items(PDO $db, string $referenceNo): array
{
    $referenceNo = trim($referenceNo);
    if ($referenceNo === "") {
        return [];
    }

    $json = null;
    foreach (["cart_orders", "merchant_qr_orders"] as $table) {
        if ($json !== null || !gjc_table_exists($db, $table)) {
            continue;
        }
        $stmt = $db->prepare(
            "SELECT items_json FROM {$table} WHERE paid_ref = ? LIMIT 1",
        );
        $stmt->execute([$referenceNo]);
        $found = $stmt->fetchColumn();
        if ($found !== false && $found !== null && $found !== "") {
            $json = (string) $found;
        }
    }

    if ($json === null) {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }
        $qty = max(1, (int) ($item["qty"] ?? 1));
        $price = (float) ($item["price"] ?? 0);
        $items[] = [
            "name" => (string) ($item["name"] ?? "Item"),
            "qty" => $qty,
            "price" => $price,
            "line_total" => isset($item["line_total"])
                ? (float) $item["line_total"]
                : round($price * $qty, 2),
        ];
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
            "SELECT id, reference_no, user_id, student_wallet_id, amount, payment_method, status,
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
            "SELECT id, reference_no, user_id, merchant_wallet_id, amount, method, status,
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

function gjc_p2p_daily_sent(PDO $db, int $userId): float
{
    if (!gjc_table_exists($db, "p2p_transfers")) {
        return 0.0;
    }
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM p2p_transfers
          WHERE from_user_id = ? AND DATE(created_at) = CURDATE() AND status = 'completed'",
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
            user_id               INT UNSIGNED NOT NULL UNIQUE,
            low_balance_threshold DECIMAL(10,2) NOT NULL DEFAULT 50.00,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS parent_student_links (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_id       INT UNSIGNED NOT NULL,
            student_user_id INT UNSIGNED NOT NULL,
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
            student_user_id   INT UNSIGNED NOT NULL,
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
            "SELECT COUNT(*) FROM parent_alerts
              WHERE parent_id = ? AND student_user_id = ?
                AND DATE(created_at) = CURDATE() AND is_read = 0"
        );
        $chk->execute([$parent['parent_id'], $wallet['user_id']]);
        if ((int) $chk->fetchColumn() === 0) {
            $db->prepare(
                "INSERT INTO parent_alerts
                    (parent_id, student_user_id, student_wallet_id, balance_at_alert, threshold)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([
                $parent['parent_id'],
                $wallet['user_id'],
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
            entry_count     INT UNSIGNED NOT NULL,
            total_amount    DECIMAL(12,2) NOT NULL,
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
    foreach ($legacy as $g) {
        $db->prepare(
            "INSERT INTO maintenance_fee_collections
                (receipt_no, student_user_id, entry_count, total_amount, collected_by, collected_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([gjc_reference('MFR'), (int) $g['student_user_id'], (int) $g['n'], (float) $g['total'], (int) ($g['collected_by'] ?? 0), $g['collected_at']]);
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
            merchant_user_id    INT UNSIGNED NOT NULL,
            staff_user_id       INT UNSIGNED NOT NULL,
            reference_no        VARCHAR(40) NULL UNIQUE,
            amount              DECIMAL(15,2) NOT NULL,
            txn_count           INT UNSIGNED NOT NULL DEFAULT 0,
            period_start        DATETIME NOT NULL,
            period_end          DATETIME NOT NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_remit_wallet (merchant_wallet_id),
            INDEX idx_remit_wallet_period (merchant_wallet_id, period_end),
            INDEX idx_remit_owner (merchant_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
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
    $db->prepare(
        "INSERT INTO maintenance_fee_collections
            (receipt_no, student_user_id, entry_count, total_amount, collected_by)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$receiptNo, $studentUserId, (int) $sum['n'], (float) $sum['total'], $collectedBy]);
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
 * data. student_info.yr_lvl is deliberately left in place and untouched.
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
    return ['enrolled', 'not_enrolled', 'on_leave', 'dropped', 'transferred', 'graduated'];
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
