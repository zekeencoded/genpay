<?php
// CLI integration check. All fixture orders live in connection-local temporary tables.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

foreach (['cart_orders' => 'reference_no', 'merchant_qr_orders' => 'short_code'] as $table => $reference) {
    $db->exec("CREATE TEMPORARY TABLE {$table} (
        id INT PRIMARY KEY, merchant_wallet_id INT, {$reference} VARCHAR(50),
        description VARCHAR(150), amount DECIMAL(10,2), status VARCHAR(20), created_at DATETIME
    ) ENGINE=InnoDB");
}
$stmt = $db->prepare('INSERT INTO cart_orders VALUES (?, ?, ?, ?, ?, ?, ?)');
for ($id = 1; $id <= 30; $id++) {
    $stmt->execute([$id, 7, 'CART-' . $id, 'Cancelled app order', 30, 'voided', '2026-10-10 12:00:00']);
}
$stmt->execute([31, 8, 'FOREIGN', 'Different merchant', 30, 'voided', '2026-10-11 12:00:00']);
$stmt->execute([32, 7, 'PAID', 'Paid order', 30, 'paid', '2026-10-11 12:00:00']);
$db->exec("INSERT INTO merchant_qr_orders VALUES
    (1, 7, 'ABCDEFGH', 'Cancelled walk-in', 30, 'voided', '2026-10-11 12:00:00'),
    (2, 7, 'IJKLMNOP', 'Cancelled walk-in', 30, 'voided', '2026-10-11 12:01:00')");
$source = file_get_contents(__DIR__ . '/../merchant/voided_transactions.php');
$start = strpos($source, '$parts = [];');
$end = strpos($source, '?>', $start);
if ($start === false || $end === false) throw new RuntimeException('Voided query block missing');
$block = substr($source, $start, $end - $start);
$wallet = ['id' => 7];
$_GET = [];
eval($block);
if ($total !== 32 || count($orders) !== 25 || $pages !== 2 || $orders[0]['source'] !== 'qr') {
    throw new RuntimeException('Voided list scope, sorting, or pagination failed');
}
$_GET = ['page' => 2];
eval($block);
if (count($orders) !== 7 || $page !== 2) throw new RuntimeException('Second page failed');
$_GET = ['source' => 'cart', 'token' => gjc_make_view_token(31, 'merchant_voided_cart')];
eval($block);
if ($record !== null) throw new RuntimeException('Different merchant order was exposed');
$_GET = ['source' => 'qr', 'token' => gjc_make_view_token(1, 'merchant_voided_cart')];
eval($block);
if ($record !== null) throw new RuntimeException('Token for a different source was accepted');
$_GET = ['source' => 'cart', 'token' => gjc_make_view_token(32, 'merchant_voided_cart')];
eval($block);
if ($record !== null) throw new RuntimeException('Non-voided order was exposed');
echo "PASS: all-date app/POS list, pagination, merchant ownership, source tokens, and voided-only access.\n";
