<?php
// CLI regression: the public apply form refuses uploads once one network address
// passes the hourly limit. A temporary login_attempts table keeps real rows
// untouched, and no file is ever moved (the limit is checked first).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

function apply_expect(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException("FAIL: {$label}"); }
    echo "PASS: {$label}\n";
}

$db->exec('CREATE TEMPORARY TABLE login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, login_key CHAR(64) NOT NULL,
    failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (login_key, failed_at)) ENGINE=InnoDB');

try {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $key = gjc_throttle_key('apply', '203.0.113.9');
    $rule = gjc_stall_application_upload_rules()['business_permit'];
    $count = static fn(): int => (int) $db->query(
        'SELECT COUNT(*) FROM login_attempts WHERE login_key = ' . $db->quote($key))->fetchColumn();

    $result = gjc_stage_stall_application_upload($db, 'business_permit', null, $rule);
    apply_expect($result['message'] === 'Business Permit is required.', 'under the limit: the upload is validated as usual');
    apply_expect($count() === 1, 'each upload attempt is counted');

    $old = $db->prepare('INSERT INTO login_attempts (login_key, failed_at) VALUES (?, NOW() - INTERVAL 59 MINUTE)');
    for ($i = 1; $i < GJC_APPLY_UPLOADS_PER_HOUR; $i++) { $old->execute([$key]); }
    $result = gjc_stage_stall_application_upload($db, 'business_permit', null, $rule);
    apply_expect(str_starts_with($result['message'], 'Too many uploads'), 'at the hourly limit: uploads are refused');
    apply_expect($count() === GJC_APPLY_UPLOADS_PER_HOUR, 'refused uploads are not counted');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    $result = gjc_stage_stall_application_upload($db, 'business_permit', null, $rule);
    apply_expect($result['message'] === 'Business Permit is required.', 'the limit is per network: other addresses are unaffected');

    echo "Apply upload limit checks passed.\n";
} finally {
    $db->exec('DROP TEMPORARY TABLE IF EXISTS login_attempts');
}
