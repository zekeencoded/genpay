<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/parent_activity_filters.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);
gjc_ensure_parent_wallet_schema($db);

$parentUserId    = gjc_user_id();
$currentUser     = gjc_current_user($db);
$profileImg      = (string) ($currentUser['raw']['profile_img'] ?? '');
$profilePhotoUrl = ($profileImg !== '') ? (BASE_URL . '/' . ltrim($profileImg, '/')) : '';

$parentId       = gjc_parent_id_for_user($db, $parentUserId);
$parentWallet   = gjc_parent_wallet($db, $parentId);
$parentWalletId = (int) $parentWallet['id'];

$linkedStmt = $db->prepare(
    "SELECT u.userID, u.first_name, u.last_name, sw.id AS wallet_id
       FROM parent_student_links psl
       JOIN users u ON u.userID = psl.student_user_id
       LEFT JOIN student_wallets sw ON sw.user_id = u.userID
      WHERE psl.parent_id = ?
      ORDER BY u.last_name, u.first_name"
);
$linkedStmt->execute([$parentId]);
$linkedStudents = $linkedStmt->fetchAll(PDO::FETCH_ASSOC);

$studentByWallet = [];
$walletIds = [];
foreach ($linkedStudents as $s) {
    if ((int) $s['wallet_id'] > 0) {
        $walletIds[] = (int) $s['wallet_id'];
        $studentByWallet[(int) $s['wallet_id']] = trim($s['first_name'] . ' ' . $s['last_name']);
    }
}

// Filters; the wallet filter is re-verified below. 'me' = the parent's own wallet.
$walletFilter    = trim((string) ($_GET['student'] ?? '0'));
$showOwnOnly     = ($walletFilter === 'me' && $parentWalletId > 0);
$selectedStudent = $showOwnOnly ? 0 : (int) $walletFilter;
if ($selectedStudent > 0 && !in_array($selectedStudent, $walletIds, true)) {
    $selectedStudent = 0;
}
// Normalised back so the <select> below can mark the right option.
$walletFilter = $showOwnOnly ? 'me' : (string) $selectedStudent;
$typeOptions = gjc_transaction_type_options() + ['p2p_transfer' => 'Transfer', 'student_withdraw' => 'Withdrawal'];
unset($typeOptions['encashment']);
$filterType = trim((string) ($_GET['type'] ?? ''));
foreach (gjc_parent_activity_types() as $category => $ledgerTypes) {
    if (in_array($filterType, $ledgerTypes, true)) {
        $filterType = $category;
        break;
    }
}
if (!array_key_exists($filterType, $typeOptions)) {
    $filterType = '';
}
$dateFrom = gjc_parent_activity_date(trim((string) ($_GET['from'] ?? '')));
$dateTo = gjc_parent_activity_date(trim((string) ($_GET['to'] ?? '')));
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$rows = [];
if (($walletIds || $parentWalletId) && gjc_table_exists($db, 'transactions')) {
    [$where, $params] = gjc_parent_activity_where(
        $walletIds, $parentWalletId, $walletFilter, $filterType, $dateFrom, $dateTo
    );

    // Receipt items come from the official relational records.
    $stmt = $db->prepare(
        "SELECT t.id, t.reference_no, t.transaction_type, t.student_wallet_id, t.merchant_wallet_id,
                t.parent_wallet_id, t.initiated_by, t.amount, t.notes, t.status, t.created_at
           FROM transactions t
           {$where}
          ORDER BY t.created_at DESC, t.id DESC
          LIMIT 300"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($rows as &$r) {
    $type = (string) $r['transaction_type'];
    $meta = gjc_student_txn_meta($type);
    $r['label'] = $type === 'top_up' ? 'Top-up' : ($type === 'p2p_transfer' ? 'Transfer' : ($type === 'student_withdraw' ? 'Withdrawal' : $meta['label']));
    $r['incoming'] = $meta['incoming'];
    // Own wallet or a linked child's.
    $r['is_own'] = ((int) $r['parent_wallet_id'] > 0
        && (int) $r['parent_wallet_id'] === $parentWalletId
        && ($showOwnOnly || (int) $r['student_wallet_id'] === 0));
    if ($r['is_own'] && $type === 'allowance') {
        $r['incoming'] = false;
    }
    // Anything not the parent's own now always has a student wallet, so an
    // unknown id means a student who has since been unlinked — not "no wallet".
    $r['student_name'] = $r['is_own']
        ? 'You (Guardian)'
        : ($studentByWallet[(int) $r['student_wallet_id']] ?? 'Unlinked student');

    $itemsSummary = '';
    if ($type === 'payment') {
        $decoded = gjc_transaction_line_items($db, (string) $r['reference_no']);
        if (is_array($decoded)) {
            $itemsSummary = implode(', ', array_map(
                static fn(array $i): string => (int) ($i['qty'] ?? 1) . '× ' . (string) ($i['name'] ?? 'Item'),
                $decoded
            ));
        }
    }
    $r['items_summary'] = $itemsSummary;
}
unset($r);

// Split shown in the panel head, so the mix of the two is visible at a glance
// before anyone reaches for the wallet filter.
$ownCount     = count(array_filter($rows, static fn(array $r): bool => $r['is_own']));
$studentCount = count($rows) - $ownCount;

// What the filter sheet has applied, in words, for the line under the Filters button.
$activeFilters = (int) ($walletFilter !== '0') + (int) ($filterType !== '')
    + (int) ($dateFrom !== '') + (int) ($dateTo !== '');
$filterSummary = [];
if ($showOwnOnly) {
    $filterSummary[] = 'My transactions';
} elseif ($selectedStudent > 0) {
    $filterSummary[] = $studentByWallet[$selectedStudent] ?? '';
}
if ($filterType !== '') {
    $filterSummary[] = $typeOptions[$filterType];
}
if ($dateFrom !== '' || $dateTo !== '') {
    $filterSummary[] = ($dateFrom !== '' ? date('M j, Y', strtotime($dateFrom)) : 'Start')
        . ' to ' . ($dateTo !== '' ? date('M j, Y', strtotime($dateTo)) : 'today');
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'activity';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Trail | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=27">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_wallet.css?v=18">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
</head>
<body class="gp-theme">
<div class="parent-layout">

    <?php require __DIR__ . '/../includes/partials/sidebar_parent.php'; ?>

    <main class="parent-main">

        <?php
        $topbarTitle = 'Activity Trail';
        $topbarSubtitle = 'Your wallet transactions and activity across your linked students.';
        require __DIR__ . '/../includes/partials/topbar_parent.php';
        ?>

        <div class="parent-content parent-content--wide">

            <?php if (empty($linkedStudents) && empty($rows)): ?>
            <div class="parent-card">
                <div class="parent-empty">
                    <i class="fa-solid fa-user-graduate"></i>
                    No students linked yet. Link a student from your <a href="<?= PARENT_URL ?>/dashboard.php">Dashboard</a> first.
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters open on their own page; this line only says what is applied. -->
            <div class="pa-bar">
                <a href="<?= PARENT_URL ?>/activity_filter.php?<?= $e(http_build_query([
                        'student' => $walletFilter,
                        'type'    => $filterType,
                        'from'    => $dateFrom,
                        'to'      => $dateTo,
                    ])) ?>" class="pa-filter-btn">
                    <i class="fa-solid fa-sliders"></i> Filters
                </a>
                <?php if ($activeFilters): ?>
                <a href="<?= PARENT_URL ?>/activity.php" class="pa-filter-reset">Clear</a>
                <p class="pa-summary"><?= $e(implode(', ', array_filter($filterSummary))) ?></p>
                <?php endif; ?>
            </div>

            <div class="parent-card">
                <div class="parent-card-head">
                    <h5><i class="fa-solid fa-list-check me-2" style="color:var(--gp-green-700)"></i>Transactions</h5>
                    <span class="pa-count">
                        <?= count($rows) ?> records (max 300)<?php if ($ownCount && $studentCount): ?>
                        &middot; <?= $ownCount ?> yours, <?= $studentCount ?> your students'<?php endif; ?>
                    </span>
                </div>
                <?php if (empty($rows)): ?>
                <div class="parent-empty">
                    <i class="fa-solid fa-receipt"></i>
                    No transactions found for this filter.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle js-datatable parent-table" id="activityTable" data-page-length="15" data-hide-filter="true">
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th data-card="hide">Reference</th>
                                <th>Wallet</th>
                                <th>Type</th>
                                <th data-card="title">Items</th>
                                <th data-card="amount">Amount</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                            <tr class="<?= $r['is_own'] ? 'pa-own-row' : '' ?>">
                                <td><?= $e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
                                <td><?= $e($r['reference_no']) ?></td>
                                <td class="pa-wallet-cell">
                                    <?php if ($r['is_own']): ?>
                                    <span class="pa-you-badge">You</span> Guardian
                                    <?php else: ?>
                                    <?= $e($r['student_name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= $e($r['label']) ?></td>
                                <td class="pa-items" title="<?= $e($r['items_summary']) ?>">
                                    <?= $e($r['items_summary'] !== '' ? $r['items_summary'] : $r['label']) ?>
                                </td>
                                <td style="color:<?= $r['incoming'] ? 'var(--gp-success)' : 'var(--gp-ink)' ?>;font-weight:700;">
                                    <?= $r['incoming'] ? '+' : '−' ?>&#8369;<?= number_format((float) $r['amount'], 2) ?>
                                </td>
                                <td><?= $e(gjc_transaction_status_label((string) $r['status'])) ?></td>
                                <td class="text-end">
                                    <!-- Read view: signed HMAC token, re-checked in
                                         view_transaction.php against this parent's scope. -->
                                    <a class="view-btn"
                                        href="<?= PARENT_URL ?>/view_transaction.php?token=<?= urlencode(gjc_make_view_token((int) $r['id'], 'parent_txn')) ?>">
                                        <i class="fa-regular fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

        </div>

    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
<script src="<?= JS_URL ?>/gjc_table_cards.js?v=2"></script>
<script>
function toggleParentSidebar() {
    document.getElementById('parentSidebar').classList.toggle('collapsed');
}

/* Number the pager with as many page buttons as fit between Prev and Next. */
function fitPager() {
    const ul = document.querySelector('#activityTable_wrapper .dataTables_paginate ul.pagination');
    if (!ul) return;
    ul.querySelectorAll('.ellipsis').forEach(li => { li.style.display = 'none'; });
    const pages = Array.from(ul.querySelectorAll('.page-item:not(.previous):not(.next):not(.ellipsis)'));
    pages.forEach(li => { li.style.display = 'none'; });
    const current = pages.findIndex(li => li.classList.contains('active'));
    if (current < 0) return;
    pages[current].style.display = '';
    // Grow outward from the current page until the row would overflow.
    for (let d = 1; d < pages.length; d++) {
        for (const i of [current - d, current + d]) {
            if (i < 0 || i >= pages.length) continue;
            pages[i].style.display = '';
            if (ul.scrollWidth > ul.clientWidth) {
                pages[i].style.display = 'none';
                return;
            }
        }
    }
}

jQuery(function ($) {
    $('#activityTable').on('draw.dt', fitPager);
    window.addEventListener('resize', fitPager);
    fitPager();
});
</script>

<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
