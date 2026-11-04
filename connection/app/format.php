<?php

// Escaping, money/GenCoin/date formatting, signed view tokens, references.

function gjc_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function gjc_money($amount): string
{
    return '&#8369;' . number_format((float) $amount, 2);
}

/** Like gjc_money() but with a literal ₱, for non-HTML output (JSON, notifications, logs). */
function gjc_money_plain($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/**
 * The UI's date format (gjc_date / gjc_datetime). Returns an em dash for empty values.
 * Machine formats (query strings, filenames) use date() directly.
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

/** Signed, non-enumerable token for a view URL: base64url("<id>.<hmac>"), namespaced by $scope. */
function gjc_make_view_token(int $id, string $scope = 'record'): string
{
    $id = max(0, $id);
    $sig = substr(hash_hmac('sha256', $scope . ':' . $id, GJC_VIEW_TOKEN_SECRET), 0, 40);
    return rtrim(strtr(base64_encode($id . '.' . $sig), '+/', '-_'), '=');
}

/** Row id from a view token, or null if invalid. $scope must match. */
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

// "50 GC ≈ ₱500.00" for table rows.
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
            6 => 'merchant',
            7 => 'parent',
            8 => 'finance',
            9 => 'student', // faculty use the student portal; gjc_is_faculty() tells them apart
        ][(int) $role] ?? 'guest';
    }

    // legacy alias: anything stored as 'admin' is treated as 'finance'
    if (strtolower((string) $role) === 'admin') {
        return 'finance';
    }

    return strtolower((string) $role);
}

/** Client-safe error message: business-rule exceptions pass through, others (incl. PDO) are logged and hidden. */
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
