<?php
session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['merchant']);
$currentUser    = gjc_current_user($db);
$merchantUserId = $currentUser['id'];
$ownerMerchId   = gjc_merchant_owner_id($db, $merchantUserId);
$isStaff        = gjc_is_merchant_staff();
$isMerchAdmin   = gjc_is_merchant_admin() || (gjc_current_role() === 'merchant' && !$isStaff); // legacy merchant = admin
$currentPage    = 'inventory';

// Fetch inventory for this merchant
$inventory = [];
if (gjc_table_exists($db, 'merchant_inventory')) {
    $stmt = $db->prepare(
        "SELECT * FROM merchant_inventory WHERE merchant_user_id = ? ORDER BY category ASC, product_name ASC"
    );
    $stmt->execute([$ownerMerchId]);
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$categories = ['food', 'beverage', 'snack', 'supplies', 'service', 'general'];
$units       = ['piece', 'pack', 'bottle', 'can', 'cup', 'kg', 'gram', 'litre', 'serving', 'set'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=53">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=30">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="gp-theme">
<div class="merchant-layout">
    <?php require __DIR__ . '/../includes/partials/' . (gjc_is_merchant_staff() ? 'sidebar_merchant_staff.php' : 'sidebar_merchant_admin.php'); ?>

    <main class="merchant-main">
        <?php
        $topbarTitle = 'Product Inventory';
        $topbarSubtitle = $isMerchAdmin ? 'Manage your full product catalog.' : 'Update stock levels for available items.';
        require __DIR__ . '/../includes/partials/topbar_merchant.php';
        ?>

        <section class="merchant-premium-panel">
            <div class="merchant-panel-header d-flex justify-content-between align-items-center">
                <div><h3>Product Catalog</h3><p><?= count($inventory) ?> items on file.</p></div>
                <div class="d-flex gap-2">
                    <a href="<?= MERCHANT_URL ?>/print_menu.php" class="btn btn-outline-success">
                        <i class="fa-solid fa-print"></i> Print Full Menu
                    </a>
                    <?php if ($isMerchAdmin): ?>
                    <button class="merchant-view-btn" data-bs-toggle="modal" data-bs-target="#addProductModal">+ Add Product</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php
            $lowStock   = array_filter($inventory, function ($i) {
                return $i['stock_qty'] <= $i['min_stock_alert'] && $i['is_available'];
            });
            $restricted = array_filter($inventory, function ($i) {
                return $i['is_restricted'];
            });
            if ($lowStock): ?>
            <div class="alert alert-warning" style="margin:0 0 16px;border-radius:10px;font-size:13px">
                &#9888; <strong><?= count($lowStock) ?> item(s)</strong> are at or below minimum stock levels.
            </div>
            <?php endif; ?>
            <?php if ($restricted): ?>
            <div class="alert alert-danger" style="margin:0 0 16px;border-radius:10px;font-size:13px">
                &#128683; <strong><?= count($restricted) ?> item(s)</strong> are flagged as restricted by admin policy.
            </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table merchant-premium-table align-middle js-datatable" id="inventoryTable" data-page-length="10" data-empty-message="No products found.">
                    <thead>
                        <tr>
                            <th data-card="hide">SKU</th>
                            <th data-card="title">Product</th>
                            <th data-card="hide">Category</th>
                            <th data-card="hide">Unit</th>
                            <th data-card="amount">Price</th>
                            <th data-card="hide">Stock</th>
                            <th>Stock Level</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($inventory)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-5">No products yet. <?= $isMerchAdmin ? 'Add your first item.' : 'Contact your store admin.' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($inventory as $item): ?>
                        <?php
                            // Stock tier off the item's own Min Alert:
                            // low = at/below it, medium = up to 3x, high = above.
                            $qty      = (int) $item['stock_qty'];
                            $minAlert = (int) $item['min_stock_alert'];
                            $isLow    = $qty <= $minAlert;
                            $tier     = $isLow ? 'low' : ($qty <= $minAlert * 3 ? 'medium' : 'high');
                        ?>
                        <tr class="<?= $item['is_restricted'] ? 'table-danger' : ($isLow ? 'table-warning' : '') ?>">
                            <td><code><?= gjc_e($item['sku'] ?: '-') ?></code></td>
                            <td>
                                <strong><?= gjc_e($item['product_name']) ?></strong>
                                <?php if ($item['is_restricted']): ?>
                                    <span class="badge bg-danger ms-1" style="font-size:11px">RESTRICTED</span>
                                <?php endif; ?>
                                <?php if ($item['description']): ?>
                                    <br><small class="text-muted"><?= gjc_e($item['description']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= gjc_e(ucwords($item['category'])) ?></td>
                            <td><?= gjc_e($item['unit']) ?></td>
                            <td data-order="<?= (float) $item['price'] ?>"><?= gjc_gc_price($item['price']) ?></td>
                            <td><?= $qty ?></td>
                            <td data-order="<?= ['low' => 0, 'medium' => 1, 'high' => 2][$tier] ?>">
                                <span class="stock-pill stock-pill--<?= $tier ?>"
                                      title="<?= $isLow ? "At or below the minimum stock alert ({$minAlert})" : ($tier === 'medium' ? "Within 3× of the minimum stock alert ({$minAlert})" : "Comfortably above the minimum stock alert ({$minAlert})") ?>">
                                    <?= ucfirst($tier) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($item['is_available'] && !$item['is_restricted']): ?>
                                    <span class="merchant-type-pill">Available</span>
                                <?php elseif ($item['is_restricted']): ?>
                                    <span style="color:var(--gp-red);font-weight:700;font-size:12px">Blocked</span>
                                <?php else: ?>
                                    <span style="color:var(--gp-muted);font-size:12px">Unavailable</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <div class="d-flex align-items-center gap-1 flex-nowrap">
                                    <!-- Read view: the row is identified by a signed HMAC token,
                                         and view_product.php re-checks it against this stall's
                                         merchant_user_id before rendering. -->
                                    <a class="btn btn-sm btn-outline-secondary rounded-0 text-decoration-none"
                                        href="<?= MERCHANT_URL ?>/view_product.php?token=<?= urlencode(gjc_make_view_token((int) $item['id'], 'merchant_product')) ?>">
                                        <i class="fa-regular fa-eye"></i> View
                                    </a>
                                    <?php if (!$isMerchAdmin): ?>
                                    <!-- Staff have no Edit button, so the quick Stock modal stays their
                                         only way to update quantities. Admins update stock via Edit. -->
                                    <button class="btn btn-sm btn-outline-primary"
                                        onclick="editStock(<?= (int)$item['id'] ?>, <?= (int)$item['stock_qty'] ?>, '<?= gjc_e($item['product_name']) ?>')">
                                        Stock
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-success rounded-0"
                                        <?= $item['sku'] ? '' : 'disabled title="Add a SKU first — the QR encodes the SKU."' ?>
                                        onclick='openItemQr(<?= json_encode([
                                            "sku" => $item["sku"],
                                            "name" => $item["product_name"],
                                            "price" => number_format((float) $item["price"], 2),
                                            "price_raw" => (float) $item["price"],
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <i class="fa-solid fa-qrcode"></i> QR
                                    </button>
                                    <?php if ($isMerchAdmin): ?>
                                    <button class="btn btn-sm btn-outline-primary rounded-0"
                                        onclick="editProduct(<?= htmlspecialchars(json_encode($item), ENT_QUOTES) ?>)">
                                        Edit
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger rounded-0"
                                        onclick='askDeleteProduct(<?= (int) $item['id'] ?>, <?= htmlspecialchars(json_encode($item['product_name']), ENT_QUOTES) ?>)'
                                        title="Delete product">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- Add Product Modal (Merchant Admin only) -->
<?php if ($isMerchAdmin): ?>
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content custom-modal">
            <div class="modal-header"><h5 class="modal-title" id="productModalTitle">Add Product</h5></div>
            <div class="modal-body">
                <form id="productForm">
                    <input type="hidden" name="action" value="add_product" id="productAction">
                    <input type="hidden" name="item_id" id="productId" value="">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">SKU (optional)</label>
                            <input type="text" class="form-control" name="sku" id="pSku" placeholder="e.g. RICE-001">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Product Name *</label>
                            <input type="text" class="form-control" name="product_name" id="pName" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <input type="text" class="form-control" name="description" id="pDesc" placeholder="Short description">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Category *</label>
                            <select class="form-select" name="category" id="pCategory">
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= $c ?>"><?= ucwords($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Unit *</label>
                            <select class="form-select" name="unit" id="pUnit">
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= $u ?>"><?= ucwords($u) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Price (₱) *</label>
                            <input type="number" class="form-control" name="price" id="pPrice" step="0.01" min="0" required>
                            <div class="form-text" id="pPriceGcHint" style="min-height:18px"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Stock Quantity *</label>
                            <input type="number" class="form-control" name="stock_qty" id="pStock" min="0" required value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Min Stock Alert</label>
                            <input type="number" class="form-control" name="min_stock_alert" id="pMinStock" min="0" value="5">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" name="is_available" id="pAvailable" value="1" checked>
                                <label class="form-check-label" for="pAvailable">Available for sale</label>
                            </div>
                        </div>
                    </div>
                    <div id="productMsg" class="mt-3"></div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="login-btn" style="flex:1" id="productSubmitBtn">Save Product</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Stock Modal -->
<div class="modal fade" id="stockModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content custom-modal">
            <div class="modal-header"><h5 class="modal-title">Update Stock Level</h5></div>
            <div class="modal-body">
                <form id="stockForm">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" name="item_id" id="stockItemId">
                    <p>Product: <strong id="stockProductName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Stock Quantity</label>
                        <input type="number" class="form-control form-control-lg" name="stock_qty" id="stockQtyInput" min="0" required>
                    </div>
                    <div id="stockMsg" class="mb-3"></div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="login-btn" style="flex:1">Update Stock</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Item QR Modal -->
<div class="modal fade" id="itemQrModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content custom-modal">
            <div class="modal-header">
                <h5 class="modal-title">Item QR Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p class="text-muted mb-3">Print this and stick it next to the item on your cardboard menu. Students scan it to add this item to their cart.</p>
                <img id="itemQrImage" src="" alt="Item QR" style="width:240px;height:240px;border-radius:12px;background:#fff;padding:8px;box-shadow:0 2px 8px rgba(0,0,0,.08)">
                <h5 class="mt-3 mb-1" id="itemQrName">--</h5>
                <p class="text-muted mb-0" id="itemQrPrice">--</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="itemQrPrintBtn"><i class="fa-solid fa-print"></i> Print</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Product Confirm Modal (Merchant Admin only) -->
<?php if ($isMerchAdmin): ?>
<div class="modal fade" id="deleteProductModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content custom-modal">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="fa-solid fa-trash-can me-2"></i>Delete Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">Permanently remove this product from your catalog?</p>
                <p class="fw-bold fs-5 mb-3" id="deleteProductName">--</p>
                <p class="text-muted small mb-3"><i class="fa-solid fa-circle-info me-1"></i>Past sales records are not affected. Students will no longer be able to scan or buy this item.</p>
                <div id="deleteProductMsg" class="mb-3"></div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-danger" style="flex:1" id="confirmDeleteBtn"><i class="fa-solid fa-trash-can me-1"></i>Delete</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Restricted Product Blocked Modal (strikes below the risk threshold) -->
<div class="modal fade" id="restrictedWarnModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content custom-modal" style="border-top:4px solid var(--gp-warning)">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--gp-warning)"><i class="fa-solid fa-triangle-exclamation me-2"></i>Restricted Product Blocked</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">This item cannot be added to your catalog:</p>
                <p class="fw-bold fs-5 mb-2" id="rvProductName">--</p>
                <div class="alert alert-warning" style="border-radius:10px;font-size:13px">
                    <i class="fa-solid fa-ban me-1"></i><span id="rvReasonText">--</span>
                </div>
                <div class="mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="small fw-semibold">Blocked attempts on this account</span>
                        <span class="small fw-bold" style="color:var(--gp-danger)" id="rvStrikeLabel">--</span>
                    </div>
                    <div id="rvStrikePips" class="d-flex gap-1"></div>
                </div>
                <div class="alert alert-danger d-none" style="border-radius:10px;font-size:13px" id="rvEscalation"></div>
                <p class="text-muted small mb-3"><i class="fa-solid fa-circle-info me-1"></i>Every blocked attempt is logged and visible to the GenPay finance team.</p>
                <button type="button" class="login-btn w-100" data-bs-dismiss="modal">I Understand</button>
            </div>
        </div>
    </div>
</div>

<!-- Account Suspended Modal (fires when the strike limit is hit) -->
<div class="modal fade" id="accountRiskModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content custom-modal" style="border-top:4px solid var(--gp-danger)">
            <div class="modal-body text-center pt-4">
                <div style="width:64px;height:64px;border-radius:50%;background:var(--gp-danger-bg);display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
                    <i class="fa-solid fa-user-lock" style="font-size:26px;color:var(--gp-danger)"></i>
                </div>
                <h4 class="fw-bold mb-2" style="color:var(--gp-danger)">Account Temporarily Suspended</h4>
                <p class="mb-2">"<span class="fw-semibold" id="arProductName">--</span>" is a restricted product and was not saved.</p>
                <p class="mb-3">This account reached <strong id="arStrikeCount">--</strong> blocked attempts to list restricted products. <strong>Your merchant account — including staff logins and stall sales — is now suspended for <span id="arSuspendDays">3</span> days</strong>, until <strong id="arSuspendUntil">--</strong>.</p>
                <div class="alert alert-danger text-start" style="border-radius:10px;font-size:13px">
                    <i class="fa-solid fa-ban me-1"></i><span id="arReasonText">--</span>
                </div>
                <p class="text-muted small mb-3">The GenPay finance team has been notified and may lift the suspension early. You will be logged out now and can log back in once the suspension ends.</p>
                <button type="button" class="btn btn-danger w-100" id="arLogoutBtn"><i class="fa-solid fa-right-from-bracket me-1"></i>Log Out</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
<script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>
<script>
const INV_API = '<?= MERCHANT_URL ?>/api/inventory.php';

// GenCoin display conversion (₱10 = 1 GC). Prices are stored and saved in ₱.
const PESOS_PER_GC = <?= GJC_PESOS_PER_GC ?>;
function gcAmount(pesos) {
    return (pesos / PESOS_PER_GC).toLocaleString('en-PH', {maximumFractionDigits: 2});
}

function editStock(id, qty, name) {
    document.getElementById('stockItemId').value = id;
    document.getElementById('stockQtyInput').value = qty;
    document.getElementById('stockProductName').textContent = name;
    document.getElementById('stockMsg').innerHTML = '';
    new bootstrap.Modal(document.getElementById('stockModal')).show();
}

let currentItemQrSku = '';
let currentItemQrName = '';
let currentItemQrPrice = '';
let currentItemQrGc = '';

function openItemQr(item) {
    if (!item || !item.sku) return;
    currentItemQrSku = item.sku;
    currentItemQrName = item.name;
    currentItemQrPrice = item.price;
    currentItemQrGc = gcAmount(item.price_raw);

    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&ecc=H&margin=10&data=' + encodeURIComponent(item.sku);
    document.getElementById('itemQrImage').src = qrUrl;
    document.getElementById('itemQrName').textContent = item.name;
    document.getElementById('itemQrPrice').innerHTML =
        `<strong style="color:var(--gp-green-850)">${currentItemQrGc} GC</strong><br><small>≈ ₱${item.price}</small>`;
    new bootstrap.Modal(document.getElementById('itemQrModal')).show();
}

document.getElementById('itemQrPrintBtn').addEventListener('click', function () {
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&ecc=H&margin=14&data=' + encodeURIComponent(currentItemQrSku);
    const win = window.open('', '_blank', 'width=420,height=560');
    win.document.write(`
        <html>
        <head>
            <title>${currentItemQrName} QR</title>
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
            <style>
                body { font-family: 'Plus Jakarta Sans', sans-serif; text-align: center; padding: 24px; }
                img { width: 280px; height: 280px; }
                h2 { margin: 16px 0 4px; }
                p { color: #555; }
            </style>
        </head>
        <body>
            <img src="${qrUrl}" alt="Item QR">
            <h2>${currentItemQrName}</h2>
            <p><strong>${currentItemQrGc} GC</strong><br><span style="font-size:13px">≈ ₱${currentItemQrPrice}</span></p>
            <script>window.onload = () => window.print();<\/script>
        </body>
        </html>
    `);
    win.document.close();
});

<?php if ($isMerchAdmin): ?>
function updatePriceGcHint() {
    const pesos = parseFloat(document.getElementById('pPrice').value);
    document.getElementById('pPriceGcHint').textContent =
        pesos > 0 ? `≈ ${gcAmount(pesos)} GC (₱${PESOS_PER_GC} = 1 GC)` : '';
}
document.getElementById('pPrice').addEventListener('input', updatePriceGcHint);

function editProduct(item) {
    document.getElementById('productModalTitle').textContent = 'Edit Product';
    document.getElementById('productAction').value = 'edit_product';
    document.getElementById('productId').value = item.id;
    document.getElementById('pSku').value = item.sku || '';
    document.getElementById('pName').value = item.product_name;
    document.getElementById('pDesc').value = item.description || '';
    document.getElementById('pCategory').value = item.category;
    document.getElementById('pUnit').value = item.unit;
    document.getElementById('pPrice').value = item.price;
    document.getElementById('pStock').value = item.stock_qty;
    document.getElementById('pMinStock').value = item.min_stock_alert;
    document.getElementById('pAvailable').checked = !!parseInt(item.is_available);
    updatePriceGcHint();
    new bootstrap.Modal(document.getElementById('addProductModal')).show();
}

document.getElementById('productForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('productSubmitBtn');
    btn.disabled = true; btn.textContent = 'Saving...';
    const r = await fetch(INV_API, { method:'POST', body: new FormData(this) });
    const d = await r.json();
    const msg = document.getElementById('productMsg');
    if (d.success) {
        msg.innerHTML = `<div class="alert alert-success">${d.message} Reloading...</div>`;
        setTimeout(() => location.reload(), 1300);
    } else if (d.blocked) {
        msg.innerHTML = '';
        btn.disabled = false; btn.textContent = 'Save Product';
        showViolationModal(d);
    } else {
        msg.innerHTML = `<div class="alert alert-danger">${d.message}</div>`;
        btn.disabled = false; btn.textContent = 'Save Product';
    }
});

// ── Restricted-product strike modals ─────────────────────────────────────────
// Below the risk threshold: warning modal with the strike meter (escalation
// note from warn_at up). Hitting risk_at: the account-suspended modal — the
// server has already frozen the stall for suspend_days, so the only exit is
// Log Out. Thresholds come from the API so policy lives server-side.
function showViolationModal(d) {
    const count  = parseInt(d.violation_count) || 0;
    const warnAt = parseInt(d.warn_at) || 3;
    const riskAt = parseInt(d.risk_at) || 5;

    if (count >= riskAt) {
        document.getElementById('arProductName').textContent = d.attempted_name || '';
        document.getElementById('arStrikeCount').textContent = count;
        document.getElementById('arReasonText').textContent = d.reason || d.message;
        document.getElementById('arSuspendDays').textContent = d.suspend_days || 3;
        const until = d.suspended_until ? new Date(d.suspended_until.replace(' ', 'T')) : null;
        document.getElementById('arSuspendUntil').textContent = (until && !isNaN(until))
            ? until.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
            : `${d.suspend_days || 3} days from now`;
    } else {
        document.getElementById('rvProductName').textContent = d.attempted_name || '';
        document.getElementById('rvReasonText').textContent = d.reason || d.message;
        document.getElementById('rvStrikeLabel').textContent = `${count} of ${riskAt}`;
        document.getElementById('rvStrikePips').innerHTML = Array.from({length: riskAt}, (_, i) =>
            `<span style="flex:1;height:6px;border-radius:3px;background:${i < count ? 'var(--gp-danger)' : 'var(--gp-line)'}"></span>`
        ).join('');
        const esc = document.getElementById('rvEscalation');
        if (count >= warnAt) {
            const left = riskAt - count;
            esc.innerHTML = `<i class="fa-solid fa-triangle-exclamation me-1"></i><strong>${left} more blocked attempt${left === 1 ? '' : 's'}</strong> and this account will be suspended for ${d.suspend_days || 3} days.`;
            esc.classList.remove('d-none');
        } else {
            esc.classList.add('d-none');
        }
    }

    const nextId = count >= riskAt ? 'accountRiskModal' : 'restrictedWarnModal';
    const productModalEl = document.getElementById('addProductModal');
    productModalEl.addEventListener('hidden.bs.modal', () => {
        new bootstrap.Modal(document.getElementById(nextId)).show();
    }, { once: true });
    bootstrap.Modal.getOrCreateInstance(productModalEl).hide();
}

document.getElementById('arLogoutBtn').addEventListener('click', () => {
    window.location.href = '<?= BASE_URL ?>/logout.php';
});

// ── Delete product ────────────────────────────────────────────────────────────
let deleteItemId = null;

function askDeleteProduct(id, name) {
    deleteItemId = id;
    document.getElementById('deleteProductName').textContent = name;
    document.getElementById('deleteProductMsg').innerHTML = '';
    new bootstrap.Modal(document.getElementById('deleteProductModal')).show();
}

document.getElementById('confirmDeleteBtn').addEventListener('click', async function () {
    if (!deleteItemId) return;
    const btn = this;
    btn.disabled = true; btn.textContent = 'Deleting...';
    const f = new FormData();
    f.append('action', 'delete_product');
    f.append('item_id', deleteItemId);
    try {
        const r = await fetch(INV_API, { method: 'POST', body: f });
        const d = await r.json();
        const msg = document.getElementById('deleteProductMsg');
        if (d.success) {
            msg.innerHTML = '<div class="alert alert-success mb-0">Product deleted. Reloading...</div>';
            setTimeout(() => location.reload(), 1000);
        } else {
            msg.innerHTML = `<div class="alert alert-danger mb-0">${d.message}</div>`;
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-trash-can me-1"></i>Delete';
        }
    } catch {
        document.getElementById('deleteProductMsg').innerHTML = '<div class="alert alert-danger mb-0">Network error. Please try again.</div>';
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-trash-can me-1"></i>Delete';
    }
});
<?php endif; ?>

document.getElementById('stockForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const r = await fetch(INV_API, { method:'POST', body: new FormData(this) });
    const d = await r.json();
    const msg = document.getElementById('stockMsg');
    if (d.success) {
        msg.innerHTML = '<div class="alert alert-success">Stock updated! Reloading...</div>';
        setTimeout(() => location.reload(), 1200);
    } else {
        msg.innerHTML = `<div class="alert alert-danger">${d.message}</div>`;
    }
});
</script>
<?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>
</html>
