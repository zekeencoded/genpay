<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['student']);
gjc_enforce_graduate_lock($db);

if (isset($_SESSION['force_change'])) {
    header('Location: ' . BASE_URL . '/change_password.php');
    exit();
}

$currentUser = gjc_current_user($db);
$wallet      = gjc_student_wallet($db, (int) $currentUser['id']);
$studentName = $currentUser['name'];
$balance     = (float) $wallet['balance'];

// Real school-issued ID (GJC2026-0001); the padded userID is only a fallback
// for accounts that never got a student_info row.
$studentID = 'GJC-' . str_pad((string) $currentUser['id'], 5, '0', STR_PAD_LEFT);
if (gjc_table_exists($db, 'student_info')) {
    $sidStmt = $db->prepare("SELECT studentID FROM student_info WHERE userID = ? LIMIT 1");
    $sidStmt->execute([(int) $currentUser['id']]);
    $realID = trim((string) $sidStmt->fetchColumn());
    if ($realID !== '') {
        $studentID = $realID;
    }
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'cart';
$csrfToken = gjc_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Cart | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=30">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_scan.css?v=9">
    <link rel="stylesheet" href="<?= CSS_URL ?>/cart.css?v=9">

    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
</head>

<body class="sd-body sp-page">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_student.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'Shop Cart';
            $topbarSubtitle = 'Scan each item&rsquo;s barcode, submit your order, then pay at the counter by scanning the shop&rsquo;s Wallet QR.';
            require __DIR__ . '/../includes/partials/topbar_student.php';
            ?>

            <div class="sd-content">

                <!-- Balance card -->
                <section class="sd-balance">
                    <div>
                        <span class="sd-balance-label">Current Balance</span>
                        <div class="sd-balance-amount">
                            <span id="sdBalance"><?= gjc_gc_amount($balance) ?></span><span class="sd-unit">GC</span>
                        </div>
                        <div class="sd-gc-row">
                            <span class="sd-gc-badge">&#8776; &#8369;<span id="sdBalancePhp"><?= number_format($balance, 2) ?></span></span>
                            <span class="sd-gc-rate">&#8369;10 = 1 GC</span>
                        </div>
                        <div class="sd-balance-actions">
                            <a class="sd-btn-ghost" href="<?= STUDENT_URL ?>/transfer.php"><i class="fa-solid fa-paper-plane"></i>Send</a>
                            <a class="sd-btn-ghost" href="<?= STUDENT_URL ?>/withdraw.php"><i class="fa-solid fa-money-bill-wave"></i>Withdraw</a>
                        </div>
                    </div>
                    <div class="sd-balance-holder">
                        <strong><?= $e($studentName) ?></strong>
                        <span class="sd-holder-id"><?= $e($studentID) ?></span>
                        <span class="sd-role-badge">STUDENT</span>
                    </div>
                </section>

                <!-- Scanner + cart -->
                <section class="sp-grid">

                    <div class="sd-panel sp-scanner">

                        <!-- Mobile-only chrome (full-screen scanner) -->
                        <div class="sp-mobile-bar sp-mobile-only">
                            <a href="<?= DASHBOARD_URL ?>" class="sp-mobile-iconbtn" aria-label="Back to dashboard">
                                <i class="fa-solid fa-chevron-left"></i>
                            </a>
                            <h2>Shop Cart</h2>
                            <button type="button" class="sp-mobile-iconbtn" id="switchCameraBtnM" aria-label="Switch camera">
                                <i class="fa-solid fa-camera-rotate"></i>
                            </button>
                        </div>

                        <div class="sd-panel-head">
                            <div>
                                <h3>Item Scanner</h3>
                                <p id="scannerHint">Point the camera at an item&rsquo;s barcode on the cardboard menu.</p>
                            </div>
                            <span class="sp-status" id="cameraStatus">Starting Camera</span>
                        </div>

                        <div class="sp-frame" id="scanFrame">
                            <video id="qrVideo" autoplay playsinline muted></video>
                            <canvas id="qrCanvas" hidden></canvas>

                            <span class="sp-corner tl" aria-hidden="true"></span>
                            <span class="sp-corner tr" aria-hidden="true"></span>
                            <span class="sp-corner bl" aria-hidden="true"></span>
                            <span class="sp-corner br" aria-hidden="true"></span>
                            <span class="sp-scanline" aria-hidden="true"></span>

                            <div class="sp-frame-msg" id="frameMsg">
                                <div>
                                    <strong id="frameMsgTitle">Opening camera&hellip;</strong>
                                    <small id="frameMsgSub"></small>
                                    <button type="button" class="sp-retry-btn" id="retryCameraBtn" hidden>
                                        <i class="fa-solid fa-rotate-right me-1"></i> Retry Camera
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="sp-mobile-caption sp-mobile-only">
                            <strong id="mobileCaptionTitle">Align the item&rsquo;s barcode</strong>
                            <span id="mobileCaptionSub">Items are added to your cart automatically</span>
                        </div>

                        <div class="sp-mobile-toast" id="mobileToast">
                            <div id="mobileToastText"></div>
                        </div>

                        <button type="button" class="sp-mobile-pill sp-mobile-only" id="viewCartBtnMobile">
                            <i class="fa-solid fa-cart-shopping"></i> <span id="viewCartBtnLabel">View Cart</span>
                        </button>

                        <div class="sp-toolbar">
                            <button type="button" class="sp-btn" id="switchCameraBtn">Switch Camera</button>
                        </div>

                        <div id="cartAlerts" class="mt-3"></div>
                    </div>

                    <div class="sd-panel sp-cart-sheet" id="cartSheet">
                        <div class="sd-panel-head">
                            <div>
                                <h3 id="cartPanelTitle">Your Cart</h3>
                                <p id="cartMerchantHint">No items scanned yet.</p>
                            </div>
                            <button type="button" class="sp-sheet-close" id="closeCartSheetBtn" aria-label="Close cart">
                                <i class="fa-solid fa-chevron-down"></i>
                            </button>
                        </div>

                        <div id="cartMerchantPillWrap"></div>

                        <!-- Builder view: shown while there's no pending order yet -->
                        <div id="cartBuilderView">
                            <div class="cart-line-list" id="cartLineList">
                                <div class="cart-empty" id="cartEmptyState">Scan an item to start your order.</div>
                            </div>

                            <div class="cart-total-row">
                                <span>Total</span>
                                <span id="cartTotal">&#8369;0.00</span>
                            </div>

                            <button type="button" class="sp-btn-pay cart-panel-btn mt-3" id="submitOrderBtn" disabled>
                                Submit Order
                            </button>
                            <button type="button" class="sp-btn-cancel cart-panel-btn mt-2" id="clearCartBtn">
                                &#10005; Clear Cart
                            </button>
                        </div>

                        <!-- Pending-order view: shown after Submit Order, until paid or cancelled -->
                        <div id="pendingOrderView" class="d-none">
                            <div class="text-center">
                                <span class="pending-order-badge">Awaiting Payment</span>
                                <h4 class="mb-1 pending-order-ref" id="pendingOrderRef">--</h4>
                            </div>

                            <ul class="list-unstyled mt-3" id="pendingOrderLineList" style="font-size:13px;color:var(--sd-muted);"></ul>

                            <div class="cart-total-row">
                                <span>Total</span>
                                <span id="pendingOrderTotal">&#8369;0.00</span>
                            </div>

                            <p class="pending-order-note">Go to the counter and scan the shop&rsquo;s Wallet QR to pay.</p>

                            <button type="button" class="sp-btn-cancel cart-panel-btn mt-2" id="cancelOrderBtn">
                                Cancel Order
                            </button>
                        </div>
                    </div>

                </section>

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_student.php'; ?>

    <!-- Payment confirmation -->
    <div class="modal fade sp-modal" id="cartPayConfirmModal" tabindex="-1" aria-labelledby="cartPayConfirmModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="cartPayConfirmModalTitle">Confirm Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-2">
                    <div class="sp-pay-amount">
                        <span>Total to pay</span>
                        <strong id="payConfirmTotal">--</strong>
                    </div>
                    <div class="sp-pay-rows">
                        <div class="sp-pay-row">
                            <label>Merchant</label>
                            <strong id="payConfirmMerchant">--</strong>
                        </div>
                        <div class="sp-pay-row">
                            <label>Items</label>
                            <strong id="payConfirmItemCount">--</strong>
                        </div>
                        <div class="sp-pay-row">
                            <label>Peso value</label>
                            <strong id="payConfirmPhp">--</strong>
                        </div>
                    </div>
                    <ul class="list-unstyled mt-3 mb-0" id="payConfirmLineList" style="font-size:13px;color:var(--sd-muted);"></ul>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button type="button" class="sp-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="sp-btn-pay" id="cartPayConfirmBtn">Confirm Payment</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>

    <script>
    const CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
    const CART_API = "<?= STUDENT_URL ?>/api/cart.php";
    const CHECKOUT_API = "<?= STUDENT_URL ?>/api/checkout.php";
    const PESOS_PER_GC = <?= GJC_PESOS_PER_GC ?>;

    // Smart GC formatting: whole numbers stay whole ("2"), otherwise up to 2 decimals ("14.59").
    function gcAmt(pesos) {
        return (+((pesos / PESOS_PER_GC).toFixed(2))).toLocaleString('en-PH', { maximumFractionDigits: 2 });
    }
    // Two-line GC-first price tag (same .gc-price style the merchant POS uses).
    function gcPriceHtml(pesos) {
        return `<span class="gc-price gc-price--end"><span class="gc-price-main">${gcAmt(pesos)} GC</span><span class="gc-price-sub">≈ ₱${(+pesos).toFixed(2)}</span></span>`;
    }

    const video = document.getElementById("qrVideo");
    const canvas = document.getElementById("qrCanvas");
    const canvasContext = canvas.getContext("2d", { willReadFrequently: true });
    const scanFrame = document.getElementById("scanFrame");
    const cameraStatus = document.getElementById("cameraStatus");
    const frameMsg = document.getElementById("frameMsg");
    const frameMsgTitle = document.getElementById("frameMsgTitle");
    const frameMsgSub = document.getElementById("frameMsgSub");
    const retryCameraBtn = document.getElementById("retryCameraBtn");
    const scannerHint = document.getElementById("scannerHint");
    const switchCameraBtn = document.getElementById("switchCameraBtn");
    const submitOrderBtn = document.getElementById("submitOrderBtn");
    const clearCartBtn = document.getElementById("clearCartBtn");
    const cancelOrderBtn = document.getElementById("cancelOrderBtn");
    const cartAlerts = document.getElementById("cartAlerts");
    const cartLineList = document.getElementById("cartLineList");
    const cartTotal = document.getElementById("cartTotal");
    const cartMerchantHint = document.getElementById("cartMerchantHint");
    const cartMerchantPillWrap = document.getElementById("cartMerchantPillWrap");
    const cartPanelTitle = document.getElementById("cartPanelTitle");
    const cartBuilderView = document.getElementById("cartBuilderView");
    const pendingOrderView = document.getElementById("pendingOrderView");
    const pendingOrderRef = document.getElementById("pendingOrderRef");
    const pendingOrderLineList = document.getElementById("pendingOrderLineList");
    const pendingOrderTotal = document.getElementById("pendingOrderTotal");

    const cartPayConfirmModalEl = document.getElementById("cartPayConfirmModal");
    const cartPayConfirmModal = bootstrap.Modal.getOrCreateInstance(cartPayConfirmModalEl);
    const cartPayConfirmBtn = document.getElementById("cartPayConfirmBtn");
    const payConfirmMerchant = document.getElementById("payConfirmMerchant");
    const payConfirmItemCount = document.getElementById("payConfirmItemCount");
    const payConfirmTotal = document.getElementById("payConfirmTotal");
    const payConfirmLineList = document.getElementById("payConfirmLineList");

    let activeStream = null;
    let scanLoopRunning = false;
    let currentFacingMode = "environment";
    let scanCooldown = false;
    let scanningPaused = false;
    let lastDetectedPayload = "";
    let currentPendingOrder = null; // set once the student has Submitted an Order, cleared on pay/cancel
    let pendingWalletPayload = null;

    function setCameraStatus(text, tone = "") {
        const map = { active: "is-active", blocked: "is-blocked" };
        cameraStatus.className = "sp-status" + (map[tone] ? " " + map[tone] : "");
        cameraStatus.textContent = text;
    }

    function showFrameMsg(title, sub = "", isError = false, withRetry = false) {
        frameMsg.style.display = "grid";
        frameMsg.className = "sp-frame-msg" + (isError ? " is-error" : "");
        frameMsgTitle.textContent = title;
        frameMsgSub.textContent = sub;
        retryCameraBtn.hidden = !withRetry;
    }

    function hideFrameMsg() {
        frameMsg.style.display = "none";
    }

    function setPaused(paused) {
        scanningPaused = paused;
        scanFrame.classList.toggle("is-paused", paused);
    }

    const mobileToast = document.getElementById("mobileToast");
    const mobileToastText = document.getElementById("mobileToastText");
    let toastTimer = null;

    function showAlert(message, type = "danger") {
        cartAlerts.innerHTML = `<div class="alert alert-${type} py-2 px-3 mb-0" style="font-size:13px">${message}</div>`;
        clearTimeout(window.__cartAlertTimer);
        window.__cartAlertTimer = setTimeout(() => { cartAlerts.innerHTML = ""; }, 4500);

        // Mirror into the floating toast on the mobile full-screen scanner.
        mobileToastText.textContent = message.replace(/<[^>]*>/g, "");
        mobileToast.className = "sp-mobile-toast is-visible" + (type === "danger" ? " is-error" : "");
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => mobileToast.classList.remove("is-visible"), 4500);
    }

    // ── Mobile cart bottom sheet ────────────────────────────────────────
    const cartSheet = document.getElementById("cartSheet");
    const viewCartBtnLabel = document.getElementById("viewCartBtnLabel");
    const mobileCaptionTitle = document.getElementById("mobileCaptionTitle");
    const mobileCaptionSub = document.getElementById("mobileCaptionSub");

    document.getElementById("viewCartBtnMobile").addEventListener("click", () => {
        cartSheet.classList.toggle("is-open");
    });
    document.getElementById("closeCartSheetBtn").addEventListener("click", () => {
        cartSheet.classList.remove("is-open");
    });

    function updateMobilePill(snapshot) {
        if (currentPendingOrder) {
            viewCartBtnLabel.textContent = "View Order";
            return;
        }
        const lines = (snapshot && snapshot.lines) || [];
        const count = lines.reduce((sum, l) => sum + l.qty, 0);
        viewCartBtnLabel.textContent = count
            ? `View Cart · ${count} item${count > 1 ? "s" : ""} · ${gcAmt(Number(snapshot.total || 0))} GC`
            : "View Cart";
    }

    function escapeHtml(value) {
        const div = document.createElement("div");
        div.textContent = value ?? "";
        return div.innerHTML;
    }

    function renderCart(snapshot) {
        const lines = snapshot.lines || [];

        if (snapshot.merchant_label) {
            cartMerchantHint.textContent = "Shopping at:";
            cartMerchantPillWrap.innerHTML = `<span class="cart-merchant-pill">${escapeHtml(snapshot.merchant_label)}</span>`;
        } else {
            cartMerchantHint.textContent = "No items scanned yet.";
            cartMerchantPillWrap.innerHTML = "";
        }

        if (!lines.length) {
            cartLineList.innerHTML = '<div class="cart-empty" id="cartEmptyState">Scan an item to start your order.</div>';
        } else {
            cartLineList.innerHTML = lines.map(line => `
                <div class="cart-line" data-item-id="${line.id}">
                    <div>
                        <div class="cart-line-name">${escapeHtml(line.name)}</div>
                        ${line.sku ? `<div class="cart-line-sku">SKU: ${escapeHtml(line.sku)}</div>` : ""}
                    </div>
                    <div class="cart-line-qty">
                        <button type="button" onclick="changeQty(${line.id}, ${line.qty - 1})">-</button>
                        <span>${line.qty}</span>
                        <button type="button" onclick="changeQty(${line.id}, ${line.qty + 1})">+</button>
                    </div>
                    <div class="cart-line-price">${gcPriceHtml(line.line_total)}</div>
                    <button type="button" class="cart-line-remove" onclick="removeLine(${line.id})">&times;</button>
                </div>
            `).join("");
        }

        cartTotal.innerHTML = gcPriceHtml(Number(snapshot.total || 0));
        submitOrderBtn.disabled = lines.length === 0;

        if (Array.isArray(snapshot.dropped) && snapshot.dropped.length) {
            const reasons = snapshot.dropped.map(d => `${escapeHtml(d.name)} (${escapeHtml(d.reason)})`).join(", ");
            showAlert(`Removed from cart: ${reasons}`, "warning");
        }

        updateMobilePill(snapshot);
    }

    function renderPendingOrder(order) {
        currentPendingOrder = order || null;
        if (!order) return;

        pendingOrderRef.textContent = order.reference;
        const lines = order.lines || [];
        pendingOrderLineList.innerHTML = lines.map(line =>
            `<li>${line.qty}&times; ${escapeHtml(line.name)} <span class="text-muted">&mdash; ${gcAmt(line.line_total)} GC</span></li>`
        ).join("");
        pendingOrderTotal.innerHTML = gcPriceHtml(Number(order.total || 0));
    }

    function updateUiState() {
        const hasPending = !!currentPendingOrder;
        cartBuilderView.classList.toggle("d-none", hasPending);
        pendingOrderView.classList.toggle("d-none", !hasPending);
        cartPanelTitle.textContent = hasPending ? "Your Order" : "Your Cart";
        scannerHint.textContent = hasPending
            ? "Go to the counter and scan the shop's Wallet QR to pay."
            : "Point the camera at an item's barcode on the cardboard menu.";

        mobileCaptionTitle.textContent = hasPending
            ? "Scan the shop's Wallet QR"
            : "Align the item's barcode";
        mobileCaptionSub.textContent = hasPending
            ? "Pay at the counter to finish your order"
            : "Items are added to your cart automatically";
        if (hasPending) {
            viewCartBtnLabel.textContent = "View Order";
        }
    }

    async function callCartApi(action, extra = {}) {
        const form = new FormData();
        form.append("action", action);
        form.append("csrf_token", CSRF);
        Object.entries(extra).forEach(([key, value]) => form.append(key, value));
        const response = await fetch(CART_API, { method: "POST", body: form });
        return response.json();
    }

    async function refreshCart() {
        try {
            const result = await callCartApi("get_cart");
            if (result.success) renderCart(result);
        } catch (error) {
            showAlert("Unable to load your cart. Check your connection.");
        }
        updateUiState();
    }

    async function loadPendingOrder() {
        try {
            const result = await callCartApi("get_pending_order");
            if (result.success) renderPendingOrder(result.order);
        } catch (error) {
            // Non-critical on page load — student can still build a cart.
        }
        updateUiState();
    }

    async function addItemByCode(code) {
        if (currentPendingOrder) {
            showAlert("You already have an order awaiting payment. Cancel it first to keep shopping.", "warning");
            return;
        }
        try {
            const result = await callCartApi("add_item", { code });
            if (!result.success) {
                showAlert(result.message || "Unable to add this item.", result.blocked ? "danger" : "warning");
                return;
            }
            renderCart(result);
            showAlert(result.message || "Item added.", "success");
        } catch (error) {
            showAlert("Unable to reach the server. Please try again.");
        }
    }

    async function changeQty(itemId, qty) {
        const result = await callCartApi("update_qty", { item_id: itemId, qty });
        if (result.success) renderCart(result);
    }

    async function removeLine(itemId) {
        const result = await callCartApi("remove_item", { item_id: itemId });
        if (result.success) renderCart(result);
    }

    clearCartBtn.addEventListener("click", async () => {
        const result = await callCartApi("clear_cart");
        if (result.success) {
            renderCart(result);
            showAlert("Cart cleared.", "success");
        }
    });

    submitOrderBtn.addEventListener("click", async () => {
        submitOrderBtn.disabled = true;
        submitOrderBtn.textContent = "Submitting...";

        try {
            const result = await callCartApi("submit_order");
            if (!result.success) {
                showAlert(result.message || "Unable to submit your order.", "warning");
                if (result.dropped) refreshCart();
                return;
            }

            renderPendingOrder(result.order);
            updateUiState();
            showAlert(result.message || "Order submitted.", "success");
        } catch (error) {
            showAlert("Unable to reach the server. Please try again.");
        } finally {
            submitOrderBtn.disabled = false;
            submitOrderBtn.textContent = "Submit Order";
        }
    });

    cancelOrderBtn.addEventListener("click", async () => {
        cancelOrderBtn.disabled = true;
        cancelOrderBtn.textContent = "Cancelling...";

        try {
            const result = await callCartApi("cancel_my_order");
            if (!result.success) {
                showAlert(result.message || "Unable to cancel this order.", "warning");
                return;
            }

            currentPendingOrder = null;
            updateUiState();
            refreshCart();
            showAlert("Order cancelled.", "success");
        } finally {
            cancelOrderBtn.disabled = false;
            cancelOrderBtn.textContent = "Cancel Order";
        }
    });

    function openPayConfirmModal(walletPayload) {
        pendingWalletPayload = walletPayload;
        setPaused(true);
        cartSheet.classList.remove("is-open");

        const order = currentPendingOrder || { lines: [], total: 0 };
        const lines = order.lines || [];

        payConfirmMerchant.textContent = walletPayload.merchant || order.merchant_label || "--";
        payConfirmItemCount.textContent = lines.reduce((sum, l) => sum + l.qty, 0) + " item(s)";
        payConfirmTotal.textContent = gcAmt(Number(order.total || 0)) + " GC";
        document.getElementById("payConfirmPhp").textContent = "₱" + Number(order.total || 0).toFixed(2);
        payConfirmLineList.innerHTML = lines.map(line =>
            `<li>${line.qty}&times; ${escapeHtml(line.name)} &mdash; ${gcAmt(line.line_total)} GC</li>`
        ).join("");

        cartPayConfirmModal.show();
    }

    cartPayConfirmModalEl.addEventListener("hidden.bs.modal", () => {
        pendingWalletPayload = null;
        setPaused(false);
        lastDetectedPayload = "";
        if (activeStream) {
            setCameraStatus("Camera Active", "active");
        }
    });

    cartPayConfirmBtn.addEventListener("click", async () => {
        if (!pendingWalletPayload) return;
        const walletPayload = pendingWalletPayload;

        cartPayConfirmBtn.disabled = true;
        cartPayConfirmBtn.textContent = "Processing...";

        try {
            const form = new FormData();
            form.append("action", "pay_order");
            form.append("csrf_token", CSRF);
            form.append("merchant_wallet_id", walletPayload.merchant_wallet_id);
            form.append("merchant_user_id", walletPayload.merchant_user_id);
            const response = await fetch(CHECKOUT_API, { method: "POST", body: form });
            const result = await response.json();

            if (!result.success) {
                showAlert(result.message || "Payment failed.");
                cartPayConfirmModal.hide();
                return;
            }

            cartPayConfirmModal.hide();
            showAlert(`Payment completed. Reference: ${result.reference}`, "success");
            currentPendingOrder = null;
            updateUiState();
            refreshCart();
            if (window.gjcRefreshNotifications) window.gjcRefreshNotifications();
        } catch (error) {
            showAlert("Checkout isn't available right now. Please try again later.");
            cartPayConfirmModal.hide();
        } finally {
            cartPayConfirmBtn.disabled = false;
            cartPayConfirmBtn.textContent = "Confirm Payment";
        }
    });

    async function handleScannedPayload(raw) {
        let parsed = null;
        try { parsed = JSON.parse(raw); } catch (ignored) { /* not JSON, treat as plain code */ }

        if (currentPendingOrder) {
            if (!parsed || parsed.type !== "merchant_wallet") {
                showAlert("That's not a Shop Wallet QR. Ask the merchant for their wallet QR to pay.");
                return;
            }

            openPayConfirmModal(parsed);
            return;
        }

        // No pending order yet — treat the raw scanned text as an item's SKU/barcode.
        if (parsed && parsed.type === "merchant_wallet") {
            showAlert("Add items and tap \"Submit Order\" first, then scan the Wallet QR to pay.", "warning");
            return;
        }
        const code = parsed && parsed.sku ? parsed.sku : raw.trim();
        addItemByCode(code);
    }

    function stopScannerStream() {
        if (activeStream) {
            activeStream.getTracks().forEach(track => track.stop());
            activeStream = null;
        }
    }

    async function startScanner(facingMode = currentFacingMode) {
        stopScannerStream();
        currentFacingMode = facingMode;
        showFrameMsg("Opening camera…");
        setCameraStatus("Starting Camera");

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: currentFacingMode } });
            activeStream = stream;
            video.srcObject = stream;
            hideFrameMsg();
            setCameraStatus("Camera Active", "active");
            if (!scanLoopRunning) {
                scanLoopRunning = true;
                requestAnimationFrame(scanLoop);
            }
        } catch (error) {
            setCameraStatus("Camera Blocked", "blocked");
            showFrameMsg(
                "Camera access denied.",
                "Please allow camera permissions, then retry.",
                true,
                true
            );
        }
    }

    function scanLoop() {
        if (!activeStream) {
            scanLoopRunning = false;
            return;
        }

        if (!scanningPaused && video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvasContext.drawImage(video, 0, 0, canvas.width, canvas.height);

            const imageData = canvasContext.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: "attemptBoth" });

            if (code && code.data && code.data !== lastDetectedPayload && !scanCooldown) {
                lastDetectedPayload = code.data;
                scanCooldown = true;
                handleScannedPayload(code.data).finally(() => {
                    setTimeout(() => {
                        scanCooldown = false;
                        lastDetectedPayload = "";
                    }, 1200);
                });
            }
        }
        requestAnimationFrame(scanLoop);
    }

    function switchCamera() {
        const nextFacingMode = currentFacingMode === "environment" ? "user" : "environment";
        startScanner(nextFacingMode);
    }

    switchCameraBtn.addEventListener("click", switchCamera);
    document.getElementById("switchCameraBtnM").addEventListener("click", switchCamera);

    retryCameraBtn.addEventListener("click", () => startScanner());

    window.addEventListener("beforeunload", stopScannerStream);

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setCameraStatus("Camera Unsupported", "blocked");
        showFrameMsg("This browser does not support camera scanning.", "", true, false);
    } else {
        startScanner();
    }

    refreshCart();
    loadPendingOrder();
    </script>

</body>

</html>
