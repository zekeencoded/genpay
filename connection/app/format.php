<?php

// Output escaping, money/GenCoin/date formatting, signed view tokens, references.
// Loaded by connection/app.php; defines functions and constants only.

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

function gjc_reference(string $prefix): string
{
    return $prefix .
        "-" .
        date("Ymd") .
        "-" .
        strtoupper(bin2hex(random_bytes(3)));
}

function gjc_token_display(float $phpAmount): string
{
    // Cosmetic display: ₱10 = 1 GenCoin
    $tokens = $phpAmount / 10.0;
    return number_format($tokens, 1) . " GC";
}
