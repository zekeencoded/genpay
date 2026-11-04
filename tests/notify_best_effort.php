<?php
// CLI regression: notifications and guardian balance alerts never throw, because callers
// run them after money has committed. A broken temporary `notifications` table makes every
// insert fail; nothing real is written. Run: php tests/notify_best_effort.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'); // expected failures are logged
$db->exec('CREATE TEMPORARY TABLE notifications (id INT PRIMARY KEY) ENGINE=InnoDB');
$walletId = (int) $db->query('SELECT id FROM student_wallets ORDER BY id LIMIT 1')->fetchColumn();

$failed = 0;
foreach ([
    'gjc_notify swallows a failed insert' => fn() => gjc_notify($db, 1, 'test', 'T', 'M'),
    'gjc_notify_wallet swallows a failed insert' => fn() => gjc_notify_wallet($db, $walletId, 'test', 'T', 'M'),
    'balance alert swallows a failure' => function () use ($db, $walletId) {
        $db->exec('CREATE TEMPORARY TABLE student_wallets (id INT PRIMARY KEY) ENGINE=InnoDB'); // breaks its SELECT
        gjc_check_parent_balance_alert($db, $walletId);
    },
] as $name => $run) {
    try {
        $run();
        echo "PASS: {$name}\n";
    } catch (Throwable $e) {
        echo "FAIL: {$name} — threw " . $e->getMessage() . "\n";
        $failed++;
    }
}
$db->exec('DROP TEMPORARY TABLE IF EXISTS student_wallets');
$db->exec('DROP TEMPORARY TABLE IF EXISTS notifications');
exit($failed ? 1 : 0);
