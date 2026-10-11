<?php
// Exercise the actual POS detail response and expiry guard without database writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = file_get_contents(__DIR__ . '/../merchant/api/pos.php');
$branch = strpos($source, "if (\$source === 'pos')");
$start = strpos($source, "            if (\$row['status'] === 'pending'", $branch);
$end = strpos($source, '            exit;', $start);
if ($branch === false || $start === false || $end === false) throw new RuntimeException('POS detail response missing');
$block = substr($source, $start, $end - $start);
$db = new class {
    public int $updates = 0;
    public function prepare($sql) { return $this; }
    public function execute($args) { $this->updates++; }
};
$items = [['id' => 1, 'name' => 'Item', 'qty' => 1, 'price' => 30.0]];
$studentName = '';
$orderId = 41;
foreach (['pending', 'paid', 'voided', 'expired', 'overdue'] as $state) {
    $row = ['id' => 41, 'token' => str_repeat('a', 32), 'short_code' => 'ABCDEFGH',
        'description' => '1x Item', 'amount' => 30.0, 'status' => $state === 'overdue' ? 'pending' : $state,
        'created_at' => date('Y-m-d H:i:s'), 'paid_at' => null,
        'expires_at' => date('Y-m-d H:i:s', time() + ($state === 'overdue' ? -60 : 300))];
    ob_start();
    eval($block);
    $response = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    if ($state === 'pending') {
        $payload = json_decode($response['qr_payload'], true, 512, JSON_THROW_ON_ERROR);
        if ($payload !== ['type' => 'payment', 'token' => $row['token']] || $response['short_code'] !== 'ABCD-EFGH') {
            throw new RuntimeException('Pending details must include the original payment token and formatted code');
        }
    } elseif ($response['qr_payload'] !== null || $response['short_code'] !== null) {
        throw new RuntimeException('Inactive orders must not expose a scannable payment QR');
    }
    if ($state === 'overdue' && ($response['status'] !== 'expired' || $db->updates !== 1)) {
        throw new RuntimeException('Overdue order must expire before rendering details');
    }
}
echo "PASS: pending order details expose the existing QR/code; paid, voided, expired, and overdue orders do not.\n";
