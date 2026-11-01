<?php

// Restricted-product name matching and enforcement.

/** Normalize a product name (case, leetspeak, punctuation, repeats) so bans can't be dodged. */
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

/** Fold Unicode look-alikes to ASCII. Run after mb_strtolower(). */
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

/** Generic words ignored when matching multi-word bans. */
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

/** Distinctive normalized words of a ban name; [] means use whole-phrase matching. */
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

/** Whether $token is in $hay, allowing small typos for longer tokens. */
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

/** Does one restriction match a product name? 'exact' or 'contains' (also matches distinctive words). */
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

/** Check a product name against restricted_products. Returns the ban reason or null. */
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

/** Disable listed items that now match a ban. Returns the count disabled. */
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
