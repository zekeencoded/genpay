<?php
// Integration check with connection-local fixtures; no real wallets are credited.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/cash_history.php';

$db->exec('CREATE TEMPORARY TABLE student_wallets (id INT PRIMARY KEY, user_id INT)');
$db->exec('CREATE TEMPORARY TABLE parents (id INT PRIMARY KEY, user_id INT)');
$db->exec('CREATE TEMPORARY TABLE parent_wallets (id INT PRIMARY KEY, parent_id INT)');
$db->exec('CREATE TEMPORARY TABLE transactions (id INT PRIMARY KEY, reference_no VARCHAR(40),
    transaction_type VARCHAR(30), student_wallet_id INT, parent_wallet_id INT,
    amount DECIMAL(10,2), top_up_source VARCHAR(20), status VARCHAR(20), created_at DATETIME)');
$db->exec('INSERT INTO student_wallets VALUES (1, 101)');
$db->exec('INSERT INTO parents VALUES (2, 202)');
$db->exec('INSERT INTO parent_wallets VALUES (1, 2)');
$today = $db->query('SELECT CURDATE()')->fetchColumn();
$yesterday = $db->query('SELECT CURDATE() - INTERVAL 1 DAY')->fetchColumn();
$stmt = $db->prepare('INSERT INTO transactions VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, 'STUDENT', 'cash_in', 1, null, 100, 'finance', 'completed', "$today 10:00:00"],
    [2, 'PARENT', 'cash_in', null, 1, 200, 'finance', 'completed', "$today 10:00:00"],
    [3, 'MERCHANT', 'cash_in', 1, null, 50, 'merchant', 'completed', "$today 09:00:00"],
    [4, 'FAILED', 'cash_in', 1, null, 999, 'finance', 'failed', "$today 08:00:00"],
    [5, 'OLD', 'cash_in', 1, null, 999, null, 'completed', "$yesterday 10:00:00"],
    [6, 'PAYMENT', 'payment', 1, null, 999, null, 'completed', "$today 11:00:00"],
    [7, 'ALLOWANCE', 'allowance', 1, 1, 999, null, 'completed', "$today 11:00:00"],
] as $row) { $stmt->execute($row); }
$rows = gjc_recent_cash_in_history($db);
if (array_column($rows, 'reference_no') !== ['PARENT', 'STUDENT', 'MERCHANT', 'FAILED', 'OLD']) {
    throw new RuntimeException('History must include cash-ins only, newest first.');
}
if ((int) $rows[0]['recipient_user_id'] !== 202 || $rows[0]['recipient_role'] !== 'Parent'
    || (int) $rows[1]['recipient_user_id'] !== 101 || $rows[1]['recipient_role'] !== 'Student') {
    throw new RuntimeException('Student and parent wallet owners must resolve separately.');
}
if (gjc_cash_in_loaded_today($db) !== 350.0) {
    throw new RuntimeException('Loaded Today must sum only completed cash-ins today.');
}
for ($id = 8; $id <= 30; $id++) {
    $stmt->execute([$id, 'SEND-' . $id, 'cash_in', 1, null, 1, 'finance', 'completed', "$today 12:00:00"]);
}
$rows = gjc_recent_cash_in_history($db);
if (count($rows) !== 20 || $rows[0]['reference_no'] !== 'SEND-30') {
    throw new RuntimeException('Recent history must retain the latest 20 records.');
}
echo "PASS: student and parent sends, source, ordering, history limit, and Loaded Today.\n";
