<?php
// CLI integration check: fixtures exist only in connection-local temporary tables.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
class CirculationEngine {
    const TXN_PAYMENT = 'payment';
}
function gjc_table_exists(PDO $db, string $table): bool { return true; }
function gjc_table_columns(PDO $db, string $table): array { return ['created_by']; }
$source = file_get_contents(__DIR__ . '/../connection/app/merchant.php');
$start = strpos($source, 'function gjc_merchant_unremitted_sales(');
$end = strpos($source, 'function gjc_merchant_owner_id(', $start);
eval(substr($source, $start, $end - $start));
$db->exec('CREATE TEMPORARY TABLE users (userID INT PRIMARY KEY, merchant_owner_id INT)');
$db->exec('CREATE TEMPORARY TABLE merchant_wallets (id INT PRIMARY KEY, user_id INT)');
$db->exec('CREATE TEMPORARY TABLE merchant_qr_orders (merchant_wallet_id INT, paid_ref VARCHAR(40), created_by INT)');
$db->exec('CREATE TEMPORARY TABLE transactions (reference_no VARCHAR(40), merchant_wallet_id INT,
    initiated_by INT, amount DECIMAL(10,2), transaction_type VARCHAR(30), status VARCHAR(20), created_at DATETIME)');
$db->exec('CREATE TEMPORARY TABLE sales_remittances (merchant_wallet_id INT, period_start DATETIME, period_end DATETIME)');
$db->exec('INSERT INTO users VALUES (10, NULL), (11, 10), (12, NULL), (21, 20)');
$db->exec('INSERT INTO merchant_wallets VALUES (7, 10), (8, 20)');
$stmt = $db->prepare("INSERT INTO transactions VALUES (?, ?, ?, ?, ?, ?, ?)");
$day = date('Y-m-d');
foreach ([['ADMIN',7,10,100,'payment','completed'], ['STAFF',7,11,40,'payment','completed'],
    ['ADMIN-QR',7,12,50,'payment','completed'], ['STAFF-QR',7,12,30,'payment','completed'],
    ['DIRECT',7,12,20,'payment','completed'], ['CART-PREORDER',7,12,40,'payment','completed'],
    ['FAILED',7,10,999,'payment','failed'], ['OTHER',8,21,999,'payment','completed']] as $row) {
    $stmt->execute([...$row, "$day 09:00:00"]);
}
$db->exec("INSERT INTO merchant_qr_orders VALUES (7, 'ADMIN-QR', 10), (7, 'STAFF-QR', 11)");
$stmt->execute(['YESTERDAY',7,10,999,'payment','completed',date('Y-m-d 09:00:00', strtotime('-1 day'))]);
function checkSales(PDO $db, float $credited, float $pending, int $count): void {
    $today = gjc_merchant_remitted_sales_today($db, 7);
    $waiting = gjc_merchant_unremitted_sales($db, 7);
    if ($today['amount'] !== $credited || $today['count'] !== $count || $waiting['amount'] !== $pending) {
        throw new RuntimeException('Unexpected sales credit: ' . json_encode([$today, $waiting]));
    }
    $predicate = gjc_merchant_confirmed_sale_sql($db);
    $total = $db->query("SELECT SUM(t.amount) FROM transactions t WHERE t.merchant_wallet_id = 7
        AND t.transaction_type = 'payment' AND t.status = 'completed'
        AND DATE(t.created_at) = CURDATE() AND {$predicate}")->fetchColumn();
    if ((float) $total !== $credited) throw new RuntimeException('Sales list and summary differ');
}
checkSales($db, 170.0, 110.0, 3);
$db->exec("INSERT INTO sales_remittances VALUES (7, '$day 00:00:00', '$day 10:00:00')");
checkSales($db, 280.0, 0.0, 6);
$db->exec("INSERT INTO sales_remittances VALUES (7, '$day 00:00:00', '$day 11:00:00')");
checkSales($db, 280.0, 0.0, 6);
echo "PASS: admin and direct sales credit immediately; staff sales and app pre-orders require remittance; overlapping remittances do not double count.\n";
