<?php
// Exercise the checkout guard without connecting to or changing a database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['student', 'parent'] as $role) {
    $source = file_get_contents(__DIR__ . '/../' . $role . '/api/checkout.php');
    $start = strpos($source, '        $scannedOrderReference =');
    $end = strpos($source, "        if ((int) \$order['merchant_user_id']", $start);
    if ($start === false || $end === false) throw new RuntimeException('Order QR guard missing');
    $guard = substr($source, $start, $end - $start);
    $order = ['reference_no' => 'CART-NEW'];
    foreach (['CART-NEW', 'CART-OLD', ''] as $reference) {
        $_POST = ['order_reference' => $reference];
        $rejected = false;
        try { eval($guard); } catch (RuntimeException $error) { $rejected = true; }
        if ($rejected !== ($reference === 'CART-OLD')) {
            throw new RuntimeException($role . ': incorrect order QR validation');
        }
    }
}
echo "PASS: student and parent checkouts reject stale order QRs, accept matching orders, and retain shop QR support.\n";
