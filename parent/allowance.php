<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);
gjc_ensure_parent_wallet_schema($db);

$parentUserId = gjc_user_id();
$currentUser  = gjc_current_user($db);
$parentName   = $currentUser['name'];

$parentId = gjc_parent_id_for_user($db, $parentUserId);
$wallet   = gjc_parent_wallet($db, $parentId);
$balance  = (float) $wallet['balance'];

$linkedStmt = $db->prepare(
    "SELECT u.userID, u.first_name, u.last_name, si.studentID
       FROM parent_student_links psl
       JOIN users u ON u.userID = psl.student_user_id
       LEFT JOIN student_info si ON si.userID = u.userID
      WHERE psl.parent_id = ?
      ORDER BY u.last_name, u.first_name"
);
$linkedStmt->execute([$parentId]);
$linkedStudents = $linkedStmt->fetchAll(PDO::FETCH_ASSOC);

// Sent-allowance history — one transactions row per send carries both
// parent_wallet_id and student_wallet_id, so this query alone is enough.
$history = [];
if (gjc_table_exists($db, 'transactions')) {
    $histStmt = $db->prepare(
        "SELECT t.reference_no, t.amount, t.notes, t.created_at, u.first_name, u.last_name
           FROM transactions t
           JOIN student_wallets sw ON sw.id = t.student_wallet_id
           JOIN users u ON u.userID = sw.user_id
          WHERE t.parent_wallet_id = ? AND t.transaction_type = 'allowance'
          ORDER BY t.created_at DESC
          LIMIT 8"
    );
    $histStmt->execute([$wallet['id']]);
    $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrfToken = gjc_csrf_token();
$currentPage = 'allowance';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Allowance | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=21">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=30">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_scan.css?v=9">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_profile.css?v=13">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_topup.css?v=7">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_send.css?v=7">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_wallet.css?v=8">
</head>

<body class="gp-theme">
<div class="parent-layout">

    <?php require __DIR__ . '/../includes/partials/sidebar_parent.php'; ?>

    <main class="parent-main">

        <?php
        $topbarTitle = 'Send Allowance';
        $topbarSubtitle = 'Send GenCoins instantly to a linked student.';
        require __DIR__ . '/../includes/partials/topbar_parent.php';
        ?>

        <div class="parent-content" style="max-width:none;">

            <!-- Balance card -->
            <section class="sd-balance">
                <div>
                    <span class="sd-balance-label">Wallet Balance</span>
                    <div class="sd-balance-amount">
                        <span id="sdBalance"><?= gjc_gc_amount($balance) ?></span><span class="sd-unit">GC</span>
                    </div>
                    <div class="sd-gc-row">
                        <span class="sd-gc-badge">&#8776; &#8369;<span id="sdBalancePhp"><?= number_format($balance, 2) ?></span></span>
                        <span class="sd-gc-rate">&#8369;10 = 1 GC</span>
                    </div>
                    <div class="sd-balance-actions">
                        <a class="sd-btn-ghost" href="<?= PARENT_URL ?>/cart.php"><i class="fa-solid fa-cart-shopping"></i>Shop Cart</a>
                    </div>
                </div>
                <div class="sd-balance-holder">
                    <strong><?= $e($parentName) ?></strong>
                    <span class="sd-holder-id"><?= count($linkedStudents) ?> linked student<?= count($linkedStudents) === 1 ? '' : 's' ?></span>
                    <span class="sd-role-badge">PARENT</span>
                </div>
            </section>

            <?php if (empty($linkedStudents)): ?>
            <section class="sd-panel">
                <div class="sd-empty">
                    <i class="fa-regular fa-folder-open"></i>
                    No students linked yet. Link a student from your <a href="<?= PARENT_URL ?>/dashboard.php">Dashboard</a> first.
                </div>
            </section>
            <?php else: ?>

            <!-- Send form + guide -->
            <section class="tu-grid">

                <div class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>Send to a Student</h3>
                            <p>Pick a linked student, enter an amount, and confirm.</p>
                        </div>
                    </div>

                    <!-- Form view -->
                    <div class="pf-form" id="sgForm">
                        <div class="pf-field">
                            <label for="sgRecipient">Send to</label>
                            <select id="sgRecipient" class="tu-select">
                                <option value="">Select a student…</option>
                                <?php foreach ($linkedStudents as $s): ?>
                                <option value="<?= (int) $s['userID'] ?>" data-name="<?= $e(trim($s['first_name'] . ' ' . $s['last_name'])) ?>" data-sid="<?= $e($s['studentID'] ?? 'N/A') ?>">
                                    <?= $e(trim($s['first_name'] . ' ' . $s['last_name'])) ?> — <?= $e($s['studentID'] ?? 'N/A') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pf-field">
                            <label for="sgAmount">Amount (&#8369;)</label>
                            <div class="tu-money-input">
                                <span>&#8369;</span>
                                <input type="number" id="sgAmount" min="1" max="<?= $balance ?>" step="0.01" placeholder="0.00">
                            </div>
                            <div class="sg-equiv" id="sgEquiv"></div>
                            <small>Available: <?= gjc_money($balance) ?> (&#8776; <?= gjc_gc_amount($balance) ?> GC)</small>
                        </div>

                        <div class="pf-field">
                            <label for="sgMessage">Note <span style="font-weight:400;color:var(--sd-muted)">(optional)</span></label>
                            <input type="text" id="sgMessage" maxlength="120" placeholder="e.g. For school supplies">
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
                            <button type="button" class="sp-btn-cancel" onclick="location.href='<?= PARENT_URL ?>/dashboard.php'">Done</button>
                            <button type="button" class="sp-btn-pay" onclick="location.reload()">Send Another</button>
                        </div>
                    </div>
                </div>

                <div class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>How It Works</h3>
                            <p>One-time allowance transfers from your wallet to a linked student.</p>
                        </div>
                    </div>

                    <div class="tu-steps">
                        <div class="tu-step">
                            <strong>1</strong>
                            <span>Pick which linked student should receive the allowance.</span>
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
                        <span>Sent Allowance History</span>
                        <?php if (empty($history)): ?>
                        <p style="margin-top:8px;">No allowances sent yet.</p>
                        <?php else: ?>
                        <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                            <?php foreach ($history as $h): ?>
                            <div style="display:flex;justify-content:space-between;font-size:13px;border-bottom:1px solid var(--sd-line);padding-bottom:6px;">
                                <span><?= $e(trim($h['first_name'] . ' ' . $h['last_name'])) ?></span>
                                <strong>&#8369;<?= number_format((float) $h['amount'], 2) ?></strong>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </section>

            <?php endif; ?>

        </div>

    </main>
</div>

<!-- Send confirmation -->
<div class="modal fade sp-modal" id="sgConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"
     aria-labelledby="sgConfirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="sgConfirmModalTitle">Confirm Allowance</h5>
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
                        <label>Note</label>
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
function toggleParentSidebar() {
    document.getElementById('parentSidebar').classList.toggle('collapsed');
}

<?php if (!empty($linkedStudents)): ?>
// ── Single-screen Send Allowance (uses parent/api/allowance.php) ──
const CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
const SG_API = '<?= PARENT_URL ?>/api/allowance.php';
const PESOS_PER_GC = <?= GJC_PESOS_PER_GC ?>;
const SG_MAX = <?= json_encode(round($balance, 2)) ?>;
let sgName = '', sgSid = '', sgUserId = '', sgValidRecipient = false;

const sgRecipient = document.getElementById('sgRecipient');
const sgAmount    = document.getElementById('sgAmount');
const sgEquiv     = document.getElementById('sgEquiv');
const sgError     = document.getElementById('sgError');
const sgSend      = document.getElementById('sgSend');
const sgSendLabel = document.getElementById('sgSendLabel');

const sgConfirmModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('sgConfirmModal'));
const sgConfirmSend  = document.getElementById('sgConfirmSend');
const sgConfirmError = document.getElementById('sgConfirmError');

function sgFmt(n) { return '₱' + (+n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function sgGcFmt(gc) { return (+(+gc).toFixed(2)).toLocaleString('en-PH', { maximumFractionDigits: 2 }); }

function sgRefresh() {
    const pesos = parseFloat(sgAmount.value) || 0;
    sgEquiv.textContent = pesos > 0 ? ('They will receive ≈ ' + sgGcFmt(pesos / PESOS_PER_GC) + ' GC (₱10 = 1 GC)') : '';
    sgSendLabel.textContent = pesos > 0 ? ('Send ' + sgFmt(pesos)) : 'Send';
    sgSend.disabled = !(sgValidRecipient && pesos >= 1 && pesos <= SG_MAX);
}

sgRecipient.addEventListener('change', () => {
    const opt = sgRecipient.selectedOptions[0];
    sgValidRecipient = !!opt && !!opt.value;
    sgName = opt ? (opt.dataset.name || '') : '';
    sgSid = opt ? (opt.dataset.sid || '') : '';
    sgUserId = opt ? opt.value : '';
    sgRefresh();
});

sgAmount.addEventListener('input', () => { sgError.textContent = ''; sgRefresh(); });

sgSend.addEventListener('click', () => {
    const pesos = parseFloat(sgAmount.value) || 0;
    sgError.textContent = '';
    if (!sgValidRecipient) { sgError.textContent = 'Select a student first.'; return; }
    if (pesos < 1)      { sgError.textContent = 'Enter an amount of at least ₱1.00.'; return; }
    if (pesos > SG_MAX) { sgError.textContent = 'Amount exceeds your available wallet balance (' + sgFmt(SG_MAX) + ').'; return; }

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
        const res = await fetch(SG_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'send',
                student_user_id: sgUserId,
                amount: pesos.toFixed(2),
                note: document.getElementById('sgMessage').value.trim(),
                csrf_token: CSRF,
            }),
        });
        const d = await res.json();
        if (d.success) {
            sgConfirmModal.hide();
            document.getElementById('sgForm').style.display = 'none';
            document.getElementById('sgSuccess').style.display = '';
            document.getElementById('sgSuccessMsg').textContent = d.message || 'Transfer complete.';
            document.getElementById('sgSuccessRef').textContent = d.reference || '—';
        } else {
            sgConfirmError.textContent = d.error || 'Transfer failed.';
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
<?php endif; ?>
</script>


<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
