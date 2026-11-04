<?php
// Static test: encashment release claims the request before settling, so a double
// click or a release racing a reject cannot pay out twice. Run: php tests/encashment_release_claim.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$src = file_get_contents(dirname(__DIR__) . '/admin/release_encashment.php');
$claim  = strpos($src, "SET status = 'released'");
$settle = strpos($src, '->merchantSettle(');
$revert = strpos($src, "SET status = 'pending'");
$refNo  = strpos($src, 'SET reference_no = ?');

$checks = [
    'CSRF is required'                         => str_contains($src, 'gjc_csrf_require_json()'),
    'request is claimed before money moves'    => $claim !== false && $settle !== false && $claim < $settle,
    'claim is conditional on pending'          => (bool) preg_match("/SET status = 'released'.*?AND status = 'pending'/s", $src),
    'failed settle hands the request back'     => $revert !== false && $revert > $settle,
    'released request links its ledger row'    => $refNo !== false && $refNo > $settle,
];
$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ": {$name}\n";
    $failed += $ok ? 0 : 1;
}
exit($failed ? 1 : 0);
