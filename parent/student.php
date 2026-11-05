<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);

$parentUserId    = gjc_user_id();
$currentUser     = gjc_current_user($db);
$profileImg      = (string) ($currentUser['raw']['profile_img'] ?? '');
$profilePhotoUrl = ($profileImg !== '') ? (BASE_URL . '/' . ltrim($profileImg, '/')) : '';

$pStmt = $db->prepare("SELECT id FROM parents WHERE user_id = ?");
$pStmt->execute([$parentUserId]);
$parentRow = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$parentRow) {
    header('Location: ' . PARENT_URL . '/dashboard.php');
    exit;
}
$parentId = (int) $parentRow['id'];

$targetUid = (int) ($_GET['uid'] ?? 0);
if (!$targetUid) {
    header('Location: ' . PARENT_URL . '/dashboard.php');
    exit;
}

// Verify this parent is linked to this student
$linkChk = $db->prepare(
    "SELECT 1 FROM parent_student_links WHERE parent_id = ? AND student_user_id = ?"
);
$linkChk->execute([$parentId, $targetUid]);
if (!$linkChk->fetch()) {
    header('Location: ' . PARENT_URL . '/dashboard.php?link_error=Access+denied.');
    exit;
}

$sStmt = $db->prepare(
    "SELECT u.first_name, u.last_name, si.studentID, si.graduated_at,
            sw.id AS wallet_id, sw.balance, sw.is_frozen, sw.daily_spend_limit
       FROM users u
       LEFT JOIN student_info si ON si.userID = u.userID
       LEFT JOIN student_wallets sw ON sw.user_id = u.userID
      WHERE u.userID = ?"
);
$sStmt->execute([$targetUid]);
$student = $sStmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    header('Location: ' . PARENT_URL . '/dashboard.php');
    exit;
}

$walletId    = (int) ($student['wallet_id'] ?? 0);
$studentName = trim($student['first_name'] . ' ' . $student['last_name']);
$firstName   = explode(' ', $studentName)[0];
$credit      = gjc_student_waiver_credit($db, $targetUid);

$filterType = trim($_GET['type'] ?? 'all');
$allowedTypes = ['all', 'cash_in', 'payment', 'p2p_transfer', 'allowance'];
if (!in_array($filterType, $allowedTypes, true)) $filterType = 'all';

$txns = [];
if ($walletId && gjc_table_exists($db, 'transactions')) {
    $typeWhere = ($filterType !== 'all') ? "AND transaction_type = '{$filterType}'" : '';
    $tStmt = $db->prepare(
        "SELECT id, reference_no, transaction_type, amount, notes, created_at, status
           FROM transactions
          WHERE student_wallet_id = ? {$typeWhere}
          ORDER BY created_at DESC
          LIMIT 100"
    );
    $tStmt->execute([$walletId]);
    $txns = $tStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Compute running balance (most-recent first → reverse for running balance)
$runningBalance = (float) $student['balance'];
$txnsWithBalance = [];
foreach ($txns as $t) {
    $txnsWithBalance[] = array_merge($t, ['running_balance' => $runningBalance]);
    if (in_array($t['transaction_type'], ['cash_in', 'allowance'], true)) {
        $runningBalance -= (float) $t['amount'];
    } else {
        $runningBalance += (float) $t['amount'];
    }
}

// Same colours as the student's transaction types.
$typeLabels = [
    'cash_in'       => ['label' => 'Top-Up',   'icon' => 'fa-circle-plus',    'color' => '#15803d', 'bg' => '#dcf3e4'],
    'payment'       => ['label' => 'POS',       'icon' => 'fa-store',          'color' => '#b45309', 'bg' => '#fdf1d8'],
    'p2p_transfer'  => ['label' => 'Transfer',  'icon' => 'fa-money-bill-transfer', 'color' => '#2563eb', 'bg' => '#e3edfd'],
    'allowance'     => ['label' => 'Allowance', 'icon' => 'fa-hand-holding-dollar', 'color' => '#15803d', 'bg' => '#dcf3e4'],
    'fee_waiver_claim' => ['label' => 'Fee Waiver', 'icon' => 'fa-hand-holding-dollar', 'color' => '#15803d', 'bg' => '#dcf3e4'],
];

$tabs = [
    'all'          => 'All',
    'cash_in'      => 'Top-ups',
    'payment'      => 'POS',
    'p2p_transfer' => 'Transfers',
    'allowance'    => 'Allowance',
];

$csrfToken = gjc_csrf_token();
$currentPage = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($studentName) ?> — Ledger | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=27">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_student.css?v=10">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
</head>
<body class="gp-theme">
<div class="parent-layout">

    <?php require __DIR__ . '/../includes/partials/sidebar_parent.php'; ?>

    <main class="parent-main">

        <?php
        $topbarTitle = 'Transaction Ledger';
        $topbarSubtitle = 'Read-only view of ' . htmlspecialchars($studentName) . "'s wallet activity.";
        require __DIR__ . '/../includes/partials/topbar_parent.php';
        ?>

        <div class="parent-content parent-content--wide">

            <a href="<?= PARENT_URL ?>/dashboard.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
            </a>

            <!-- Balance and the controls a parent changes from here -->
            <section class="ledger-hero">
                <div class="ledger-hero-id">
                    <strong><?= htmlspecialchars($studentName) ?></strong>
                    <span><?= htmlspecialchars($student['studentID'] ?? 'N/A') ?></span>
                </div>
                <div class="ledger-balance">&#8369;<?= number_format((float)$student['balance'], 2) ?></div>
                <div class="ledger-caption">Current balance</div>
                <div class="ledger-hero-foot">
                    <?php if (!empty($student['graduated_at'])): ?>
                    <span class="ledger-pill ledger-pill--alert"><i class="fa-solid fa-graduation-cap"></i>Graduated, locked</span>
                    <?php elseif ($student['is_frozen']): ?>
                    <span class="ledger-pill ledger-pill--alert"><i class="fa-solid fa-lock"></i>Frozen</span>
                    <?php endif; ?>
                    <?php if ((float)$student['daily_spend_limit'] > 0): ?>
                    <span class="ledger-pill"><i class="fa-solid fa-gauge"></i>Daily limit &#8369;<?= number_format((float)$student['daily_spend_limit'], 2) ?></span>
                    <?php endif; ?>
                    <a class="ledger-pill" href="<?= PARENT_URL ?>/controls.php?uid=<?= $targetUid ?>"><i class="fa-solid fa-sliders"></i>Manage controls</a>
                </div>
            </section>

            <!-- Fees: each item shows its amount, one line of explanation, and one action -->
            <section class="ledger-fees">

                <!-- Fee Waiver Credit (not GenCoin). -->
                <div class="fee-item">
                    <div class="fee-item-head">
                        <h3>Fee waiver credit</h3>
                        <?php if ($credit['status'] === 'pending'): ?>
                            <span class="parent-badge parent-badge--warning">Pending</span>
                        <?php elseif ($credit['status'] === 'posted'): ?>
                            <span class="parent-badge parent-badge--success">Posted</span>
                        <?php elseif ($credit['status'] === 'claimed'): ?>
                            <span class="parent-badge parent-badge--success">Claimed</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($credit['status'] === 'pending'): ?>
                        <p class="fee-amount">&#8369;<?= number_format((float) $credit['amount'], 2) ?></p>
                        <p class="fee-hint">Waiting for the signed waiver. Download the blank form, have it signed, and return it to finance.</p>
                        <div class="fee-actions">
                            <a class="fee-link" href="<?= ADMIN_URL ?>/print_fee_waiver.php?student_user_id=<?= $targetUid ?>" target="_blank">
                                <i class="fa-solid fa-print"></i>Download blank waiver
                            </a>
                        </div>
                    <?php elseif ($credit['status'] === 'posted'): ?>
                        <p class="fee-amount fee-amount--credit">&#8369;<?= number_format((float) $credit['amount'], 2) ?></p>
                        <p class="fee-hint">Signed waiver received. Add the credit to the wallet as GenCoin.</p>
                        <div class="fee-actions">
                            <button type="button" class="parent-btn parent-btn--forest" id="fwcClaimOpen" data-amount="<?= (float) $credit['amount'] ?>">
                                <i class="fa-solid fa-wallet"></i>Add to <?= htmlspecialchars($firstName) ?>'s wallet
                            </button>
                            <?php if ($credit['waiver_file']): ?>
                            <a class="fee-link" href="<?= ADMIN_URL ?>/doc.php?f=<?= urlencode($credit['waiver_file']) ?>"
                               onclick="return gjcViewWaiver(this.href);">
                                <i class="fa-solid fa-file-lines"></i>View signed waiver
                            </a>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($credit['status'] === 'claimed'): ?>
                        <p class="fee-amount fee-amount--credit">&#8369;<?= number_format((float) $credit['amount'], 2) ?></p>
                        <p class="fee-hint">
                            Added to the wallet<?php if (!empty($credit['claimed_at'])): ?>
                            on <?= htmlspecialchars(date('M j, Y', strtotime((string) $credit['claimed_at']))) ?><?php endif; ?>.
                        </p>
                        <?php if ($credit['waiver_file']): ?>
                        <div class="fee-actions">
                            <a class="fee-link" href="<?= ADMIN_URL ?>/doc.php?f=<?= urlencode($credit['waiver_file']) ?>"
                               onclick="return gjcViewWaiver(this.href);">
                                <i class="fa-solid fa-file-lines"></i>View signed waiver
                            </a>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="fee-hint">No fee waiver credit has been requested for this student yet.</p>
                    <?php endif; ?>
                </div>

            </section>

            <nav class="ledger-filters" aria-label="Filter transactions">
                <?php foreach ($tabs as $key => $label): ?>
                <a href="?uid=<?= $targetUid ?>&type=<?= $key ?>" class="filter-tab <?= $filterType === $key ? 'active' : '' ?>"<?= $filterType === $key ? ' aria-current="page"' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <?php if (empty($txnsWithBalance)): ?>
            <div class="ledger-list">
                <div class="empty-state">
                    <i class="fa-solid fa-receipt" style="font-size:36px;margin-bottom:10px;"></i>
                    <p>No transactions found<?= $filterType !== 'all' ? ' for this filter' : '' ?>.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="ledger-list">
                <div class="table-responsive">
                    <table class="txn-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th data-card="title">Description</th>
                                <th class="num" data-card="amount">Amount</th>
                                <th class="num">Balance</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($txnsWithBalance as $t):
                                $isCredit = $t['transaction_type'] === 'cash_in';
                                $meta = $typeLabels[$t['transaction_type']] ?? ['label' => ucfirst($t['transaction_type']), 'icon' => 'fa-circle', 'color' => '#5b6b61', 'bg' => '#eef1ef'];
                            ?>
                            <tr class="txn-row">
                                <td class="txn-date"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($t['created_at']))) ?></td>
                                <td>
                                    <span class="type-chip" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>">
                                        <i class="fa-solid <?= $meta['icon'] ?>"></i>
                                        <?= $meta['label'] ?>
                                    </span>
                                </td>
                                <td class="txn-desc" title="<?= htmlspecialchars($t['notes'] ?? $t['reference_no']) ?>">
                                    <?= htmlspecialchars($t['notes'] ?? $t['reference_no']) ?>
                                </td>
                                <td class="txn-amount <?= $isCredit ? 'credit' : 'debit' ?>">
                                    <?= $isCredit ? '+' : '−' ?>&#8369;<?= number_format((float)$t['amount'], 2) ?>
                                </td>
                                <td class="txn-balance">
                                    <span class="txn-bal-lead">Balance </span>&#8369;<?= number_format($t['running_balance'], 2) ?>
                                </td>
                                <td class="text-end">
                                    <!-- Whole row opens this on phones; signed token, checked in view_transaction.php. -->
                                    <a class="view-btn"
                                        href="<?= PARENT_URL ?>/view_transaction.php?token=<?= urlencode(gjc_make_view_token((int) $t['id'], 'parent_txn')) ?>">
                                        <i class="fa-regular fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<!-- Signed Waiver Viewer (inline, no new tab/window) -->
<div class="modal fade" id="gjcWaiverModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:16px;border:none;overflow:hidden">
            <div class="modal-header border-0" style="padding:16px 20px">
                <h5 class="modal-title fw-bold" style="font-size:15px">
                    <i class="fa-solid fa-file-lines me-2"></i>Signed Waiver
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:0">
                <iframe id="gjcWaiverFrame" src="" style="width:100%;height:70vh;border:0;display:block"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- Fee Waiver Credit claim confirmation -->
<div class="modal fade" id="fwcClaimModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" style="font-size:15px">Add to <?= htmlspecialchars($firstName) ?>'s Wallet</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2">
                <p style="font-size:13px;color:var(--gp-muted);margin-bottom:16px;">
                    This Fee Waiver Credit will be added to <?= htmlspecialchars($studentName) ?>'s GenPay wallet as GenCoin.
                </p>
                <div style="text-align:center;margin-bottom:16px;">
                    <strong id="fwcClaimAmount" style="font-size:22px;color:var(--gp-success);">--</strong>
                </div>
                <div id="fwcClaimError" style="display:none;color:var(--gp-danger);font-size:13px;margin-bottom:12px;"></div>
                <p style="font-size:12px;color:var(--gp-muted);text-align:center;margin:0 0 16px;">
                    This is final and cannot be undone.
                </p>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="parent-btn parent-btn--forest" id="fwcClaimConfirm">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script src="<?= JS_URL ?>/gjc_table_cards.js?v=2"></script>
<script>
function toggleParentSidebar() {
    document.getElementById('parentSidebar').classList.toggle('collapsed');
}

// Show the signed waiver inline in a modal instead of opening a new tab/window.
function gjcViewWaiver(url) {
    document.getElementById('gjcWaiverFrame').src = url;
    new bootstrap.Modal(document.getElementById('gjcWaiverModal')).show();
    return false;
}
document.getElementById('gjcWaiverModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('gjcWaiverFrame').src = '';
});
</script>

<script>
/* ── Fee Waiver Credit claim ──────────────────────────────── */
(function() {
    const openBtn = document.getElementById('fwcClaimOpen');
    if (!openBtn) return;

    const CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
    const STUDENT_UID = <?= (int) $targetUid ?>;
    const modalEl = document.getElementById('fwcClaimModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const amountEl = document.getElementById('fwcClaimAmount');
    const errorEl = document.getElementById('fwcClaimError');
    const confirmBtn = document.getElementById('fwcClaimConfirm');

    function fmt(n) {
        return '₱' + (+n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    openBtn.addEventListener('click', () => {
        amountEl.textContent = fmt(openBtn.dataset.amount || 0);
        errorEl.style.display = 'none';
        errorEl.textContent = '';
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Confirm';
        modal.show();
    });

    confirmBtn.addEventListener('click', async () => {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Adding…';
        errorEl.style.display = 'none';

        try {
            const res = await fetch('<?= PARENT_URL ?>/api/fee_waiver_claim.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'claim', student_user_id: STUDENT_UID, csrf_token: CSRF }),
            });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                errorEl.textContent = data.error || 'Could not add the credit to the wallet.';
                errorEl.style.display = 'block';
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Confirm';
            }
        } catch (e) {
            errorEl.textContent = 'Network error. Please try again.';
            errorEl.style.display = 'block';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm';
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
