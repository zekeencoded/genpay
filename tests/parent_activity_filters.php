<?php
// Connection-local fixtures only; no real transactions are changed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/parent_activity_filters.php';

$db->exec('CREATE TEMPORARY TABLE transactions (id INT PRIMARY KEY, transaction_type VARCHAR(30),
    parent_wallet_id INT, student_wallet_id INT, created_at DATETIME(6))');
$insert = $db->prepare('INSERT INTO transactions VALUES (?, ?, ?, ?, ?)');
foreach ([
    [1, 'cash_in', 7, null, '2026-10-12 10:00:00'],
    [2, 'topup', null, 11, '2026-10-12 11:00:00'],
    [3, 'top_up', null, 12, '2026-10-12 23:59:59.999999'],
    [4, 'cash_in', 8, 99, '2026-10-12 10:00:00'],
    [5, 'allowance', 7, 11, '2026-10-12 12:00:00'],
    [6, 'payment', 7, null, '2026-10-12 13:00:00'],
    [7, 'refund', null, 11, '2026-10-12 14:00:00'],
    [8, 'cash_in', 7, null, '2026-10-13 00:00:00'],
    [9, 'p2p_transfer', null, 11, '2026-10-12 15:00:00'],
    [10, 'merchant_settle', 7, null, '2026-10-12 16:00:00'],
    [11, 'student_withdraw', null, 12, '2026-10-12 17:00:00'],
] as $row) { $insert->execute($row); }

function expectRows(PDO $db, string $wallet, string $type, array $expected): void
{
    [$where, $params] = gjc_parent_activity_where([11, 12], 7, $wallet, $type, '2026-10-12', '2026-10-12');
    $stmt = $db->prepare("SELECT t.id FROM transactions t $where ORDER BY t.id");
    $stmt->execute($params);
    $actual = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($actual !== $expected) {
        throw new RuntimeException("Unexpected rows for $wallet / $type: " . json_encode($actual));
    }
}
expectRows($db, '0', 'topup', [1, 2, 3]);
expectRows($db, 'me', 'topup', [1]);
expectRows($db, '11', 'topup', [2]);
expectRows($db, '12', 'topup', [3]);
expectRows($db, 'me', 'allowance', [5]);
expectRows($db, '11', 'allowance', [5]);
expectRows($db, '0', 'payment', [6]);
expectRows($db, '0', 'refund', [7]);
expectRows($db, '0', 'p2p_transfer', [9]);
expectRows($db, '0', 'encashment', [10]);
expectRows($db, '0', 'student_withdraw', [11]);
expectRows($db, '99', 'topup', [1, 2, 3]); // Invalid selection cannot expose another family.
expectRows($db, 'me', '', [1, 5, 6, 10]);
[$where, $params] = gjc_parent_activity_where([], 0, '0', '', '', '');
$stmt = $db->prepare("SELECT t.id FROM transactions t $where");
$stmt->execute($params);
if ($stmt->fetchAll() !== [] || gjc_parent_activity_date('2026-02-30') !== ''
    || gjc_parent_activity_date('2026-10-12') !== '2026-10-12') {
    throw new RuntimeException('Empty scope or date validation failed.');
}
echo "PASS: type aliases, parent/student scope, allowances, date boundaries, and unauthorized wallet exclusion.\n";
