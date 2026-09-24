<?php
session_start();
require_once __DIR__ . "/../connection/config.php";
require_once __DIR__ . "/../connection/pdo.php";
require_once __DIR__ . "/../connection/app.php";
require_once __DIR__ . "/../connection/CirculationEngine.php";

gjc_require_role(["merchant"]);
$currentUser = gjc_current_user($db);
$merchantUserId = $currentUser["id"];
$isStaff = gjc_is_merchant_staff();
$ownerMerchId = gjc_merchant_owner_id($db, $merchantUserId);
$currentPage = "pos";

// Fetch inventory for POS (only available, non-restricted items)
$products = [];
if (gjc_table_exists($db, "merchant_inventory")) {
    $stmt = $db->prepare(
        "SELECT id, sku, product_name, category, unit, price, stock_qty
           FROM merchant_inventory
          WHERE merchant_user_id = ? AND is_available = 1 AND is_restricted = 0 AND stock_qty > 0
          ORDER BY category ASC, product_name ASC",
    );
    $stmt->execute([$ownerMerchId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get merchant wallet for this POS context
$wallet = gjc_merchant_wallet($db, $ownerMerchId);

// Recent transactions for the in-POS Transactions tab, so the cashier can
// look up a sale without leaving the terminal. Full history lives on its
// own page; this is just the latest slice.
$recentTransactions = [];
if ($wallet["id"] > 0 && gjc_table_exists($db, "transactions")) {
    $txStmt = $db->prepare(
        "SELECT id, reference_no, transaction_type, amount, status, notes, created_at
           FROM transactions
          WHERE merchant_wallet_id = ?
          ORDER BY created_at DESC
          LIMIT 50",
    );
    $txStmt->execute([$wallet["id"]]);
    $recentTransactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title>POS Terminal | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=62">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/pos.css?v=20">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        .lw-parent-choice--active { border-color: var(--gp-success) !important; background: var(--gp-success-bg); }
    </style>
</head>
<body class="gp-theme">
<div class="merchant-layout">
    <?php require __DIR__ .
        "/../includes/partials/" .
        (gjc_is_merchant_staff()
            ? "sidebar_merchant_staff.php"
            : "sidebar_merchant_admin.php"); ?>

    <main class="merchant-main">
        <?php
        $topbarTitle = 'POS Terminal';
        $topbarSubtitle = 'Select items and process student wallet payments.';
        require __DIR__ . '/../includes/partials/topbar_merchant.php';
        ?>

        <div class="pos-layout">
            <!-- Live Order / Products: tabbed, so the cashier can watch the
                 queue by default and switch to punch a walk-in order. -->
            <div>
                <div class="pos-tabs" role="tablist">
                    <button type="button" class="pos-tab is-active" id="posTabQueue" role="tab" onclick="setPosTab('queue')">
                        <i class="fa-solid fa-list-ol"></i> Live Order
                        <span class="pos-tab-count" id="posQueueCount" hidden>0</span>
                    </button>
                    <button type="button" class="pos-tab" id="posTabProducts" role="tab" onclick="setPosTab('products')">
                        <i class="fa-solid fa-boxes-stacked"></i> Products
                    </button>
                    <button type="button" class="pos-tab" id="posTabTransactions" role="tab" onclick="setPosTab('transactions')">
                        <i class="fa-solid fa-receipt"></i> Transactions
                    </button>
                </div>

                <!-- Queue: today's orders in arrival order (#1, #2, #3...).
                     Tapping one shows its detail on the right, in place of
                     the Register panel. -->
                <div class="pos-pane" id="posPaneQueue">
                    <h4><span class="me-dot me-dot-green me-pulse" style="margin-right:6px"></span>Order Queue</h4>
                    <div id="queueList" class="pq-list">
                        <div class="pq-empty">Loading orders&hellip;</div>
                    </div>
                </div>

                <!-- Products -->
                <div class="pos-pane" id="posPaneProducts" hidden>
                <div class="pos-product-toolbar">
                    <input type="search" class="pos-search-input" id="posProductSearch"
                        placeholder="Search products by name, SKU, or category" autocomplete="off">
                    <button type="button" class="merchant-loadwallet-btn" style="flex-shrink:0" onclick="lwOpen()">
                        <i class="fa-solid fa-coins"></i> <span class="merchant-loadwallet-label">Send GenCoin</span>
                    </button>
                </div>

                <!-- Category filter -->
                <div class="cat-filter" id="catFilter">
                    <button class="cat-btn active" onclick="filterCat('all', this)">All</button>
                    <?php
                    $cats = array_unique(array_column($products, "category"));
                    foreach ($cats as $cat): ?>
                    <button class="cat-btn" onclick="filterCat('<?= gjc_e(
                        $cat,
                    ) ?>', this)"><?= gjc_e(ucwords($cat)) ?></button>
                    <?php endforeach;
                    ?>
                </div>

                <?php if (empty($products)): ?>
                <div style="text-align:center;padding:60px 20px;color:var(--gp-muted);">
                    <p style="font-size:15px">No products available in inventory.</p>
                    <p style="font-size:13px">Ask your Merchant Admin to add items first.</p>
                </div>
                <?php else: ?>
                <div class="pos-products-grid" id="productsGrid">
                    <?php foreach ($products as $p): ?>
                    <div class="pos-product-card"
                        data-id="<?= (int) $p["id"] ?>"
                        data-name="<?= gjc_e($p["product_name"]) ?>"
                        data-price="<?= (float) $p["price"] ?>"
                        data-stock="<?= (int) $p["stock_qty"] ?>"
                        data-cat="<?= gjc_e($p["category"]) ?>"
                        data-sku="<?= gjc_e($p["sku"] ?? "") ?>"
                        onclick="addToCart(this)">
                        <span class="product-cat"><?= gjc_e(
                            $p["category"],
                        ) ?></span>
                        <div class="product-name"><?= gjc_e(
                            $p["product_name"],
                        ) ?></div>
                        <div class="product-price gc-price">
                            <span class="gc-price-main"><?= gjc_gc_amount(
                                $p["price"],
                            ) ?> GC</span>
                            <span class="gc-price-sub">&asymp; <?= gjc_money(
                                $p["price"],
                            ) ?></span>
                        </div>
                        <div class="product-stock">Stock: <?= (int) $p[
                            "stock_qty"
                        ] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="pos-empty-filter" id="posEmptyFilter">No products match your search.</div>
                <?php endif; ?>
                </div>

                <!-- Transactions: latest sales for this stall, so the cashier
                     can look one up without leaving the terminal. -->
                <div class="pos-pane" id="posPaneTransactions" hidden>
                <div class="pos-product-toolbar">
                    <input type="search" class="pos-search-input" id="posTxnSearch"
                        placeholder="Search by reference or description" autocomplete="off">
                    <a class="merchant-loadwallet-btn" style="flex-shrink:0;text-decoration:none" href="<?= MERCHANT_URL ?>/history.php">
                        <i class="fa-solid fa-clock-rotate-left"></i> <span class="merchant-loadwallet-label">Full History</span>
                    </a>
                </div>
                <div id="posTxnList" class="pq-list">
                    <div class="pq-head">Recent transactions <span><?= count($recentTransactions) ?></span></div>
                    <?php if (empty($recentTransactions)): ?>
                    <div class="pq-empty">No transactions yet.</div>
                    <?php else: ?>
                    <?php foreach ($recentTransactions as $t):
                        $statusBadge = match ($t["status"]) {
                            "reversed" => '<span class="badge bg-secondary">Refunded</span>',
                            "completed" => '<span class="badge bg-success">Completed</span>',
                            default => '<span class="badge bg-warning text-dark">' . gjc_e(ucfirst($t["status"])) . '</span>',
                        };
                        $txnLabel = $t["notes"] ?: ucwords(str_replace("_", " ", $t["transaction_type"]));
                        $searchBlob = strtolower($t["reference_no"] . " " . $txnLabel . " " . $t["transaction_type"]);
                    ?>
                    <a class="pq-row" data-search="<?= gjc_e($searchBlob) ?>"
                        href="<?= MERCHANT_URL ?>/view_transaction.php?token=<?= urlencode(gjc_make_view_token((int) $t["id"], "merchant_txn")) ?>">
                        <span class="pq-main">
                            <span class="pq-src"><?= gjc_e($t["reference_no"]) ?></span>
                            <span class="pq-desc"><?= gjc_e($txnLabel) ?></span>
                        </span>
                        <span class="pq-side">
                            <span class="gc-price gc-price--end">
                                <span class="gc-price-main"><?= $t["transaction_type"] === "refund" ? "-" : "+" ?><?= gjc_gc_amount($t["amount"]) ?> GC</span>
                                <span class="gc-price-sub">&asymp; <?= gjc_money($t["amount"]) ?></span>
                            </span>
                            <span class="pq-time"><?= $statusBadge ?> <?= gjc_e(date("M d, h:i A", strtotime($t["created_at"]))) ?></span>
                        </span>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="pos-empty-filter" id="posTxnEmptyFilter">No transactions match your search.</div>
                </div>
            </div>

            <!-- Register: walk-in cart + checkout, beside the product/queue
                 tabs so the cashier never leaves this screen. Swaps out for
                 the order detail view when a queue order is opened. -->
            <div class="pos-cart-panel">
                <div class="pos-pane" id="posRegisterView">
                    <h4>Walk-in Order</h4>
                    <div class="cart-items-list" id="cartList">
                        <div id="cartEmpty" style="text-align:center;color:var(--gp-muted);padding:30px 0;font-size:13px">
                            Tap products to add them here
                        </div>
                    </div>

                    <div class="cart-total-row">
                        <span>Total</span>
                        <span id="cartTotal" class="gc-price gc-price--end">
                            <span class="gc-price-main">0 GC</span>
                            <span class="gc-price-sub">&asymp; &#8369;0.00</span>
                        </span>
                    </div>

                    <button class="pos-charge-btn" id="chargeBtn" onclick="generatePaymentQr()" disabled>
                        Generate Payment QR
                    </button>

                    <div class="pos-qr-box" id="posQrBox">
                        <div class="pos-qr-status is-pending" id="posQrStatus">Waiting for payment&hellip;</div>
                        <strong id="posQrTitle">Ready for student scan</strong>
                        <span id="posQrHint">Ask the student to open Shop Cart, tap &ldquo;Pay Now&rdquo;, and scan this QR.</span>
                        <div class="pos-qr-frame">
                            <div class="pos-qr-canvas" id="posQrCanvas"></div>
                            <span class="pos-qr-corner tl"></span>
                            <span class="pos-qr-corner tr"></span>
                            <span class="pos-qr-corner bl"></span>
                            <span class="pos-qr-corner br"></span>
                        </div>
                        <div class="pos-qr-manual">
                            <span>Manual entry code</span>
                            <strong id="posQrShortCode">----</strong>
                        </div>
                        <span id="posQrSummary"></span>
                        <div class="pos-qr-guide">
                            <div class="pos-qr-guide-step"><strong>1</strong><span>Show this QR to the student.</span></div>
                            <div class="pos-qr-guide-step"><strong>2</strong><span>They scan it from the Shop Cart&rsquo;s &ldquo;Pay Now&rdquo; mode.</span></div>
                            <div class="pos-qr-guide-step"><strong>3</strong><span>Wait for the status to flip to Paid before handing over the order.</span></div>
                        </div>
                    </div>

                    <button class="btn btn-outline-secondary btn-sm mt-3 w-100" onclick="clearCart()">
                        &#10005; Clear Order
                    </button>
                </div>

                <div class="pos-pane" id="queueDetailView" hidden></div>
            </div>
        </div>
    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script>
const cart    = {};
const merchantName = <?= json_encode(
    $currentUser["name"],
    JSON_UNESCAPED_SLASHES,
) ?>;
const merchantWalletId = <?= (int) $wallet["id"] ?>;
const merchantUserId = <?= (int) $ownerMerchId ?>;
let activeCategory = 'all';

// GenCoin display conversion (₱10 = 1 GC). Amounts sent to the API stay in ₱.
const PESOS_PER_GC = <?= GJC_PESOS_PER_GC ?>;
function gcAmount(pesos) {
    return (pesos / PESOS_PER_GC).toLocaleString('en-PH', {maximumFractionDigits: 2});
}
function gcPriceHtml(pesos, endAlign = false) {
    return `<span class="gc-price${endAlign ? ' gc-price--end' : ''}">
        <span class="gc-price-main">${gcAmount(pesos)} GC</span>
        <span class="gc-price-sub">≈ ₱${(+pesos).toFixed(2)}</span>
    </span>`;
}

// \u2500\u2500 Category filter \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
function filterCat(cat, btn) {
    activeCategory = cat;
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyProductFilters();
}

function applyProductFilters() {
    const query = (document.getElementById('posProductSearch')?.value || '').trim().toLowerCase();
    let visibleCount = 0;

    document.querySelectorAll('.pos-product-card').forEach(card => {
        const matchesCategory = activeCategory === 'all' || card.dataset.cat === activeCategory;
        const haystack = [
            card.dataset.name || '',
            card.dataset.sku || '',
            card.dataset.cat || ''
        ].join(' ').toLowerCase();
        const matchesSearch = query === '' || haystack.includes(query);
        const shouldShow = matchesCategory && matchesSearch;

        card.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleCount++;
    });

    const emptyFilter = document.getElementById('posEmptyFilter');
    if (emptyFilter) {
        emptyFilter.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

document.getElementById('posProductSearch')?.addEventListener('input', applyProductFilters);

// ── Transactions tab search ─────────────────────────────────────────────
function applyTxnFilters() {
    const query = (document.getElementById('posTxnSearch')?.value || '').trim().toLowerCase();
    let visibleCount = 0;

    document.querySelectorAll('#posTxnList .pq-row').forEach(row => {
        const shouldShow = query === '' || (row.dataset.search || '').includes(query);
        row.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleCount++;
    });

    const emptyFilter = document.getElementById('posTxnEmptyFilter');
    if (emptyFilter) {
        emptyFilter.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

document.getElementById('posTxnSearch')?.addEventListener('input', applyTxnFilters);

// \u2500\u2500 Cart management \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
function addToCart(card) {
    const id    = card.dataset.id;
    const name  = card.dataset.name;
    const price = parseFloat(card.dataset.price);
    const stock = parseInt(card.dataset.stock);

    if (!cart[id]) {
        cart[id] = { id, name, price, qty: 0, maxStock: stock };
    }
    if (cart[id].qty >= stock) {
        alert('Maximum stock reached for this item.');
        return;
    }
    cart[id].qty++;
    card.classList.add('in-cart');
    renderCart();
}

function changeQty(id, delta) {
    if (!cart[id]) return;
    cart[id].qty += delta;
    if (cart[id].qty <= 0) {
        delete cart[id];
        const card = document.querySelector(`.pos-product-card[data-id="${id}"]`);
        if (card) card.classList.remove('in-cart');
    }
    renderCart();
}

function renderCart() {
    const list  = document.getElementById('cartList');
    const empty = document.getElementById('cartEmpty');
    const keys  = Object.keys(cart);

    if (keys.length === 0) {
        list.innerHTML = '<div id="cartEmpty" style="text-align:center;color:var(--gp-muted);padding:30px 0;font-size:13px">Tap products to add them here</div>';
        document.getElementById('cartTotal').innerHTML = gcPriceHtml(0, true);
        document.getElementById('chargeBtn').disabled = true;
        stopStatusPolling();
        document.getElementById('posQrBox').style.display = 'none';
        return;
    }

    let html = '';
    let total = 0;
    keys.forEach(id => {
        const item = cart[id];
        const subtotal = item.price * item.qty;
        total += subtotal;
        html += `
        <div class="cart-item">
            <div class="cart-item-name">${item.name}</div>
            <div class="cart-item-qty">
                <button onclick="changeQty('${id}', -1)">-</button>
                <span>${item.qty}</span>
                <button onclick="changeQty('${id}', 1)">+</button>
            </div>
            <div class="cart-item-price">${gcPriceHtml(subtotal, true)}</div>
        </div>`;
    });
    list.innerHTML = html;
    document.getElementById('cartTotal').innerHTML = gcPriceHtml(total, true);
    document.getElementById('chargeBtn').disabled = !(merchantWalletId && total > 0);
    stopStatusPolling();
    document.getElementById('posQrBox').style.display = 'none';
}

function clearCart() {
    Object.keys(cart).forEach(k => delete cart[k]);
    document.querySelectorAll('.pos-product-card').forEach(c => c.classList.remove('in-cart'));
    stopStatusPolling();
    document.getElementById('posQrBox').style.display = 'none';
    document.getElementById('posQrCanvas').innerHTML = '';
    document.getElementById('posQrShortCode').textContent = '----';
    document.getElementById('posQrSummary').textContent = '';
    renderCart();
}

function orderTotals() {
    const items = Object.values(cart).map(i => ({ id: i.id, qty: i.qty, price: i.price }));
    const total = items.reduce((s, i) => s + i.price * i.qty, 0);
    return { items, total };
}

const POS_CSRF = <?= json_encode(gjc_csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
let posStatusTimer = null;

function setQrStatus(state, text) {
    const pill = document.getElementById('posQrStatus');
    pill.className = 'pos-qr-status is-' + state;
    pill.textContent = text;
}

function stopStatusPolling() {
    if (posStatusTimer) {
        clearInterval(posStatusTimer);
        posStatusTimer = null;
    }
}

// Rendered locally (qrcodejs) so the single-use token never leaves the
// terminal \u2014 the old external QR image API received it as a URL param.
function renderPosQr(payload) {
    const box = document.getElementById('posQrCanvas');
    box.innerHTML = '';
    new QRCode(box, {
        text: payload,
        width: 220,
        height: 220,
        correctLevel: QRCode.CorrectLevel.H
    });
}

function startStatusPolling(orderId) {
    stopStatusPolling();
    posStatusTimer = setInterval(async () => {
        try {
            const form = new FormData();
            form.append('action', 'check_order_status');
            form.append('order_id', orderId);

            const response = await fetch('api/pos.php', { method: 'POST', body: form });
            const data = await response.json();
            if (!data.success) {
                return;
            }

            if (data.status === 'paid') {
                stopStatusPolling();
                refreshLiveQueue();
                // Order done: reset the cart but keep the box visible in its
                // paid state (renderCart hides it) so the cashier sees the ref.
                Object.keys(cart).forEach(k => delete cart[k]);
                document.querySelectorAll('.pos-product-card').forEach(c => c.classList.remove('in-cart'));
                renderCart();
                setQrStatus('paid', 'Paid \u2713 ' + (data.paid_ref || ''));
                document.getElementById('posQrTitle').textContent = 'Payment received';
                document.getElementById('posQrHint').textContent = 'The student\u2019s wallet has been charged. You can start the next order.';
                document.getElementById('posQrCanvas').innerHTML = '';
                document.getElementById('posQrShortCode').textContent = '----';
                document.getElementById('posQrBox').style.display = 'block';
            } else if (data.status === 'expired' || data.status === 'voided') {
                stopStatusPolling();
                setQrStatus('expired', 'Expired \u2014 generate a new QR');
                document.getElementById('posQrTitle').textContent = 'This QR is no longer valid';
                document.getElementById('posQrHint').textContent = 'Tap Generate Payment QR again for a fresh code.';
                document.getElementById('posQrCanvas').innerHTML = '';
                document.getElementById('posQrShortCode').textContent = '----';
            }
        } catch (error) {
            // Transient network error - keep polling.
        }
    }, 3000);
}

async function generatePaymentQr() {
    const { items, total } = orderTotals();
    if (!items.length || total <= 0) {
        return;
    }

    const chargeBtn = document.getElementById('chargeBtn');
    chargeBtn.disabled = true;
    chargeBtn.textContent = 'Preparing QR...';

    try {
        const form = new FormData();
        form.append('action', 'create_qr_order');
        form.append('csrf_token', POS_CSRF);
        form.append('total', total.toFixed(2));
        form.append('items', JSON.stringify(items));

        const response = await fetch('api/pos.php', {
            method: 'POST',
            body: form
        });
        const result = await response.json();

        if (!result.success) {
            alert(result.message || 'Unable to create payment QR.');
            return;
        }

        renderPosQr(result.qr_payload);
        document.getElementById('posQrTitle').textContent = 'Ready for student scan';
        document.getElementById('posQrHint').textContent = 'Ask the student to open Shop Cart, tap "Pay Now", and scan this QR.';
        document.getElementById('posQrShortCode').textContent = result.short_code || '----';
        document.getElementById('posQrSummary').textContent = result.summary || `Total: ${gcAmount(total)} GC (\u2248 \u20b1${total.toFixed(2)})`;
        setQrStatus('pending', 'Waiting for payment\u2026');
        document.getElementById('posQrBox').style.display = 'block';
        startStatusPolling(result.order_id);
        refreshLiveQueue();
    } catch (error) {
        alert('Unable to create payment QR. Please try again.');
    } finally {
        chargeBtn.disabled = false;
        chargeBtn.textContent = 'Generate Payment QR';
    }
}
</script>

<script>
// ── Order queue (beside the register) ───────────────────────────────────────
const LIVE_QUEUE_API = '<?= MERCHANT_URL ?>/api/pos.php';
const queueListEl     = document.getElementById('queueList');
const posRegisterView = document.getElementById('posRegisterView');
const queueDetailEl   = document.getElementById('queueDetailView');
let queueOrders = [];
let lastQueueSignature = '';
let openQueueUid = null;

// A ticket stays in the live queue until it is paid (or voided/expired).
const QUEUE_BADGES = {
    awaiting_payment: '<span class="badge bg-warning text-dark">Awaiting payment</span>',
    served:           '<span class="badge bg-success">Paid</span>',
    voided:           '<span class="badge bg-secondary">Voided</span>',
    expired:          '<span class="badge bg-secondary">Expired</span>',
};
const LIVE_STAGES = ['awaiting_payment'];
const isLive = o => LIVE_STAGES.includes(o.stage);
let knownQueueUids = null;
// Pre-orders come from the student/parent Shop Cart; walk-ins are punched here.
const QUEUE_SOURCES = {
    cart_pending: { label: 'App pre-order', icon: 'fa-mobile-screen' },
    pos:          { label: 'Walk-in',       icon: 'fa-cash-register' },
};

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}

function queueTime(ts) {
    return ts ? new Date(ts.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '--';
}

const POS_TABS = {
    queue:        { pane: 'posPaneQueue',        tab: 'posTabQueue' },
    products:     { pane: 'posPaneProducts',      tab: 'posTabProducts' },
    transactions: { pane: 'posPaneTransactions',  tab: 'posTabTransactions' },
};

function setPosTab(name) {
    Object.entries(POS_TABS).forEach(([key, ids]) => {
        document.getElementById(ids.pane).hidden = key !== name;
        document.getElementById(ids.tab).classList.toggle('is-active', key === name);
    });
    if (name === 'queue') document.getElementById('posTabQueue').classList.remove('is-alert');
}

function queueRowHtml(order, isNext) {
    const src = QUEUE_SOURCES[order.source] || QUEUE_SOURCES.pos;
    const waiting = isLive(order);
    const isSelected = order.uid === openQueueUid;
    return `
        <button type="button" class="pq-row${waiting ? '' : ' is-done'}${isNext ? ' is-next' : ''}${isSelected ? ' is-selected' : ''}" onclick="openQueueOrder('${order.uid}')">
            <span class="pq-no">#${order.queue_no}</span>
            <span class="pq-main">
                <span class="pq-src"><i class="fa-solid ${src.icon}"></i> ${src.label}${isNext ? '<b class="pq-next">Next</b>' : ''}</span>
                <span class="pq-desc">${escapeHtml(order.description || '')}</span>
            </span>
            <span class="pq-side">
                ${gcPriceHtml(Number(order.amount), true)}
                <span class="pq-time">${QUEUE_BADGES[order.stage] || ''} ${queueTime(order.created_at)}</span>
            </span>
        </button>`;
}

function renderQueue(orders) {
    queueOrders = orders;
    const waiting = orders.filter(isLive);   // already oldest-first
    const done = orders.filter(o => !isLive(o)).reverse().slice(0, 8);

    const countEl = document.getElementById('posQueueCount');
    countEl.textContent = waiting.length;
    countEl.hidden = waiting.length === 0;

    let html = `<div class="pq-head">Live queue <span>${waiting.length}</span></div>`;
    html += waiting.length
        ? waiting.map((o, i) => queueRowHtml(o, i === 0)).join('')
        : '<div class="pq-empty">The queue is clear. Pre-orders appear here as students place them; walk-ins go through the Register tab.</div>';
    if (done.length) {
        html += `<div class="pq-head">Done today</div>` + done.map(o => queueRowHtml(o, false)).join('');
    }
    queueListEl.innerHTML = html;
}

function flashQueue() {
    queueListEl.classList.remove('queue-flash');
    void queueListEl.offsetWidth;   // restart the animation on a rapid update
    queueListEl.classList.add('queue-flash');
}

async function refreshLiveQueue() {
    try {
        const fd = new FormData();
        fd.append('action', 'list_queue');
        const res = await fetch(LIVE_QUEUE_API, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;

        const signature = JSON.stringify(data.orders.map(o => [o.uid, o.stage, o.amount]));
        if (signature === lastQueueSignature) return;

        const isFirstLoad = lastQueueSignature === '';
        lastQueueSignature = signature;
        const before = queueOrders.find(o => o.uid === openQueueUid);
        renderQueue(data.orders);
        if (!isFirstLoad) flashQueue();

        // A pre-order that just arrived while the cashier is on the Register tab.
        const uids = new Set(data.orders.map(o => o.uid));
        if (knownQueueUids && data.orders.some(o => o.stage === 'awaiting_payment' && !knownQueueUids.has(o.uid))
                && document.getElementById('posPaneQueue').hidden) {
            document.getElementById('posTabQueue').classList.add('is-alert');
        }
        knownQueueUids = uids;

        // The order being inspected changed (e.g. the student just paid): refresh it.
        const after = queueOrders.find(o => o.uid === openQueueUid);
        if (openQueueUid && before && after && before.stage !== after.stage) {
            openQueueOrder(openQueueUid);
        }
    } catch (error) {
        // Keep showing the last known queue on a transient network error.
    }
}

function closeQueueOrder() {
    openQueueUid = null;
    queueDetailEl.hidden = true;
    queueDetailEl.innerHTML = '';
    posRegisterView.hidden = false;
    renderQueue(queueOrders);
}

async function voidQueueOrder(uid, btn) {
    const order = queueOrders.find(o => o.uid === uid);
    if (!order) return;
    btn.disabled = true;
    btn.textContent = 'Voiding...';
    try {
        const fd = new FormData();
        fd.append('action', 'void_order');
        fd.append('order_id', order.id);
        fd.append('source', order.source);
        const res = await fetch(LIVE_QUEUE_API, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) alert(data.message || 'Unable to void this order.');
    } finally {
        lastQueueSignature = '';
        closeQueueOrder();
        refreshLiveQueue();
    }
}

// Inspect an order in place: items, total, and what the student must do to pay.
async function openQueueOrder(uid) {
    const order = queueOrders.find(o => o.uid === uid);
    if (!order) return;
    openQueueUid = uid;
    posRegisterView.hidden = true;
    queueDetailEl.hidden = false;
    queueDetailEl.innerHTML = '<div class="pq-empty">Loading&hellip;</div>';
    renderQueue(queueOrders);

    const backBtn = '<button type="button" class="pq-back" onclick="closeQueueOrder()"><i class="fa-solid fa-arrow-left"></i> Register</button>';
    try {
        const fd = new FormData();
        fd.append('action', 'view_order');
        fd.append('order_id', order.id);
        fd.append('source', order.source);
        const res = await fetch(LIVE_QUEUE_API, { method: 'POST', body: fd });
        const data = await res.json();
        if (openQueueUid !== uid) return;   // the cashier moved on while it loaded

        if (!data.success) {
            queueDetailEl.innerHTML = backBtn + `<div class="alert alert-danger mt-2 mb-0">${escapeHtml(data.message || 'Unable to load this order.')}</div>`;
            return;
        }

        const src = QUEUE_SOURCES[order.source] || QUEUE_SOURCES.pos;
        const items = Array.isArray(data.items) ? data.items : [];
        const itemRows = items.length
            ? items.map(item => {
                const qty = Number(item.qty || 0);
                const price = Number(item.price || 0);
                return `<tr>
                    <td>${escapeHtml(item.name || '')}</td>
                    <td class="text-center">${qty}</td>
                    <td class="text-end">${gcPriceHtml(item.line_total != null ? Number(item.line_total) : qty * price, true)}</td>
                </tr>`;
            }).join('')
            : `<tr><td colspan="3" class="text-muted text-center">${escapeHtml(data.description || 'No itemized details recorded.')}</td></tr>`;

        const stage = data.stage || order.stage;
        const badge = QUEUE_BADGES[stage] || `<span class="badge bg-light text-dark">${escapeHtml(data.status)}</span>`;
        const paidTime = data.paid_at ? new Date(data.paid_at.replace(' ', 'T')).toLocaleString() : null;
        const isPending = data.status === 'pending';

        let payBlock = '';
        if (isPending && data.wallet_qr_url) {
            payBlock = `
                <div class="pq-pay">
                    <img src="${escapeHtml(data.wallet_qr_url)}" alt="Shop Wallet QR">
                    <div>Pre-order: the student scans this Wallet QR from their Shop Cart to pay <strong>${gcAmount(Number(data.amount || 0))} GC</strong>.</div>
                </div>`;
        } else if (isPending && data.qr_payload) {
            payBlock = `
                <div class="pq-pay">
                    <div class="pq-pay-qr" id="pqPayQr"></div>
                    <div>Walk-in bill: scan from Shop Cart &rarr; Pay Now, or type code <strong>${escapeHtml(data.short_code || '----')}</strong>.</div>
                </div>`;
        }

        queueDetailEl.innerHTML = `
            ${backBtn}
            <div class="pq-detail-head">
                <span class="pq-no pq-no--lg">#${order.queue_no}</span>
                <div>
                    <div class="pq-src"><i class="fa-solid ${src.icon}"></i> ${src.label}</div>
                    <small class="text-muted">${escapeHtml(data.student_name || (order.source === 'pos' ? 'Walk-in customer' : 'Student'))} &middot; ${queueTime(data.created_at)}</small>
                </div>
                <span class="ms-auto">${badge}</span>
            </div>
            <table class="table table-sm align-middle mb-2 pq-items">
                <thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Subtotal</th></tr></thead>
                <tbody>${itemRows}</tbody>
            </table>
            <div class="pq-total"><span>Total</span>${gcPriceHtml(Number(data.amount || 0), true)}</div>
            ${data.maintenance_fee ? `
            <div class="pq-note">Student maintenance fee &middot; ${escapeHtml(data.maintenance_fee.state)}: &#8369;${Number(data.maintenance_fee.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}. Billed to tuition &mdash; not deducted from your sale.</div>` : ''}
            ${paidTime ? `<div class="pq-note">Paid: ${paidTime}</div>` : ''}
            ${payBlock}
            ${isPending ? `<div class="pq-note">Hand the order over only after this ticket turns <strong>Paid</strong>.</div>
            <button type="button" class="btn btn-sm btn-outline-danger w-100 mt-3" onclick="voidQueueOrder('${uid}', this)">&times; Void order</button>` : ''}`;

        if (isPending && data.qr_payload && !data.wallet_qr_url) {
            new QRCode(document.getElementById('pqPayQr'), {
                text: data.qr_payload, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.H
            });
        }
    } catch (error) {
        queueDetailEl.innerHTML = backBtn + '<div class="alert alert-danger mt-2 mb-0">Unable to reach the server. Please try again.</div>';
    }
}

refreshLiveQueue();
setInterval(refreshLiveQueue, 3000);
</script>

<!-- ── Load Wallet Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="lwModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
        <div class="modal-content" style="border-radius:20px;overflow:hidden;border:none">

            <div class="modal-header border-0 pb-0" style="background:var(--gp-success-bg);padding:20px 24px 12px">
                <div style="flex:1">
                    <h5 class="modal-title fw-bold" style="color:var(--gp-green-700);font-size:18px">
                        <i class="fa-solid fa-coins me-2"></i>Send GenCoin
                    </h5>
                    <div style="display:flex;gap:6px;margin-top:10px;align-items:center" id="lw-steps">
                        <div class="lw-dot lw-dot--active" data-step="1"></div>
                        <div style="flex:1;height:2px;background:var(--gp-success-bg);max-width:40px"></div>
                        <div class="lw-dot" data-step="2"></div>
                        <div style="flex:1;height:2px;background:var(--gp-success-bg);max-width:40px"></div>
                        <div class="lw-dot" data-step="3"></div>
                        <span id="lw-step-label" style="margin-left:8px;font-size:12px;color:var(--gp-muted);font-weight:600">Step 1 of 3</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="margin-top:-16px"></button>
            </div>

            <div class="modal-body" style="background:var(--gp-success-bg);padding:20px 24px 24px">

                <!-- STEP 1: Recipient type + Student ID -->
                <div id="lw-step-1">
                    <div style="display:flex;gap:8px;margin-bottom:14px">
                        <button type="button" class="lw-toggle-btn lw-toggle-btn--active" id="lw-toggle-student"
                                style="flex:1;border:1.5px solid var(--gp-success);border-radius:12px;padding:9px;font-size:13px;font-weight:700;background:var(--gp-success);color:#fff"
                                onclick="lwSetMode('student')">
                            <i class="fa-solid fa-user-graduate me-1"></i>Student
                        </button>
                        <button type="button" class="lw-toggle-btn" id="lw-toggle-parent"
                                style="flex:1;border:1.5px solid #a8dcbe;border-radius:12px;padding:9px;font-size:13px;font-weight:700;background:#fff;color:var(--gp-ink)"
                                onclick="lwSetMode('parent')">
                            <i class="fa-solid fa-people-roof me-1"></i>Parent
                        </button>
                    </div>
                    <p id="lw-step1-hint" style="font-size:13px;color:var(--gp-ink);margin-bottom:14px">Enter the Student ID of the wallet to load.</p>
                    <div style="position:relative">
                        <input type="text" id="lw-school-id" class="form-control" placeholder="e.g. GJC2026-0001"
                               autocomplete="off"
                               style="border-radius:12px;padding:12px 44px 12px 14px;font-size:14px;border:1.5px solid #a8dcbe">
                        <i class="fa-solid fa-magnifying-glass" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--gp-muted);pointer-events:none"></i>
                    </div>
                    <div id="lw-lookup-result" style="margin-top:10px;min-height:36px"></div>
                    <div style="display:flex;justify-content:flex-end;margin-top:16px">
                        <button type="button" id="lw-next-1" class="btn btn-success" disabled
                                style="border-radius:12px;padding:10px 28px;font-weight:600"
                                onclick="lwGoStep(2)">
                            Next <i class="fa-solid fa-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>

                <!-- STEP 2: Cash amount -->
                <div id="lw-step-2" style="display:none">
                    <div style="display:flex;align-items:center;gap:10px;background:#fff;border-radius:12px;padding:10px 14px;margin-bottom:16px">
                        <div style="width:36px;height:36px;border-radius:50%;background:var(--gp-success-bg);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--gp-green-700);font-size:15px" id="lw-avatar"></div>
                        <div>
                            <div style="font-weight:700;font-size:14px;color:var(--gp-ink)" id="lw-name-2"></div>
                            <div style="font-size:11px;color:var(--gp-muted)" id="lw-id-2"></div>
                        </div>
                    </div>

                    <label style="font-size:12px;font-weight:600;color:var(--gp-ink);margin-bottom:4px;display:block">Amount (₱)</label>
                    <div style="position:relative;margin-bottom:6px">
                        <input type="number" id="lw-gc" class="form-control" min="1" step="0.01"
                               placeholder="e.g. 50"
                               style="border-radius:12px;padding:12px 60px 12px 14px;font-size:20px;font-weight:700;border:1.5px solid #a8dcbe">
                        <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:13px;font-weight:600;color:var(--gp-muted)">₱</span>
                    </div>
                    <div id="lw-gc-equiv" style="font-size:12px;color:var(--gp-green-700);font-weight:600;margin-bottom:12px;padding-left:4px;min-height:18px"></div>

                    <!-- Fee breakdown -->
                    <div id="lw-fee-preview" style="display:none;background:#fff;border-radius:10px;padding:12px 14px;font-size:12px;border:1px solid #a8dcbe;margin-bottom:14px">
                        <div style="display:flex;justify-content:space-between;margin-bottom:5px">
                            <span style="color:var(--gp-green-700);font-weight:700">Credited to <span id="lw-fp-recipient-label">student</span></span>
                            <span id="lw-fp-credited" style="font-weight:800;color:var(--gp-green-700)"></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:5px">
                            <span style="color:var(--gp-red)">Service fee (<?= CirculationEngine::ratePct(CirculationEngine::FEE_SYSTEM_RATE + CirculationEngine::FEE_MERCHANT_RATE) ?>)</span>
                            <span id="lw-fp-fee" style="font-weight:600;color:var(--gp-red)"></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:5px;padding-left:12px">
                            <span style="color:var(--gp-muted);font-size:11px">↳ Your cut (<?= CirculationEngine::ratePct(CirculationEngine::FEE_MERCHANT_RATE) ?>, returned)</span>
                            <span id="lw-fp-mcut" style="font-size:11px;font-weight:600;color:var(--gp-success)"></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:5px;padding-left:12px">
                            <span style="color:var(--gp-muted);font-size:11px">↳ System (<?= CirculationEngine::ratePct(CirculationEngine::FEE_SYSTEM_RATE) ?>)</span>
                            <span id="lw-fp-sfee" style="font-size:11px;font-weight:600;color:var(--gp-muted)"></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;border-top:1px solid #a8dcbe;padding-top:8px;margin-top:4px">
                            <span style="color:var(--gp-ink);font-weight:700">Charged to your wallet</span>
                            <span id="lw-fp-cash" style="font-weight:800;color:var(--gp-ink)"></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-top:4px">
                            <span style="color:var(--gp-muted);font-size:11px">Net cost after your cut</span>
                            <span id="lw-fp-net" style="font-size:11px;font-weight:700;color:var(--gp-muted)"></span>
                        </div>
                    </div>

                    <div style="display:flex;justify-content:space-between;margin-top:4px">
                        <button type="button" class="btn btn-outline-secondary" style="border-radius:12px;padding:10px 20px" onclick="lwGoStep(1)">
                            <i class="fa-solid fa-arrow-left me-1"></i> Back
                        </button>
                        <button type="button" id="lw-next-2" class="btn btn-success" disabled
                                style="border-radius:12px;padding:10px 28px;font-weight:600"
                                onclick="lwGoStep(3)">
                            Next <i class="fa-solid fa-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>

                <!-- STEP 3: Confirm -->
                <div id="lw-step-3" style="display:none">
                    <p style="font-size:13px;color:var(--gp-ink);font-weight:600;margin-bottom:14px">Review before loading.</p>
                    <div style="background:#fff;border-radius:16px;padding:18px 20px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:18px;font-size:13px">
                        <div style="display:flex;flex-direction:column;gap:7px">
                            <div style="display:flex;justify-content:space-between"><span style="color:var(--gp-muted)" id="lw-prev-name-label">Student</span><strong id="lw-prev-name"></strong></div>
                            <div style="display:flex;justify-content:space-between"><span style="color:var(--gp-muted)">Student ID</span><span id="lw-prev-id" style="font-family:monospace"></span></div>
                        </div>
                        <div style="border-top:1px dashed #a8dcbe;margin:12px 0;padding-top:12px;display:flex;flex-direction:column;gap:6px;font-size:12px">
                            <div style="display:flex;justify-content:space-between"><span style="color:var(--gp-muted)">GenCoins</span><span id="lw-prev-gc-count" style="font-weight:600;color:var(--gp-green-700)"></span></div>
                            <div style="display:flex;justify-content:space-between;font-size:13px"><span style="color:var(--gp-green-700);font-weight:700">Credited to <span id="lw-prev-recipient-label">student</span></span><span id="lw-prev-credited" style="font-weight:800;color:var(--gp-green-700)"></span></div>
                            <div style="display:flex;justify-content:space-between"><span style="color:var(--gp-red)">Service fee (<?= CirculationEngine::ratePct(CirculationEngine::FEE_SYSTEM_RATE + CirculationEngine::FEE_MERCHANT_RATE) ?>)</span><span id="lw-prev-fee" style="font-weight:600;color:var(--gp-red)"></span></div>
                            <div style="display:flex;justify-content:space-between;padding-left:10px"><span style="color:var(--gp-muted);font-size:11px">↳ Your cut (<?= CirculationEngine::ratePct(CirculationEngine::FEE_MERCHANT_RATE) ?>, returned)</span><span id="lw-prev-mcut" style="font-size:11px;font-weight:600;color:var(--gp-success)"></span></div>
                            <div style="display:flex;justify-content:space-between;font-size:13px;border-top:1px solid #a8dcbe;padding-top:8px;margin-top:2px">
                                <span style="color:var(--gp-ink);font-weight:700">Charged to your wallet</span>
                                <span id="lw-prev-cash" style="font-weight:800"></span>
                            </div>
                        </div>
                    </div>

                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;background:#fff;border-radius:12px;padding:12px 14px;border:1.5px solid #a8dcbe">
                        <input type="checkbox" id="lw-confirm-check" style="width:18px;height:18px;margin-top:1px;accent-color:var(--gp-success);cursor:pointer">
                        <span style="font-size:13px;color:var(--gp-ink);line-height:1.5">I confirm I have received the cash and want to load <strong id="lw-confirm-credited"></strong> to <strong id="lw-confirm-name"></strong>'s wallet.</span>
                    </label>

                    <div id="lw-send-error" style="margin-top:10px;font-size:13px;color:var(--gp-red);min-height:18px"></div>

                    <div style="display:flex;justify-content:space-between;margin-top:16px">
                        <button type="button" class="btn btn-outline-secondary" style="border-radius:12px;padding:10px 20px" onclick="lwGoStep(2)">
                            <i class="fa-solid fa-arrow-left me-1"></i> Back
                        </button>
                        <button type="button" id="lw-load-btn" class="btn btn-success" disabled
                                style="border-radius:12px;padding:10px 28px;font-weight:700;font-size:15px"
                                onclick="lwLoad()">
                            <i class="fa-solid fa-paper-plane me-1"></i> Send
                        </button>
                    </div>
                </div>

                <!-- SUCCESS -->
                <div id="lw-success" style="display:none;text-align:center;padding:16px 0">
                    <div style="width:64px;height:64px;background:var(--gp-success-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
                        <i class="fa-solid fa-circle-check" style="font-size:32px;color:var(--gp-success)"></i>
                    </div>
                    <div style="font-size:18px;font-weight:700;color:var(--gp-green-700);margin-bottom:4px">Sent!</div>
                    <div style="font-size:13px;color:var(--gp-muted)" id="lw-success-msg"></div>
                    <div style="margin-top:10px;display:inline-block;background:var(--gp-success-bg);border:1px solid #a8dcbe;border-radius:8px;padding:6px 14px">
                        <span style="font-size:11px;color:var(--gp-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">Reference No.</span><br>
                        <span id="lw-success-ref" style="font-size:13px;font-weight:700;color:var(--gp-green-700);font-family:monospace;letter-spacing:.5px"></span>
                    </div>
                    <button type="button" class="btn btn-success mt-4 d-block mx-auto" data-bs-dismiss="modal"
                            style="border-radius:12px;padding:10px 32px;font-weight:600">Done</button>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
const LW_API = '<?= MERCHANT_URL ?>/api/topup.php';
let lwWalletId = 0, lwStudentName = '', lwSchoolId = '';
let lwMode = 'student'; // 'student' | 'parent'
let lwParentId = 0;

function lwFmt(n) {
    return '₱' + (+n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// Peso -> GenCoin equivalent for display (₱10 = 1 GC).
function lwGc(cash) {
    return (cash / 10).toLocaleString('en-PH', {maximumFractionDigits:2});
}

// `cash` is what the RECIPIENT RECEIVES; both fees are added on top. The
// merchant is debited base + both fees and gets their own cut straight back,
// so their real cost is base + systemFee. Rates come from CirculationEngine.
const LW_SYSTEM_RATE   = <?= json_encode(CirculationEngine::FEE_SYSTEM_RATE) ?>;
const LW_MERCHANT_RATE = <?= json_encode(CirculationEngine::FEE_MERCHANT_RATE) ?>;

function lwCalcFee(cash) {
    const systemFee   = Math.round(cash * LW_SYSTEM_RATE * 100) / 100;
    const merchantFee = Math.round(cash * LW_MERCHANT_RATE * 100) / 100;
    const credited    = Math.round(cash * 100) / 100;
    const totalFee    = Math.round((systemFee + merchantFee) * 100) / 100;
    const charged     = Math.round((credited + totalFee) * 100) / 100;
    const netCost     = Math.round((credited + systemFee) * 100) / 100;
    return { systemFee, merchantFee, totalFee, credited, charged, netCost };
}

function lwOpen() {
    lwReset();
    new bootstrap.Modal(document.getElementById('lwModal')).show();
}

function lwReset() {
    lwWalletId = 0; lwStudentName = ''; lwSchoolId = ''; lwParentId = 0;
    lwSetMode('student');
    ['lw-school-id','lw-gc'].forEach(id => { const el = document.getElementById(id); if(el) el.value=''; });
    document.getElementById('lw-gc-equiv').textContent = '';
    document.getElementById('lw-lookup-result').innerHTML = '';
    document.getElementById('lw-next-1').disabled = true;
    document.getElementById('lw-next-2').disabled = true;
    document.getElementById('lw-fee-preview').style.display = 'none';
    document.getElementById('lw-confirm-check').checked = false;
    document.getElementById('lw-load-btn').disabled = true;
    document.getElementById('lw-send-error').textContent = '';
    ['lw-step-1','lw-step-2','lw-step-3','lw-success'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
    document.getElementById('lw-step-1').style.display = '';
    lwUpdateStepUI(1);
}

function lwSetMode(mode) {
    lwMode = mode;
    lwWalletId = 0; lwParentId = 0; lwStudentName = ''; lwSchoolId = '';
    document.getElementById('lw-school-id').value = '';
    document.getElementById('lw-lookup-result').innerHTML = '';
    document.getElementById('lw-next-1').disabled = true;

    const studentBtn = document.getElementById('lw-toggle-student');
    const parentBtn  = document.getElementById('lw-toggle-parent');
    const isStudent  = mode === 'student';
    studentBtn.style.background = isStudent ? 'var(--gp-success)' : '#fff';
    studentBtn.style.color      = isStudent ? '#fff' : 'var(--gp-ink)';
    studentBtn.style.borderColor= isStudent ? 'var(--gp-success)' : '#a8dcbe';
    parentBtn.style.background  = isStudent ? '#fff' : 'var(--gp-success)';
    parentBtn.style.color       = isStudent ? 'var(--gp-ink)' : '#fff';
    parentBtn.style.borderColor = isStudent ? '#a8dcbe' : 'var(--gp-success)';

    document.getElementById('lw-step1-hint').textContent = isStudent
        ? 'Enter the Student ID of the wallet to load.'
        : 'Enter the Student ID of a linked student to find their parent.';
    document.getElementById('lw-fp-recipient-label').textContent = isStudent ? 'student' : 'parent';
    document.getElementById('lw-prev-recipient-label').textContent = isStudent ? 'student' : 'parent';
    document.getElementById('lw-prev-name-label').textContent = isStudent ? 'Student' : 'Parent';
}

function lwSelectParent(parentId, name) {
    lwParentId = parentId;
    lwStudentName = name; // reused as "recipient name" for preview/confirm/success text
    document.getElementById('lw-next-1').disabled = false;
    document.getElementById('lw-avatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('lw-name-2').textContent = name;
    document.getElementById('lw-id-2').textContent = lwSchoolId;
    document.querySelectorAll('.lw-parent-choice').forEach(el => {
        el.classList.toggle('lw-parent-choice--active', el.dataset.parentId == parentId);
    });
}

function lwUpdateStepUI(step) {
    document.getElementById('lw-step-label').textContent = `Step ${step} of 3`;
    document.querySelectorAll('.lw-dot').forEach(d => {
        const s = parseInt(d.dataset.step);
        d.classList.toggle('lw-dot--active', s === step);
        d.classList.toggle('lw-dot--done', s < step);
    });
}

function lwGoStep(step) {
    ['lw-step-1','lw-step-2','lw-step-3'].forEach((id, i) => {
        document.getElementById(id).style.display = (i+1 === step) ? '' : 'none';
    });
    lwUpdateStepUI(step);
    if (step === 3) lwBuildPreview();
}

function lwBuildPreview() {
    const cash = parseFloat(document.getElementById('lw-gc').value) || 0;
    const { systemFee, merchantFee, totalFee, credited, charged } = lwCalcFee(cash);

    document.getElementById('lw-prev-name').textContent       = lwStudentName;
    document.getElementById('lw-prev-id').textContent         = lwSchoolId;
    document.getElementById('lw-prev-gc-count').textContent   = lwGc(cash) + ' GC';
    document.getElementById('lw-prev-cash').textContent       = lwFmt(charged);
    document.getElementById('lw-prev-fee').textContent        = '+ ' + lwFmt(totalFee);
    document.getElementById('lw-prev-mcut').textContent       = '+ ' + lwFmt(merchantFee);
    document.getElementById('lw-prev-credited').textContent   = lwFmt(credited);
    document.getElementById('lw-confirm-credited').textContent = lwFmt(credited);
    document.getElementById('lw-confirm-name').textContent    = lwStudentName;

    document.getElementById('lw-confirm-check').checked = false;
    document.getElementById('lw-load-btn').disabled = true;
}

async function lwLookup() {
    const schoolId = document.getElementById('lw-school-id').value.trim();
    const resultEl = document.getElementById('lw-lookup-result');
    const nextBtn  = document.getElementById('lw-next-1');
    if (!schoolId) return;

    resultEl.innerHTML = '<span style="font-size:12px;color:var(--gp-muted)">Looking up…</span>';
    nextBtn.disabled = true;
    lwWalletId = 0;
    lwParentId = 0;
    lwSchoolId = schoolId;

    if (lwMode === 'student') {
        try {
            const res  = await fetch(LW_API, {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({action:'lookup_student', school_id: schoolId}),
            });
            const data = await res.json();
            if (data.success) {
                lwWalletId    = data.wallet_id;
                lwStudentName = data.name;
                resultEl.innerHTML = `
                    <div style="display:flex;align-items:center;gap:8px;background:var(--gp-info-bg);border-radius:10px;padding:8px 12px">
                        <i class="fa-solid fa-circle-check" style="color:var(--gp-info)"></i>
                        <div>
                            <strong style="font-size:13px;color:var(--gp-info)">${data.name}</strong>
                            <div style="font-size:11px;color:var(--gp-muted)">${schoolId}</div>
                        </div>
                    </div>`;
                nextBtn.disabled = false;
                document.getElementById('lw-avatar').textContent  = data.name.charAt(0).toUpperCase();
                document.getElementById('lw-name-2').textContent  = data.name;
                document.getElementById('lw-id-2').textContent    = schoolId;
            } else {
                resultEl.innerHTML = `<div style="font-size:12px;color:var(--gp-red);padding:4px 2px"><i class="fa-solid fa-triangle-exclamation me-1"></i>${data.error||'Student not found.'}</div>`;
            }
        } catch {
            resultEl.innerHTML = '<div style="font-size:12px;color:var(--gp-red)">Network error. Try again.</div>';
        }
        return;
    }

    // Parent mode — parents have no school ID of their own, resolved through
    // the linked student's school ID instead.
    try {
        const res  = await fetch(LW_API, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'lookup_parent_by_student', school_id: schoolId}),
        });
        const data = await res.json();
        if (data.success && data.parents && data.parents.length) {
            if (data.parents.length === 1) {
                const p = data.parents[0];
                resultEl.innerHTML = `
                    <div style="display:flex;align-items:center;gap:8px;background:var(--gp-info-bg);border-radius:10px;padding:8px 12px">
                        <i class="fa-solid fa-circle-check" style="color:var(--gp-info)"></i>
                        <div>
                            <strong style="font-size:13px;color:var(--gp-info)">${p.name}</strong>
                            <div style="font-size:11px;color:var(--gp-muted)">Parent of ${schoolId}</div>
                        </div>
                    </div>`;
                lwSelectParent(p.parent_id, p.name);
            } else {
                resultEl.innerHTML = '<div style="font-size:12px;color:var(--gp-ink);margin-bottom:6px">Multiple parents linked — choose one:</div>' +
                    data.parents.map(p => `
                        <div class="lw-parent-choice" data-parent-id="${p.parent_id}" onclick="lwSelectParent(${p.parent_id}, '${p.name.replace(/'/g, "\\'")}')"
                             style="cursor:pointer;padding:8px 12px;border-radius:10px;border:1.5px solid #a8dcbe;margin-bottom:6px;font-size:13px;font-weight:600;color:var(--gp-ink)">
                            ${p.name}
                        </div>`).join('');
            }
        } else {
            resultEl.innerHTML = `<div style="font-size:12px;color:var(--gp-red);padding:4px 2px"><i class="fa-solid fa-triangle-exclamation me-1"></i>${data.error||'No parent found.'}</div>`;
        }
    } catch {
        resultEl.innerHTML = '<div style="font-size:12px;color:var(--gp-red)">Network error. Try again.</div>';
    }
}

async function lwLoad() {
    const loadBtn = document.getElementById('lw-load-btn');
    const errorEl = document.getElementById('lw-send-error');
    const cash    = parseFloat(document.getElementById('lw-gc').value) || 0;  // entered in ₱
    const { credited, totalFee, merchantFee } = lwCalcFee(cash);

    loadBtn.disabled = true;
    loadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Loading…';
    errorEl.textContent = '';

    const payload = lwMode === 'student'
        ? { action: 'load_wallet', student_wallet_id: lwWalletId, cash_amount: cash }
        : { action: 'load_parent_wallet', parent_id: lwParentId, cash_amount: cash };
    const recipientNoun = lwMode === 'student' ? 'student' : 'parent';

    try {
        const res  = await fetch(LW_API, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            const actualCredited = data.credited_amount ?? credited;
            const actualFee      = data.fee_amount      ?? totalFee;
            const actualMFee     = data.merchant_fee    ?? merchantFee;
            const actualCharged  = data.total_collected ?? (actualCredited + actualFee);
            document.getElementById('lw-step-3').style.display  = 'none';
            document.getElementById('lw-success').style.display = '';
            document.getElementById('lw-step-label').textContent = 'Complete';
            document.getElementById('lw-success-msg').textContent =
                lwFmt(actualCredited) + ' credited to ' + lwStudentName + ` (${recipientNoun})` +
                '. Charged to your wallet: ' + lwFmt(actualCharged) +
                ', your cut returned: ' + lwFmt(actualMFee) + '.';
            document.getElementById('lw-success-ref').textContent = data.reference || '—';
        } else {
            errorEl.textContent = data.error || 'Failed. Please try again.';
            loadBtn.disabled = false;
            loadBtn.innerHTML = '<i class="fa-solid fa-wallet me-1"></i> Load';
        }
    } catch {
        errorEl.textContent = 'Network error. Please try again.';
        loadBtn.disabled = false;
        loadBtn.innerHTML = '<i class="fa-solid fa-wallet me-1"></i> Load';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    if (new URLSearchParams(location.search).get('open') === 'loadwallet') lwOpen();

    const idInput = document.getElementById('lw-school-id');
    idInput.addEventListener('keydown', e => { if(e.key==='Enter'){e.preventDefault();lwLookup();} });
    idInput.addEventListener('blur', lwLookup);

    document.getElementById('lw-gc').addEventListener('input', function () {
        const cash = parseFloat(this.value) || 0;   // amount is entered in ₱
        const next = document.getElementById('lw-next-2');
        const prev = document.getElementById('lw-fee-preview');
        if (cash > 0) {
            const { systemFee, merchantFee, totalFee, credited, charged, netCost } = lwCalcFee(cash);
            document.getElementById('lw-gc-equiv').textContent   = '≈ ' + lwGc(cash) + ' GenCoins (₱10 = 1 GC)';
            document.getElementById('lw-fp-credited').textContent= lwFmt(credited);
            document.getElementById('lw-fp-fee').textContent     = '+ ' + lwFmt(totalFee);
            document.getElementById('lw-fp-mcut').textContent    = lwFmt(merchantFee);
            document.getElementById('lw-fp-sfee').textContent    = lwFmt(systemFee);
            document.getElementById('lw-fp-cash').textContent    = lwFmt(charged);
            document.getElementById('lw-fp-net').textContent     = lwFmt(netCost);
            prev.style.display = '';
            next.disabled = false;
        } else {
            document.getElementById('lw-gc-equiv').textContent = '';
            prev.style.display = 'none';
            next.disabled = true;
        }
    });

    document.getElementById('lw-confirm-check').addEventListener('change', function () {
        document.getElementById('lw-load-btn').disabled = !this.checked;
    });

    document.getElementById('lwModal').addEventListener('hidden.bs.modal', lwReset);
});

</script>
<?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>
</html>
