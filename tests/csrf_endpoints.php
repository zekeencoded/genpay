<?php
// Static guard: every state-changing endpoint must refuse requests without the
// session CSRF token. Reads the source, so it runs without a database or session.
// Run from the CLI: php tests/csrf_endpoints.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$endpoints = [
    'admin/api/mint.php', 'admin/api/leases.php', 'admin/api/school_years.php',
    'admin/api/fee_waiver_credits.php', 'admin/api/maintenance_fees.php',
    'admin/api/archived_rejections.php', 'admin/api/restricted_products.php',
    'admin/api/stall_applications.php', 'admin/api/economy.php',
    'admin/api/get_stall_details.php',
    'merchant/api/returns.php', 'merchant/api/topup.php', 'merchant/api/inventory.php',
    'merchant/api/profile.php', 'parent/api/controls.php', 'parent/api/link.php',
    'parent/api/cart.php', 'parent/api/alerts.php', 'student/api/cart.php',
    'api/profile_photo.php',
];

$failures = 0;
foreach ($endpoints as $file) {
    $source = file_get_contents($root . '/' . $file);
    $guarded = str_contains($source, 'gjc_csrf_require_json()') || str_contains($source, 'gjc_csrf_verify()');
    if (!$guarded) {
        $failures++;
        echo "FAIL  {$file} has no CSRF check\n";
    }
}

// The shared helper must refuse a request whose token is missing or wrong.
require_once $root . '/connection/app/users.php';
$_SESSION = ['gjc_csrf_token' => str_repeat('a', 64)];
assert(gjc_csrf_verify(str_repeat('a', 64)) === true);
assert(gjc_csrf_verify('') === false);
assert(gjc_csrf_verify(str_repeat('b', 64)) === false);

echo $failures === 0 ? "ok: " . count($endpoints) . " endpoints guarded\n" : "{$failures} endpoint(s) unguarded\n";
exit($failures === 0 ? 0 : 1);
