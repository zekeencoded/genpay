<?php
// Verify the endpoint's actual queue selection without changing any database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = file_get_contents(__DIR__ . '/../merchant/api/pos.php');
$start = strpos($source, '        usort($orders,');
$end = strpos($source, "        echo json_encode(['success' => true, 'orders'", $start);
if ($start === false || $end === false) throw new RuntimeException('Queue selection block missing');
$orders = [];
for ($id = 1; $id <= 20; $id++) {
    $orders[] = ['uid' => 'pos-' . $id, 'id' => $id, 'status' => 'pending', 'created_at' => '2026-10-11 08:00:00'];
}
for ($id = 21; $id <= 50; $id++) {
    $orders[] = ['uid' => 'pos-' . $id, 'id' => $id, 'status' => 'paid', 'created_at' => '2026-10-11 09:00:00'];
}
$orders[] = ['uid' => 'pos-51', 'id' => 51, 'status' => 'expired', 'created_at' => '2026-10-11 10:00:00'];
eval(substr($source, $start, $end - $start));
$waiting = array_filter($orders, fn($order) => $order['stage'] === 'awaiting_payment');
$paid = array_filter($orders, fn($order) => $order['stage'] === 'served');
if (count($waiting) !== 20 || count($paid) !== 15 || count($orders) !== 35) {
    throw new RuntimeException('History displaced pending orders or inactive orders remained visible');
}
if ($orders[0]['status'] !== 'pending' || $orders[count($orders) - 1]['status'] !== 'paid') {
    throw new RuntimeException('Queue must remain oldest first');
}
echo "PASS: all 20 unpaid orders remain visible alongside limited paid history; expired tickets are excluded.\n";
