<?php
// Exercise the endpoint's creation/reuse block with an in-memory database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
class QrTestDatabase {
    public array $orders = [];
    public int $inserts = 0;
    public function prepare($sql) { return new QrTestStatement($this, $sql); }
    public function beginTransaction() {}
    public function commit() {}
    public function rollBack() {}
    public function lastInsertId() { return $this->inserts; }
}
class QrTestStatement {
    private array $params = [];
    public function __construct(private QrTestDatabase $db, private string $sql) {}
    public function execute($params) {
        $this->params = $params;
        if (str_contains($this->sql, 'INSERT INTO merchant_qr_orders')) {
            [$token, $code, $wallet, $operator, $description, $amount, $expires] = $params;
            $id = ++$this->db->inserts;
            $this->db->orders[$id] = ['id' => $id, 'token' => $token, 'short_code' => $code,
                'wallet' => $wallet, 'created_by' => $operator, 'status' => 'pending', 'expires_at' => $expires];
        }
    }
    public function fetch($mode = null) {
        $row = $this->db->orders[$this->params[0]] ?? false;
        return $row && $row['wallet'] === $this->params[1] ? $row : false;
    }
}
function gjc_has_legacy_wallet_user_ids($db, $table) { return false; }
function gjc_store_order_items($db, $source, $id, $lines) { $db->orders[$id]['lines'] = $lines; }
function gjc_order_item_lines($db, $source, $id) { return $db->orders[$id]['lines']; }
function checkQr($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$source = file_get_contents(__DIR__ . '/../merchant/api/pos.php');
$start = strpos($source, '        // PHP holds the session lock');
$end = strpos($source, '        // Token-only payload:', $start);
checkQr($start !== false && $end !== false, 'Endpoint block missing');
$block = substr($source, $start, $end - $start);
$db = new QrTestDatabase();
$_SESSION = [];
$merchantWallet = ['id' => 7];
$ownerMerchId = 7;
$merchantUserId = 11;
$requestKey = str_repeat('a', 32);
$validatedItems = [['id' => 1, 'name' => 'Item', 'qty' => 2, 'price' => 5.0]];
$serverTotal = 10.0;
$description = '2x Item';
$legacyItemsJson = false;
$token = 'original'; $shortCode = 'ABCDEFGH'; $expiresAt = date('Y-m-d H:i:s', time() + 300);
eval($block);
checkQr($db->inserts === 1, 'Initial generation must create one order');
checkQr($db->orders[1]['created_by'] === 11, 'QR must record the register operator');
$token = 'retry'; $shortCode = 'RETRY123';
eval($block);
checkQr($db->inserts === 1 && $orderId === 1 && $token === 'original', 'Retry must reuse order and QR');
$db->orders[1]['status'] = 'paid';
try {
    eval($block);
    throw new LogicException('Paid draft was recreated');
} catch (RuntimeException $error) {
    checkQr(str_contains($error->getMessage(), 'already paid'), 'Unexpected paid-order error');
}
checkQr($db->inserts === 1, 'Paid draft must not create another order');
$db->orders[1]['status'] = 'expired';
eval($block);
checkQr($db->inserts === 2 && $orderId === 2, 'Expired draft must allow a fresh QR');
$requestKey = str_repeat('b', 32);
eval($block);
checkQr($db->inserts === 3, 'New draft must allow another customer order');
echo "PASS: server reuses pending orders, rejects paid-draft retries, and allows expired/new drafts.\n";
