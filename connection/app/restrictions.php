<?php

// Restricted-product name matching and enforcement.
// Loaded by connection/app.php; defines functions and constants only.

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
