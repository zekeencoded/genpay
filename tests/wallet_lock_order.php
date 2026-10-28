<?php
// Static guard: every payment path that checks a student's daily spending limit
// must lock the wallet row first thing in its transaction. Otherwise two requests
// at the same moment can both pass the limit. Reads the source, so it needs no
// database. Run from the CLI: php tests/wallet_lock_order.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$files = ['student/api/transfer.php', 'student/api/checkout.php', 'merchant/api/pos.php'];

$failures = 0;
foreach ($files as $file) {
    $src = file_get_contents($root . '/' . $file);
    $lock = strpos($src, 'gjc_lock_student_wallet(');
    $begin = $lock === false ? false : strrpos(substr($src, 0, $lock), 'beginTransaction()');
    $limit = strpos($src, 'daily_spend_limit');

    $problem = match (true) {
        $lock === false  => 'never locks the wallet',
        $begin === false => 'locks outside a transaction',
        // A query between beginTransaction() and the lock would fix the snapshot too early.
        (bool) preg_match('/->(prepare|query|exec)\(/', substr($src, $begin, $lock - $begin))
                         => 'runs a query before the lock',
        $limit === false || $limit < $lock => 'checks the spending limit before the lock',
        default          => null,
    };
    if ($problem !== null) {
        $failures++;
        echo "FAIL  {$file} {$problem}\n";
    }
}

echo $failures === 0 ? "ok: " . count($files) . " payment paths lock the wallet first\n" : "{$failures} payment path(s) unsafe\n";
exit($failures === 0 ? 0 : 1);
