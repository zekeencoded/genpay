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

// Filters — the wallet selector is re-verified against the linked-wallet set
// below, never trusted as-is (a parent could otherwise probe any wallet id).
// 'me' includes every transaction on the parent's wallet, including allowances.
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
    // Whose wallet the money moved on — the parent's own, or a linked child's.
    // In the parent's wallet filter, allowances are outgoing. In the combined
    // trail they are attributed to the student recipient and shown only once.
    $r['is_own'] = ((int) $r['parent_wallet_id'] > 0
        && (int) $r['parent_wallet_id'] === $parentWalletId
        && ($showOwnOnly || (int) $r['student_wallet_id'] === 0));
    if ($r['is_own'] && $type === 'allowance') {
        $r['incoming'] = false;
    }
    // Anything not the parent's own now always has a student wallet, so an
    // unknown id means a student who has since been unlinked — not "no wallet".
    $r['student_name'] = $r['is_own']
        ? 'You (Parent)'
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
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=24">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_wallet.css?v=9">
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

            <div class="parent-card">
                <form method="GET" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
                    <div style="min-width:180px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:var(--gp-muted);margin-bottom:6px;">Wallet</label>
                        <select name="student" style="width:100%;height:42px;border:1.5px solid var(--ad-line);border-radius:10px;padding:0 12px;font-size:14px;">
                            <option value="0" <?= $walletFilter === '0' ? 'selected' : '' ?>>All Wallets</option>
                            <?php if ($parentWalletId > 0): ?>
                            <option value="me" <?= $showOwnOnly ? 'selected' : '' ?>>My Transactions (Parent)</option>
                            <?php endif; ?>
                            <?php foreach ($linkedStudents as $s): ?>
                            <?php if ((int) $s['wallet_id'] <= 0) continue; ?>
                            <option value="<?= (int) $s['wallet_id'] ?>" <?= $selectedStudent === (int) $s['wallet_id'] ? 'selected' : '' ?>>
                                <?= $e(trim($s['first_name'] . ' ' . $s['last_name'])) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="min-width:160px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:var(--gp-muted);margin-bottom:6px;">Type</label>
                        <select name="type" style="width:100%;height:42px;border:1.5px solid var(--ad-line);border-radius:10px;padding:0 12px;font-size:14px;">
                            <?php foreach ($typeOptions as $val => $label): ?>
                            <option value="<?= $e($val) ?>" <?= $filterType === $val ? 'selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="min-width:150px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:var(--gp-muted);margin-bottom:6px;">From</label>
                        <input type="date" name="from" value="<?= $e($dateFrom) ?>" style="width:100%;height:42px;border:1.5px solid var(--ad-line);border-radius:10px;padding:0 12px;font-size:14px;">
                    </div>
                    <div style="min-width:150px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:var(--gp-muted);margin-bottom:6px;">To</label>
                        <input type="date" name="to" value="<?= $e($dateTo) ?>" style="width:100%;height:42px;border:1.5px solid var(--ad-line);border-radius:10px;padding:0 12px;font-size:14px;">
                    </div>
                    <button type="submit" class="parent-btn parent-btn--forest" style="height:42px;">Filter</button>
                    <a href="<?= PARENT_URL ?>/activity.php" class="parent-btn" style="height:42px;">Reset</a>
                </form>
            </div>

            <div class="parent-card">
                <div class="parent-card-head">
                    <h5><i class="fa-solid fa-list-check me-2" style="color:var(--gp-green-700)"></i>Transactions</h5>
                    <span style="font-size:12px;color:var(--gp-muted);">
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
                    <table class="table align-middle js-datatable parent-table" id="activityTable" data-page-length="15">
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th data-card="title">Reference</th>
                                <th>Wallet</th>
                                <th data-card="hide">Type</th>
                                <th data-card="hide">Items</th>
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
                                    <span class="pa-you-badge">You</span> Parent
                                    <?php else: ?>
                                    <?= $e($r['student_name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= $e($r['label']) ?></td>
                                <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;color:var(--gp-muted);" title="<?= $e($r['items_summary']) ?>">
                                    <?= $r['items_summary'] !== '' ? $e($r['items_summary']) : '—' ?>
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
<script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>
<script>
function toggleParentSidebar() {
    document.getElementById('parentSidebar').classList.toggle('collapsed');
}
</script>

<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
