<?php
session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['student']);
gjc_enforce_graduate_lock($db);
$currentUser = gjc_current_user($db);
$wallet      = gjc_student_wallet($db, $currentUser['id']);
$studentName = $currentUser['name'];
$balance     = (float) $wallet['balance'];
$dailySent   = gjc_p2p_daily_sent($db, $currentUser['id']);
$dailyLimit  = 5000.00;
$dailyRemaining = max(0, $dailyLimit - $dailySent);
$sendMax     = min($balance, $dailyRemaining);

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
$currentPage = 'transfer';
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
    <title>Send GenCoin | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=30">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_scan.css?v=9">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_profile.css?v=13">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_topup.css?v=7">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_send.css?v=7">
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_student.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'Send GenCoin';
            $topbarSubtitle = 'Send GenCoins instantly to another student.';
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
                            <a class="sd-btn-ghost" href="<?= STUDENT_URL ?>/withdraw.php"><i class="fa-solid fa-money-bill-wave"></i>Withdraw</a>
                        </div>
                    </div>
                    <div class="sd-balance-holder">
                        <strong><?= $e($studentName) ?></strong>
                        <span class="sd-holder-id"><?= $e($studentID) ?></span>
                        <span class="sd-role-badge">STUDENT</span>
                    </div>
                </section>

                <!-- Send form + guide -->
                <section class="tu-grid">

                    <div class="sd-panel">
                        <div class="sd-panel-head">
                            <div>
                                <h3>Send to a Student</h3>
                                <p>Look up the recipient, enter an amount, and confirm.</p>
                            </div>
                        </div>

                        <!-- Form view -->
                        <div class="pf-form" id="sgForm">
                            <div class="pf-field">
                                <label for="sgRecipient">Send to (Student ID)</label>
                                <input type="text" id="sgRecipient" placeholder="e.g. GJC2026-0001" autocomplete="off">
                                <div class="sg-recip" id="sgRecipMsg"></div>
                            </div>

                            <div class="pf-field">
                                <label for="sgAmount">Amount (&#8369;)</label>
                                <div class="tu-money-input">
                                    <span>&#8369;</span>
                                    <input type="number" id="sgAmount" min="1" max="<?= $sendMax ?>" step="0.01" placeholder="0.00">
                                </div>
                                <div class="sg-equiv" id="sgEquiv"></div>
                                <small>Available: <?= gjc_money($balance) ?> (&#8776; <?= gjc_gc_amount($balance) ?> GC) &middot; Daily remaining: <?= gjc_money($dailyRemaining) ?></small>
                            </div>

                            <div class="pf-field">
                                <label for="sgMessage">Message <span style="font-weight:400;color:var(--sd-muted)">(optional)</span></label>
                                <input type="text" id="sgMessage" maxlength="255" placeholder="e.g. For lunch &#127836;">
                            </div>

                            <div class="sg-error" id="sgError"></div>

                            <button type="button" class="pf-btn pf-btn--block" id="sgSend" disabled>
                                <i class="fa-solid fa-paper-plane me-1"></i> <span id="sgSendLabel">Send</span>
                            </button>
                        </div>

                        <!-- Success view -->
                        <div class="sg-success" id="sgSuccess" style="display:none">
                            <div class="sg-success-icon"><i class="fa-solid fa-check"></i></div>
                            <h4>Sent!</h4>
                            <p id="sgSuccessMsg"></p>
                            <div class="sg-success-ref" id="sgSuccessRef">--</div>
                            <div class="sg-btn-row">
                                <button type="button" class="sp-btn-cancel" onclick="location.href='<?= DASHBOARD_URL ?>'">Done</button>
                                <button type="button" class="sp-btn-pay" onclick="location.reload()">Send Another</button>
                            </div>
                        </div>
                    </div>

                    <div class="sd-panel">
                        <div class="sd-panel-head">
                            <div>
                                <h3>How It Works</h3>
                                <p>Peer-to-peer transfers between student wallets.</p>
                            </div>
                        </div>

                        <div class="tu-steps">
                            <div class="tu-step">
                                <strong>1</strong>
                                <span>Type the recipient&rsquo;s Student ID — their name appears once found.</span>
                            </div>
                            <div class="tu-step">
                                <strong>2</strong>
                                <span>Enter the amount in pesos; the GenCoin value shows below it.</span>
                            </div>
                            <div class="tu-step">
                                <strong>3</strong>
                                <span>Review the details in the confirmation card before sending.</span>
                            </div>
                            <div class="tu-step">
                                <strong>4</strong>
                                <span>Transfers are instant and cannot be undone.</span>
                            </div>
                        </div>

                        <div class="tu-limit">
                            <span>Daily Send Limit</span>
                            <strong><?= gjc_gc_amount($dailyLimit) ?> GC</strong>
                            <p>&#8776; <?= gjc_money($dailyLimit) ?> &middot; Remaining today: <strong><?= gjc_gc_amount($dailyRemaining) ?> GC</strong>. Limits reset at midnight.</p>
                        </div>
                    </div>

                </section>

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_student.php'; ?>

    <!-- Send confirmation -->
    <div class="modal fade sp-modal" id="sgConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"
         aria-labelledby="sgConfirmModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="sgConfirmModalTitle">Confirm Transfer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-2">
                    <div class="sp-pay-amount">
                        <span>Amount to send</span>
                        <strong id="sgConfirmAmount">--</strong>
                    </div>
                    <div class="sp-pay-rows">
                        <div class="sp-pay-row">
                            <label>To</label>
                            <strong id="sgConfirmName">--</strong>
                        </div>
                        <div class="sp-pay-row">
                            <label>Student ID</label>
                            <strong id="sgConfirmSid">--</strong>
                        </div>
                        <div class="sp-pay-row">
                            <label>GenCoin value</label>
                            <strong id="sgConfirmGc">--</strong>
                        </div>
                        <div class="sp-pay-row" id="sgConfirmMsgRow" style="display:none">
                            <label>Message</label>
                            <strong id="sgConfirmMsg">--</strong>
                        </div>
                    </div>
                    <div class="sp-pay-error" id="sgConfirmError"></div>
                    <p style="font-size:12px;color:var(--sd-muted);text-align:center;margin:12px 0 0">
                        Transfers are instant and cannot be undone.
                    </p>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="sp-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="sp-btn-pay" id="sgConfirmSend">
                            <i class="fa-solid fa-paper-plane me-1"></i> Confirm Send
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <script>
    // ── Single-screen Send GenCoin (uses the same api/transfer.php lookup + transfer) ──
    const CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
    const SG_API = '<?= STUDENT_URL ?>/api/transfer.php';
    const PESOS_PER_GC = <?= GJC_PESOS_PER_GC ?>;
    // The input is peso-denominated (matches the API); GC shows as an equivalence line.
    const SG_MAX = <?= json_encode(round((float) $sendMax, 2)) ?>;
    let sgName = '', sgSid = '', sgValidRecipient = false;

    const sgRecipient = document.getElementById('sgRecipient');
    const sgRecipMsg  = document.getElementById('sgRecipMsg');
    const sgAmount    = document.getElementById('sgAmount');
    const sgEquiv     = document.getElementById('sgEquiv');
    const sgError     = document.getElementById('sgError');
    const sgSend      = document.getElementById('sgSend');
    const sgSendLabel = document.getElementById('sgSendLabel');

    const sgConfirmModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('sgConfirmModal'));
    const sgConfirmSend  = document.getElementById('sgConfirmSend');
    const sgConfirmError = document.getElementById('sgConfirmError');

    function sgFmt(n) { return '₱' + (+n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    // Smart GC formatting: whole numbers stay whole ("2"), otherwise up to 2 decimals.
    function sgGcFmt(gc) { return (+(+gc).toFixed(2)).toLocaleString('en-PH', { maximumFractionDigits: 2 }); }

    function sgRefresh() {
        const pesos = parseFloat(sgAmount.value) || 0;
        sgEquiv.textContent = pesos > 0 ? ('They will receive ≈ ' + sgGcFmt(pesos / PESOS_PER_GC) + ' GC (₱10 = 1 GC)') : '';
        sgSendLabel.textContent = pesos > 0 ? ('Send ' + sgFmt(pesos)) : 'Send';
        sgSend.disabled = !(sgValidRecipient && pesos >= 1 && pesos <= SG_MAX);
    }

    // Recipient lookup (on blur / Enter; edits reset the confirmed recipient)
    sgRecipient.addEventListener('input', () => { sgValidRecipient = false; sgName = ''; sgSid = ''; sgRefresh(); });
    sgRecipient.addEventListener('blur', sgLookup);
    sgRecipient.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); sgLookup(); } });

    async function sgLookup() {
        const sid = sgRecipient.value.trim();
        if (!sid) { sgRecipMsg.textContent = ''; return; }
        sgRecipMsg.textContent = 'Looking up…'; sgRecipMsg.style.color = 'var(--sd-muted)';
        try {
            const f = new FormData();
            f.append('action', 'lookup');
            f.append('csrf_token', CSRF);
            f.append('student_id', sid);
            const d = await (await fetch(SG_API, { method: 'POST', body: f })).json();
            if (d.success && d.name) {
                sgName = d.name; sgSid = sid; sgValidRecipient = true;
                sgRecipMsg.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i>' + d.name;
                sgRecipMsg.style.color = 'var(--sd-green)';
            } else {
                sgValidRecipient = false;
                sgRecipMsg.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i>' + (d.message || 'Student not found');
                sgRecipMsg.style.color = 'var(--sd-red)';
            }
        } catch (e) {
            sgValidRecipient = false;
            sgRecipMsg.textContent = 'Network error. Try again.'; sgRecipMsg.style.color = 'var(--sd-red)';
        }
        sgRefresh();
    }

    sgAmount.addEventListener('input', () => { sgError.textContent = ''; sgRefresh(); });

    // Open the styled confirmation card (replaces the old native confirm()).
    sgSend.addEventListener('click', () => {
        const pesos = parseFloat(sgAmount.value) || 0;
        sgError.textContent = '';
        if (!sgValidRecipient) { sgError.textContent = 'Enter a valid recipient Student ID first.'; return; }
        if (pesos < 1)      { sgError.textContent = 'Enter an amount of at least ₱1.00.'; return; }
        if (pesos > SG_MAX) { sgError.textContent = 'Amount exceeds your available balance / daily limit (' + sgFmt(SG_MAX) + ').'; return; }

        document.getElementById('sgConfirmAmount').textContent = sgFmt(pesos);
        document.getElementById('sgConfirmName').textContent = sgName;
        document.getElementById('sgConfirmSid').textContent = sgSid;
        document.getElementById('sgConfirmGc').textContent = sgGcFmt(pesos / PESOS_PER_GC) + ' GC';

        const msg = document.getElementById('sgMessage').value.trim();
        document.getElementById('sgConfirmMsgRow').style.display = msg ? '' : 'none';
        document.getElementById('sgConfirmMsg').textContent = msg;

        sgConfirmError.style.display = 'none';
        sgConfirmError.textContent = '';
        sgConfirmSend.disabled = false;
        sgConfirmSend.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Confirm Send';
        sgConfirmModal.show();
    });

    sgConfirmSend.addEventListener('click', async () => {
        const pesos = parseFloat(sgAmount.value) || 0;
        sgConfirmSend.disabled = true;
        sgConfirmSend.textContent = 'Sending…';
        sgConfirmError.style.display = 'none';

        try {
            const f = new FormData();
            f.append('action', 'transfer');
            f.append('csrf_token', CSRF);
            f.append('recipient_student_id', sgSid);
            f.append('amount', pesos.toFixed(2));
            f.append('message', document.getElementById('sgMessage').value.trim());
            const d = await (await fetch(SG_API, { method: 'POST', body: f })).json();
            if (d.success) {
                sgConfirmModal.hide();
                document.getElementById('sgForm').style.display = 'none';
                document.getElementById('sgSuccess').style.display = '';
                document.getElementById('sgSuccessMsg').textContent = d.message || 'Transfer complete.';
                document.getElementById('sgSuccessRef').textContent = d.reference || '—';
                if (window.gjcRefreshNotifications) window.gjcRefreshNotifications();
            } else {
                sgConfirmError.textContent = d.message || 'Transfer failed.';
                sgConfirmError.style.display = 'block';
                sgConfirmSend.disabled = false;
                sgConfirmSend.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Confirm Send';
            }
        } catch (e) {
            sgConfirmError.textContent = 'Network error. Please try again.';
            sgConfirmError.style.display = 'block';
            sgConfirmSend.disabled = false;
            sgConfirmSend.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Confirm Send';
        }
    });
    </script>

</body>

</html>
