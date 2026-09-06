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

// Get parent record
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

// Student details
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
$credit      = gjc_student_waiver_credit($db, $targetUid);

// Read-only per-year balance snapshots (school_year_balances is never written
// to by the parent portal — finance's rollover is the only writer).
gjc_ensure_school_year_schema($db);
$syBalances = $db->prepare(
    "SELECT sy.school_year_name, syb.starting_balance, syb.final_ending_balance, syb.archived_at
       FROM school_year_balances syb
       JOIN school_years sy ON sy.id = syb.school_year_id
      WHERE syb.student_user_id = ?
      ORDER BY sy.school_year_name DESC"
);
$syBalances->execute([$targetUid]);
$syBalances = $syBalances->fetchAll(PDO::FETCH_ASSOC);

// Transaction type filter
$filterType = trim($_GET['type'] ?? 'all');
$allowedTypes = ['all', 'cash_in', 'payment', 'p2p_transfer', 'allowance'];
if (!in_array($filterType, $allowedTypes, true)) $filterType = 'all';

// Fetch transactions
$txns = [];
if ($walletId && gjc_table_exists($db, 'transactions')) {
    $typeWhere = ($filterType !== 'all') ? "AND transaction_type = '{$filterType}'" : '';
    $tStmt = $db->prepare(
        "SELECT reference_no, transaction_type, amount, notes, created_at, status
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

// Colors match the student side's own transaction-type mapping exactly
// (assets/css/student_dashboard.css .sd-txn--*) so a parent viewing this
// ledger sees the same type-per-color coding their child sees on their
// own dashboard/history pages.
$typeLabels = [
    'cash_in'       => ['label' => 'Top-Up',   'icon' => 'fa-circle-plus',    'color' => '#15803d', 'bg' => '#dcf3e4'],
    'payment'       => ['label' => 'POS',       'icon' => 'fa-store',          'color' => '#b45309', 'bg' => '#fdf1d8'],
    'p2p_transfer'  => ['label' => 'Transfer',  'icon' => 'fa-money-bill-transfer', 'color' => '#2563eb', 'bg' => '#e3edfd'],
    'voucher_payment' => ['label' => 'Voucher', 'icon' => 'fa-ticket',         'color' => '#7c3aed', 'bg' => '#efe7fb'],
    'allowance'     => ['label' => 'Allowance', 'icon' => 'fa-hand-holding-dollar', 'color' => '#15803d', 'bg' => '#dcf3e4'],
];

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
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=21">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_student.css?v=5">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=2">
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

            <!-- Student wallet summary -->
            <div class="ledger-header">
                <h2><?= htmlspecialchars($studentName) ?></h2>
                <small><?= htmlspecialchars($student['studentID'] ?? 'N/A') ?></small>
                <div class="balance-big">&#8369;<?= number_format((float)$student['balance'], 2) ?></div>
                <small>Current Balance</small>
                <div class="status-chips">
                    <?php if (!empty($student['graduated_at'])): ?>
                    <span class="status-chip frozen"><i class="fa-solid fa-graduation-cap me-1"></i>Graduated — Locked (withdraw only)</span>
                    <?php elseif ($student['is_frozen']): ?>
                    <span class="status-chip frozen"><i class="fa-solid fa-lock me-1"></i>Wallet Frozen</span>
                    <?php endif; ?>
                    <?php if ((float)$student['daily_spend_limit'] > 0): ?>
                    <span class="status-chip"><i class="fa-solid fa-gauge me-1"></i>Daily Limit: &#8369;<?= number_format((float)$student['daily_spend_limit'], 2) ?></span>
                    <?php endif; ?>
                    <span class="status-chip"><i class="fa-solid fa-sliders me-1"></i><a href="<?= PARENT_URL ?>/controls.php?uid=<?= $targetUid ?>" style="color:inherit;text-decoration:none;">Manage Controls</a></span>
                </div>
            </div>

            <div class="parent-grid">

                <!-- Fee Waiver Credit — school-managed, not GenCoin. GenPay doesn't
                     track tuition fee itself; this credit is applied against it
                     by the finance office. -->
                <div class="parent-card<?= $syBalances ? '' : ' parent-grid-full' ?>">
                    <div class="parent-card-head">
                        <h5><i class="fa-solid fa-hand-holding-dollar me-2" style="color:var(--gp-green-700)"></i>Fee Waiver Credit</h5>
                        <?php if ($credit['status'] === 'pending'): ?>
                            <span class="parent-badge parent-badge--warning">Pending</span>
                        <?php elseif ($credit['status'] === 'posted'): ?>
                            <span class="parent-badge parent-badge--success">Posted</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($credit['status'] === 'pending'): ?>
                    <p style="font-size:13px;color:var(--gp-muted);margin-bottom:14px;">
                        A Fee Waiver Credit of &#8369;<?= number_format((float) $credit['amount'], 2) ?> is awaiting
                        the signed waiver. Download the blank form, have it signed, and return it to finance to post the credit.
                    </p>
                    <p style="margin-bottom:14px;">
                        <a href="<?= ADMIN_URL ?>/print_fee_waiver.php?student_user_id=<?= $targetUid ?>" target="_blank"
                           style="color:var(--gp-green-700);font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-print"></i>Download Blank Waiver
                        </a>
                    </p>
                    <?php elseif ($credit['status'] === 'posted'): ?>
                    <div style="margin-bottom:14px;">
                        <small style="display:block;color:var(--gp-muted);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;">Amount</small>
                        <strong style="font-size:18px;color:var(--gp-success);">&#8369;<?= number_format((float) $credit['amount'], 2) ?></strong>
                    </div>
                    <?php else: ?>
                    <p style="font-size:13px;color:var(--gp-muted);margin:0;">
                        No Fee Waiver Credit has been requested for this student yet.
                    </p>
                    <?php endif; ?>

                    <?php if ($credit['status'] === 'posted' && $credit['waiver_file']): ?>
                    <p style="margin:0;">
                        <a href="<?= ADMIN_URL ?>/doc.php?f=<?= urlencode($credit['waiver_file']) ?>"
                           onclick="return gjcViewWaiver(this.href);"
                           style="color:var(--gp-green-700);font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-file-lines"></i>View Signed Waiver
                        </a>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- School Year Snapshots — read-only, finance's rollover is the only writer -->
                <?php if ($syBalances): ?>
                <div class="parent-card">
                    <div class="parent-card-head">
                        <h5><i class="fa-solid fa-graduation-cap me-2" style="color:var(--gp-green-700)"></i>School Year Balances</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="txn-table">
                            <thead>
                                <tr>
                                    <th>School Year</th>
                                    <th>Starting Balance</th>
                                    <th>Ending Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($syBalances as $syb): ?>
                                <tr>
                                    <td><?= htmlspecialchars($syb['school_year_name']) ?></td>
                                    <td>&#8369;<?= number_format((float) $syb['starting_balance'], 2) ?></td>
                                    <td>
                                        <?= $syb['final_ending_balance'] !== null
                                            ? '&#8369;' . number_format((float) $syb['final_ending_balance'], 2)
                                            : '<span style="color:var(--gp-muted);">Year still open</span>' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Filter tabs -->
            <?php
            $tabs = [
                'all'          => 'All Transactions',
                'cash_in'      => 'Top-Ups',
                'payment'      => 'POS Purchases',
                'p2p_transfer' => 'Transfers',
                'allowance'    => 'Allowance',
            ];
            ?>
            <div class="filter-tabs">
                <?php foreach ($tabs as $key => $label): ?>
                <a href="?uid=<?= $targetUid ?>&type=<?= $key ?>" class="filter-tab <?= $filterType === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars($label) ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Transaction table -->
            <?php if (empty($txnsWithBalance)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-receipt" style="font-size:36px;margin-bottom:10px;"></i>
                <p>No transactions found<?= $filterType !== 'all' ? ' for this filter' : '' ?>.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="txn-table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th data-card="hide">Type</th>
                            <th data-card="title">Description</th>
                            <th class="num" data-card="amount">Amount</th>
                            <th class="num" data-card="hide">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($txnsWithBalance as $t):
                            $isCredit = $t['transaction_type'] === 'cash_in';
                            $meta = $typeLabels[$t['transaction_type']] ?? ['label' => ucfirst($t['transaction_type']), 'icon' => 'fa-circle', 'color' => '#5b6b61', 'bg' => '#eef1ef'];
                        ?>
                        <tr class="txn-row">
                            <td><?= htmlspecialchars(date('M j, Y', strtotime($t['created_at']))) ?><br>
                                <small class="txn-time"><?= htmlspecialchars(date('g:i A', strtotime($t['created_at']))) ?></small>
                            </td>
                            <td>
                                <span class="type-chip" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>">
                                    <i class="fa-solid <?= $meta['icon'] ?>"></i>
                                    <?= $meta['label'] ?>
                                </span>
                            </td>
                            <td class="txn-desc">
                                <?= htmlspecialchars($t['notes'] ?? $t['reference_no']) ?>
                            </td>
                            <td class="txn-amount <?= $isCredit ? 'credit' : 'debit' ?>">
                                <?= $isCredit ? '+' : '−' ?>&#8369;<?= number_format((float)$t['amount'], 2) ?>
                            </td>
                            <td class="txn-balance">
                                &#8369;<?= number_format($t['running_balance'], 2) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>
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

<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
