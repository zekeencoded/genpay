<?php
// CLI regression: a return puts its items back in stock. Temporary tables only;
// nothing real is touched. Run: php tests/return_restock.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

foreach ([
    'merchant_inventory (id INT PRIMARY KEY, merchant_user_id INT, stock_qty INT)',
    'cart_orders (id INT PRIMARY KEY, paid_ref VARCHAR(40), merchant_wallet_id INT, status VARCHAR(20))',
    'cart_order_items (id INT AUTO_INCREMENT PRIMARY KEY, cart_order_id INT, merchant_inventory_id INT NULL, qty INT)',
    'merchant_qr_orders (id INT PRIMARY KEY, paid_ref VARCHAR(40), merchant_wallet_id INT, status VARCHAR(20))',
    'qr_order_items (id INT AUTO_INCREMENT PRIMARY KEY, merchant_qr_order_id INT, merchant_inventory_id INT NULL, qty INT)',
] as $table) {
    $db->exec("CREATE TEMPORARY TABLE {$table} ENGINE=InnoDB");
}
// Stall 7 (wallet 70) owns items 1-3; item 9 belongs to stall 8.
$db->exec("INSERT INTO merchant_inventory VALUES (1, 7, 10), (2, 7, 10), (3, 7, 10), (9, 8, 10)");
$db->exec("INSERT INTO cart_orders VALUES (1, 'CART-A', 70, 'paid'), (2, 'CART-B', 70, 'paid')");
// Item 1 twice (must add 2+3), a deleted product (NULL), and another stall's item.
$db->exec("INSERT INTO cart_order_items (cart_order_id, merchant_inventory_id, qty) VALUES
    (1, 1, 2), (1, 1, 3), (1, NULL, 4), (1, 9, 1), (2, 2, 5)");
$db->exec("INSERT INTO merchant_qr_orders VALUES (1, 'POS-A', 70, 'paid')");
$db->exec("INSERT INTO qr_order_items (merchant_qr_order_id, merchant_inventory_id, qty) VALUES (1, 3, 4)");

$stock = fn() => $db->query('SELECT id, stock_qty FROM merchant_inventory ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$failed = 0;
$check = function (string $name, array $want) use ($stock, &$failed) {
    $ok = $stock() === $want;
    echo ($ok ? 'PASS' : 'FAIL') . ": {$name}" . ($ok ? '' : ' got ' . json_encode($stock())) . "\n";
    $failed += $ok ? 0 : 1;
};

$db->beginTransaction();
try {
    gjc_restock_returned_sale($db, 'CART-A', 70, 7);
    $check('app order: repeated lines summed, deleted and foreign items skipped, other orders untouched',
        [1 => 15, 2 => 10, 3 => 10, 9 => 10]);
    gjc_restock_returned_sale($db, 'POS-A', 70, 7);
    $check('POS order restocked', [1 => 15, 2 => 10, 3 => 14, 9 => 10]);
    gjc_restock_returned_sale($db, 'CART-B', 71, 7);
    $check('another wallet cannot restock this stall', [1 => 15, 2 => 10, 3 => 14, 9 => 10]);
    gjc_restock_returned_sale($db, 'DIRECT-SALE', 70, 7);
    $check('sale with no order is a no-op', [1 => 15, 2 => 10, 3 => 14, 9 => 10]);
} finally {
    $db->rollBack();
}
exit($failed ? 1 : 0);
