<?php
// ============================================================
//  merchant/print_menu.php
//  Printable cardboard-menu sheet: every available product with its
//  scan QR (encodes the SKU, same value student/api/cart.php looks up),
//  plus the merchant's static Wallet QR for checkout.
// ============================================================
session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['merchant']);

$currentUser = gjc_current_user($db);
$ownerMerchId = gjc_merchant_owner_id($db, (int) $currentUser['id']);
$wallet = gjc_merchant_wallet($db, $ownerMerchId);
$currentPage = 'inventory';

$stmt = $db->prepare(
    "SELECT m.stall_name, m.stall_id, s.label AS stall_label
       FROM merchant m
       LEFT JOIN stalls s ON s.stall_id = m.stall_id
      WHERE m.userID = ?
      LIMIT 1"
);
$stmt->execute([$ownerMerchId]);
$merchant = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$merchantName = $merchant['stall_name'] ?? $currentUser['name'];

$items = [];
if (gjc_table_exists($db, 'merchant_inventory')) {
    $stmt = $db->prepare(
        "SELECT sku, product_name, description, category, price
           FROM merchant_inventory
          WHERE merchant_user_id = ? AND is_available = 1 AND is_restricted = 0 AND sku IS NOT NULL AND sku != ''
          ORDER BY category ASC, product_name ASC"
    );
    $stmt->execute([$ownerMerchId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[$row['category']][] = $row;
    }
}

$skuLessCount = 0;
if (gjc_table_exists($db, 'merchant_inventory')) {
    $skuStmt = $db->prepare(
        "SELECT COUNT(*) FROM merchant_inventory
          WHERE merchant_user_id = ? AND is_available = 1 AND is_restricted = 0 AND (sku IS NULL OR sku = '')"
    );
    $skuStmt->execute([$ownerMerchId]);
    $skuLessCount = (int) $skuStmt->fetchColumn();
}

$walletQrPayload = json_encode([
    'type' => 'merchant_wallet',
    'merchant_wallet_id' => $wallet['id'],
    'merchant_user_id' => $ownerMerchId,
    'merchant' => $merchantName,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$walletQrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&ecc=H&margin=10&data=' . rawurlencode($walletQrPayload);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Menu | <?= gjc_e($merchantName) ?></title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=62">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/print_menu.css?v=7">
</head>
<body>
<div class="merchant-layout">
    <?php require __DIR__ . '/../includes/partials/' . (gjc_is_merchant_staff() ? 'sidebar_merchant_staff.php' : 'sidebar_merchant_admin.php'); ?>

    <main class="merchant-main">
        <div class="menu-print-toolbar">
            <div>
                <h1 class="mb-0" style="font-size:22px">Print Full Menu</h1>
                <p class="text-muted mb-0">Every available item with its scan QR, ready for your cardboard menu.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= MERCHANT_URL ?>/inventory.php" class="btn btn-outline-secondary">Back to Inventory</a>
                <button type="button" class="btn btn-success" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Menu</button>
            </div>
        </div>

        <?php if ($skuLessCount > 0): ?>
        <div class="alert alert-warning menu-skuless-note">
            &#9888; <?= $skuLessCount ?> available item(s) are missing a SKU and were left off this menu &mdash;
            add a SKU to each in <a href="<?= MERCHANT_URL ?>/inventory.php">Inventory</a> so students can scan them.
        </div>
        <?php endif; ?>

        <div class="menu-sheet">
            <div class="menu-sheet-header">
                <h1><?= gjc_e($merchantName) ?></h1>
                <p><?= !empty($merchant['stall_id']) ? 'Stall ' . gjc_e($merchant['stall_id']) : '' ?><?= !empty($merchant['stall_label']) ? ' &middot; ' . gjc_e($merchant['stall_label']) : '' ?></p>
                <p>Scan an item's QR with the GenPay Shop Cart, then scan the Wallet QR below to pay.</p>
            </div>

            <?php if (empty($items)): ?>
            <p class="text-center text-muted py-5">No available products with a SKU yet. Add SKUs in Inventory first.</p>
            <?php else: ?>
                <?php foreach ($items as $category => $catItems): ?>
                <div class="menu-category-title"><?= gjc_e(ucwords($category)) ?></div>
                <div class="menu-item-grid">
                    <?php foreach ($catItems as $item): ?>
                    <?php $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&ecc=H&margin=8&data=' . rawurlencode($item['sku']); ?>
                    <div class="menu-item-card">
                        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="<?= gjc_e($item['product_name']) ?> QR">
                        <div class="menu-item-name"><?= gjc_e($item['product_name']) ?></div>
                        <?php if ($item['description']): ?>
                        <div class="menu-item-desc"><?= gjc_e($item['description']) ?></div>
                        <?php endif; ?>
                        <div class="menu-item-price gc-price">
                            <span class="gc-price-main"><?= gjc_gc_amount($item['price']) ?> GC</span>
                            <span class="gc-price-sub">&asymp; <?= gjc_money($item['price']) ?></span>
                        </div>
                        <div class="menu-item-sku"><?= gjc_e($item['sku']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="menu-checkout-box">
                <img src="<?= htmlspecialchars($walletQrImageUrl) ?>" alt="Shop Wallet QR">
                <div class="menu-checkout-text">
                    <strong>Step 2: Pay your total</strong>
                    <span>Scan this Wallet QR in the Shop Cart's "Pay Now" mode once you're done adding items.</span>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>
</html>
