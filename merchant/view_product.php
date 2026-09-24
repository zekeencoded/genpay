<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['merchant']);

/**
 * CRUD "Read": one catalog item, in the shared .uv-* record chrome
 * (assets/css/detail_view.css). Staff and owner both read the same stall,
 * which is what gjc_merchant_owner_id() resolves; the write actions stay in
 * merchant/api/inventory.php and are not reachable from here.
 *
 * The id travels as a signed HMAC token so the URL can't be walked, and the
 * lookup is still scoped to merchant_user_id.
 */
$currentUser = gjc_current_user($db);
$ownerMerchId = gjc_merchant_owner_id($db, (int) $currentUser['id']);
$isMerchAdmin = gjc_is_merchant_admin() || (gjc_current_role() === 'merchant' && !gjc_is_merchant_staff());

$productId = gjc_verify_view_token($_GET['token'] ?? null, 'merchant_product');

$product = null;
if ($productId !== null && $ownerMerchId > 0 && gjc_table_exists($db, 'merchant_inventory')) {
    $stmt = $db->prepare(
        "SELECT * FROM merchant_inventory WHERE id = ? AND merchant_user_id = ? LIMIT 1"
    );
    $stmt->execute([$productId, $ownerMerchId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$product) {
    http_response_code(404);
}

// Local escaper — the shared gjc_e() only casts to string, and this page
// renders merchant-supplied names, descriptions and restriction notes.
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($product) {
    $stockQty = (int) ($product['stock_qty'] ?? 0);
    $minAlert = (int) ($product['min_stock_alert'] ?? 0);
    $isAvailable = (int) ($product['is_available'] ?? 0) === 1;
    $isRestricted = (int) ($product['is_restricted'] ?? 0) === 1;

    // The hero status answers "can a student buy this right now?", so a
    // restriction outranks the merchant's own availability switch.
    if ($isRestricted) {
        $statusClass = 'is-inactive';
        $statusLabel = 'Restricted';
    } elseif (!$isAvailable) {
        $statusClass = 'is-neutral';
        $statusLabel = 'Unavailable';
    } elseif ($stockQty <= 0) {
        $statusClass = 'is-inactive';
        $statusLabel = 'Out of Stock';
    } elseif ($stockQty <= $minAlert) {
        $statusClass = 'is-pending';
        $statusLabel = 'Low Stock';
    } else {
        $statusClass = 'is-active';
        $statusLabel = 'On Sale';
    }

    $stockClass = '';
    if ($stockQty <= 0) {
        $stockClass = 'is-bad';
    } elseif ($stockQty <= $minAlert) {
        $stockClass = 'is-warn';
    }

    $description = trim((string) ($product['description'] ?? ''));
    $sku = trim((string) ($product['sku'] ?? ''));
    $unit = (string) ($product['unit'] ?? 'piece');
    $addedAt = !empty($product['created_at']) ? date('M d, Y h:i A', strtotime((string) $product['created_at'])) : 'N/A';
    $updatedAt = !empty($product['updated_at']) ? date('M d, Y h:i A', strtotime((string) $product['updated_at'])) : 'N/A';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $product ? 'Product Details' : 'Content Not Available' ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=23">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=15">
</head>

<body class="gp-theme">
    <div class="uv-wrap">
        <?php if (!$product): ?>
        <div class="uv-notfound">
            <div class="uv-nf-ic"><i class="fa-regular fa-circle-question"></i></div>
            <h1>This page isn't available</h1>
            <p>The link you followed may be broken, or the item may have been removed from your catalog.</p>
            <a href="<?= MERCHANT_URL ?>/inventory.php" class="gp-btn gp-btn--forest">Go to Inventory</a>
        </div>
        <?php else: ?>
        <div class="uv-topbar">
            <a class="uv-back" href="<?= MERCHANT_URL ?>/inventory.php">
                <i class="fa-solid fa-arrow-left"></i> Back to Inventory
            </a>
        </div>

        <section class="uv-hero">
            <div class="uv-avatar"><i class="fa-solid fa-box"></i></div>
            <div class="uv-hero-main">
                <div class="uv-eyebrow"><?= $e(ucwords((string) ($product['category'] ?? 'general'))) ?></div>
                <h1 class="uv-name"><?= $e($product['product_name']) ?></h1>
                <div class="uv-id-line">
                    <span class="gp-hero-badge"><?= gjc_money($product['price']) ?> / <?= $e($unit) ?></span>
                    <?php if ($sku !== ''): ?>
                    <span class="uv-mono"><?= $e($sku) ?></span>
                    <?php endif; ?>
                    <span class="uv-status <?= $statusClass ?>"><?= $e($statusLabel) ?></span>
                </div>
            </div>
        </section>

        <div class="uv-strip">
            <div class="uv-tile">
                <div class="uv-tile-label">In Stock</div>
                <div class="uv-tile-value <?= $stockClass ?>"><?= number_format($stockQty) ?></div>
                <div class="uv-tile-sub"><?= $e($unit) ?><?= $stockQty === 1 ? '' : 's' ?> on hand</div>
            </div>
            <div class="uv-tile">
                <div class="uv-tile-label">Low-Stock Alert</div>
                <div class="uv-tile-value"><?= number_format($minAlert) ?></div>
                <div class="uv-tile-sub">Warns at or below this level</div>
            </div>
            <div class="uv-tile">
                <div class="uv-tile-label">Unit Price</div>
                <div class="uv-tile-value"><?= gjc_money($product['price']) ?></div>
                <div class="uv-tile-sub"><?= gjc_gc_amount($product['price']) ?> GC per <?= $e($unit) ?></div>
            </div>
            <div class="uv-tile">
                <div class="uv-tile-label">Stock Value</div>
                <div class="uv-tile-value"><?= gjc_money((float) $product['price'] * $stockQty) ?></div>
                <div class="uv-tile-sub">Price &times; quantity on hand</div>
            </div>
        </div>

        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div>
                    <h3>Product details</h3>
                    <p>Read-only view<?= $isMerchAdmin ? ' — edit this item from the Inventory page.' : '.' ?></p>
                </div>
            </div>
            <div class="uv-fields">
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-tag"></i></div>
                    <div>
                        <div class="uv-field-label">SKU</div>
                        <div class="uv-field-value is-mono"><?= $e($sku !== '' ? $sku : 'Not set') ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-layer-group"></i></div>
                    <div>
                        <div class="uv-field-label">Category</div>
                        <div class="uv-field-value"><?= $e(ucwords((string) ($product['category'] ?? 'general'))) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-ruler"></i></div>
                    <div>
                        <div class="uv-field-label">Unit</div>
                        <div class="uv-field-value"><?= $e(ucwords($unit)) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-cart-shopping"></i></div>
                    <div>
                        <div class="uv-field-label">Sellable</div>
                        <div class="uv-field-value"><?= $isAvailable ? 'Yes' : 'No — hidden from the POS' ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-regular fa-calendar-plus"></i></div>
                    <div>
                        <div class="uv-field-label">Added</div>
                        <div class="uv-field-value"><?= $e($addedAt) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-regular fa-pen-to-square"></i></div>
                    <div>
                        <div class="uv-field-label">Last Updated</div>
                        <div class="uv-field-value"><?= $e($updatedAt) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-regular fa-note-sticky"></i></div>
                    <div>
                        <div class="uv-field-label">Description</div>
                        <div class="uv-field-value"><?= $e($description !== '' ? $description : 'None') ?></div>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($isRestricted): ?>
        <!-- Set by the admin restricted-products screen, not by the merchant,
             so the reason is worth showing in full rather than as a pill. -->
        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div>
                    <h3>Campus restriction</h3>
                    <p>This item was flagged by the school administration and cannot be sold.</p>
                </div>
            </div>
            <p class="uv-item-warn">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?= $e(trim((string) ($product['restriction_note'] ?? '')) ?: 'No reason was recorded. Contact the administration for details.') ?>
            </p>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</body>

</html>
