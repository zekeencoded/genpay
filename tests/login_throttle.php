<?php
// CLI regression: the sign-in throttle locks one identifier after five failures
// inside 15 minutes. A temporary login_attempts table keeps real rows untouched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

function throttle_expect(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException("FAIL: {$label}"); }
    echo "PASS: {$label}\n";
}

$baseTableExists = static fn(PDO $db): bool => (int) $db->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'login_attempts' AND table_type = 'BASE TABLE'"
)->fetchColumn() === 1;
$hadBaseTable = $baseTableExists($db);

$db->exec('CREATE TEMPORARY TABLE login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    login_key CHAR(64) NOT NULL,
    failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_la_key_time (login_key, failed_at)
)');

try {
    $a = hash('sha256', 'student@example.com');
    $b = hash('sha256', 'other@example.com');

    throttle_expect(!gjc_login_throttled($db, $a), 'no failures: sign-in allowed');
    for ($i = 0; $i < 4; $i++) { gjc_record_login_failure($db, $a); }
    throttle_expect(!gjc_login_throttled($db, $a), 'four failures: still allowed');
    gjc_record_login_failure($db, $a);
    throttle_expect(gjc_login_throttled($db, $a), 'fifth failure: identifier locked');
    throttle_expect(!gjc_login_throttled($db, $b), 'lock is per identifier: other identifiers unaffected');
    gjc_clear_login_failures($db, $a);
    throttle_expect(!gjc_login_throttled($db, $a), 'successful sign-in clears the failures');

    $db->exec('DELETE FROM login_attempts');
    $old = $db->prepare('INSERT INTO login_attempts (login_key, failed_at) VALUES (?, NOW() - INTERVAL 16 MINUTE)');
    for ($i = 0; $i < 5; $i++) { $old->execute([$a]); }
    throttle_expect(!gjc_login_throttled($db, $a), 'failures older than 15 minutes do not count');

    $recent = $db->prepare('INSERT INTO login_attempts (login_key, failed_at) VALUES (?, NOW() - INTERVAL 14 MINUTE)');
    for ($i = 0; $i < 5; $i++) { $recent->execute([$a]); }
    throttle_expect(gjc_login_throttled($db, $a), 'five failures within the last 15 minutes lock the identifier');

    $db->exec('DELETE FROM login_attempts');
    $db->prepare('INSERT INTO login_attempts (login_key, failed_at) VALUES (?, NOW() - INTERVAL 2 DAY)')->execute([$b]);
    gjc_record_login_failure($db, $a);
    $pruned = (int) $db->query("SELECT COUNT(*) FROM login_attempts WHERE login_key = '{$b}'")->fetchColumn();
    throttle_expect($pruned === 0, 'rows older than a day are pruned on the next failure');

    // Custom limit and window: the network sign-in limit and the apply-form limit.
    $db->exec('DELETE FROM login_attempts');
    $ip = gjc_throttle_key('ip', '10.0.0.1');
    for ($i = 0; $i < 99; $i++) { gjc_record_login_failure($db, $ip); }
    throttle_expect(!gjc_login_throttled($db, $ip, 100), '99 failures from one network: still allowed');
    gjc_record_login_failure($db, $ip);
    throttle_expect(gjc_login_throttled($db, $ip, 100), '100 failures from one network: network paused');

    $apply = gjc_throttle_key('apply', '10.0.0.1');
    $halfHour = $db->prepare('INSERT INTO login_attempts (login_key, failed_at) VALUES (?, NOW() - INTERVAL 30 MINUTE)');
    for ($i = 0; $i < 3; $i++) { $halfHour->execute([$apply]); }
    throttle_expect(!gjc_login_throttled($db, $apply, 3), '30-minute-old rows fall outside the default 15-minute window');
    throttle_expect(gjc_login_throttled($db, $apply, 3, 60), 'a 60-minute window counts them');

    throttle_expect(
        gjc_throttle_key('ip', 'x') !== gjc_throttle_key('mint', 'x')
            && gjc_throttle_key('ip', 'x') !== hash('sha256', 'x')
            && strlen(gjc_throttle_key('ip', 'x')) === 64,
        'keys differ per scope and from the sign-in key, and fit the 64-character column'
    );

    throttle_expect($baseTableExists($db) === $hadBaseTable, 'real login_attempts table is left alone');
    echo "Sign-in throttle checks passed.\n";
} finally {
    $db->exec('DROP TEMPORARY TABLE IF EXISTS login_attempts');
}
