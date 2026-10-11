<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
gjc_require_role(['merchant']);

$currentUser = gjc_current_user($db);
$ownerMerchId = gjc_merchant_owner_id($db, (int) $currentUser['id']);
$wallet = gjc_merchant_wallet($db, $ownerMerchId);
$currentPage = 'dashboard';
$parts = [];
$params = [];
foreach (['cart' => ['cart_orders', 'reference_no'], 'qr' => ['merchant_qr_orders', 'short_code']] as $source => [$table, $reference]) {
    if (!gjc_table_exists($db, $table)) continue;
    $parts[] = "SELECT id, '{$source}' AS source, {$reference} AS reference, description, amount, created_at
        FROM {$table} WHERE merchant_wallet_id = ? AND status = 'voided'";
    $params[] = (int) $wallet['id'];
}
$union = implode(' UNION ALL ', $parts);
$detailRequested = isset($_GET['token']) || isset($_GET['source']);
$record = null;
$items = [];
$orders = [];
$total = 0;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$pages = 1;
if ($detailRequested) {
    $source = (string) ($_GET['source'] ?? '');
    $id = in_array($source, ['cart', 'qr'], true)
        ? gjc_verify_view_token($_GET['token'] ?? null, 'merchant_voided_' . $source) : null;
    if ($id !== null && $union !== '' && $wallet['id'] > 0) {
        $stmt = $db->prepare("SELECT * FROM ({$union}) voided WHERE id = ? AND source = ? LIMIT 1");
        $stmt->execute([...$params, $id, $source]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($record) $items = gjc_order_item_lines($db, $source, $id);
    }
    if (!$record) http_response_code(404);
} elseif ($union !== '' && $wallet['id'] > 0) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM ({$union}) voided");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("SELECT * FROM ({$union}) voided ORDER BY created_at DESC, source, id DESC LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voided Transactions | GenPay</title>
    <link rel="icon" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=63">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
</head>
<body class="gp-theme">
<div class="merchant-layout">
    <?php require __DIR__ . '/../includes/partials/' . (gjc_is_merchant_staff() ? 'sidebar_merchant_staff.php' : 'sidebar_merchant_admin.php'); ?>
    <main class="merchant-main">
        <?php
        $topbarTitle = 'Voided Transactions';
        $topbarSubtitle = 'Orders cancelled before payment.';
        require __DIR__ . '/../includes/partials/topbar_merchant.php';
        ?>
        <section class="merchant-premium-panel">
            <div class="merchant-panel-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                <div><h3><?= $detailRequested ? 'Voided order details' : 'All Voided Transactions' ?></h3>
                    <p><?= $detailRequested ? 'No payment was completed for this order.' : number_format($total) . ' cancelled orders across all dates' ?></p></div>
                <a class="merchant-view-btn" href="<?= MERCHANT_URL ?>/<?= $detailRequested ? 'voided_transactions.php' : 'dashboard.php' ?>"><?= $detailRequested ? 'All voided transactions' : 'Dashboard' ?></a>
            </div>
            <?php if ($detailRequested && !$record): ?>
                <div class="text-muted py-4">This voided order was not found.</div>
            <?php elseif ($record): ?>
                <div class="d-flex justify-content-between gap-3 flex-wrap mb-3">
                    <div><strong><?= gjc_e($record['reference'] ?: '#' . $record['id']) ?></strong>
                        <div><?= $record['source'] === 'qr' ? 'Walk-in' : 'App pre-order' ?></div>
                        <small class="text-muted">Created <?= gjc_e(date('M j, Y g:i A', strtotime($record['created_at']))) ?></small></div>
                    <span class="badge bg-secondary align-self-start">Voided</span>
                </div>
                <p><?= gjc_e($record['description'] ?: 'Order') ?></p>
                <div class="table-responsive">
                    <table class="table merchant-premium-table align-middle">
                        <thead><tr><th>Item</th><th>Quantity</th><th class="text-end">Unit price</th><th class="text-end">Subtotal</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr><td><?= gjc_e($item['name']) ?></td><td><?= (int) $item['qty'] ?></td>
                                <td class="text-end"><?= gjc_gc_price((float) $item['price']) ?></td>
                                <td class="text-end"><?= gjc_gc_price(round((float) $item['price'] * (int) $item['qty'], 2)) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?><tr><td colspan="4" class="text-muted">No item details recorded.</td></tr><?php endif; ?>
                        </tbody>
                        <tfoot><tr><th colspan="3">Order total</th><th class="text-end"><?= gjc_gc_price((float) $record['amount']) ?></th></tr></tfoot>
                    </table>
                </div>
            <?php elseif (!$orders): ?>
                <div class="text-center text-muted py-4">No voided transactions recorded.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table merchant-premium-table align-middle">
                        <thead><tr><th>Reference</th><th>Order</th><th>Source</th><th class="text-end">Amount</th><th>Created</th><th>Details</th></tr></thead>
                        <tbody><?php foreach ($orders as $order): ?>
                            <tr><td><?= gjc_e($order['reference'] ?: '#' . $order['id']) ?></td>
                                <td><?= gjc_e($order['description'] ?: 'Order') ?></td>
                                <td><?= $order['source'] === 'qr' ? 'Walk-in' : 'App pre-order' ?></td>
                                <td class="text-end text-nowrap"><?= gjc_gc_price((float) $order['amount']) ?></td>
                                <td class="text-nowrap"><?= gjc_e(date('M j, Y g:i A', strtotime($order['created_at']))) ?></td>
                                <td><a class="merchant-view-btn" href="<?= MERCHANT_URL ?>/voided_transactions.php?source=<?= gjc_e($order['source']) ?>&amp;token=<?= urlencode(gjc_make_view_token((int) $order['id'], 'merchant_voided_' . $order['source'])) ?>">View</a></td></tr>
                        <?php endforeach; ?></tbody>
                    </table>
                </div>
                <nav aria-label="Voided transactions pages" class="d-flex justify-content-between align-items-center gap-3 mt-3">
                    <?php if ($page > 1): ?><a class="merchant-view-btn" href="?page=<?= $page - 1 ?>">Previous</a><?php else: ?><span></span><?php endif; ?>
                    <span class="text-muted">Page <?= $page ?> of <?= $pages ?></span>
                    <?php if ($page < $pages): ?><a class="merchant-view-btn" href="?page=<?= $page + 1 ?>">Next</a><?php else: ?><span></span><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>
</html>
