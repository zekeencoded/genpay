<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/parent_activity_filters.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);
gjc_ensure_parent_wallet_schema($db);

$parentUserId = gjc_user_id();
$currentUser  = gjc_current_user($db);

$parentId       = gjc_parent_id_for_user($db, $parentUserId);
$parentWallet   = gjc_parent_wallet($db, $parentId);
$parentWalletId = (int) $parentWallet['id'];

$linkedStmt = $db->prepare(
    "SELECT u.first_name, u.last_name, sw.id AS wallet_id
       FROM parent_student_links psl
       JOIN users u ON u.userID = psl.student_user_id
       LEFT JOIN student_wallets sw ON sw.user_id = u.userID
      WHERE psl.parent_id = ?
      ORDER BY u.last_name, u.first_name"
);
$linkedStmt->execute([$parentId]);
$linkedStudents = $linkedStmt->fetchAll(PDO::FETCH_ASSOC);

// Same type list as the activity page, so the values round-trip.
$typeOptions = gjc_transaction_type_options() + ['p2p_transfer' => 'Transfer', 'student_withdraw' => 'Withdrawal'];
unset($typeOptions['encashment']);

// Pre-fill with what the activity page is showing.
$walletFilter = trim((string) ($_GET['student'] ?? '0'));
$filterType   = trim((string) ($_GET['type'] ?? ''));
$dateFrom     = gjc_parent_activity_date(trim((string) ($_GET['from'] ?? '')));
$dateTo       = gjc_parent_activity_date(trim((string) ($_GET['to'] ?? '')));

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'activity';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filter Activity | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=27">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_wallet.css?v=18">
</head>
<body class="gp-theme">
<div class="parent-layout">

    <?php require __DIR__ . '/../includes/partials/sidebar_parent.php'; ?>

    <main class="parent-main">

        <?php
        $topbarTitle = 'Filter Activity';
        $topbarSubtitle = 'Choose a wallet, type or date range.';
        $topbarSubtitleMobile = true;
        require __DIR__ . '/../includes/partials/topbar_parent.php';
        ?>

        <div class="parent-content parent-content--wide">

            <a href="<?= PARENT_URL ?>/activity.php?<?= $e(http_build_query([
                    'student' => $walletFilter,
                    'type'    => $filterType,
                    'from'    => $dateFrom,
                    'to'      => $dateTo,
                ])) ?>" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Activity
            </a>

            <form method="GET" action="<?= PARENT_URL ?>/activity.php" class="parent-card pa-sheet">
                <div class="pa-field">
                    <label for="paWallet">Wallet</label>
                    <select id="paWallet" name="student">
                        <option value="0" <?= $walletFilter === '0' ? 'selected' : '' ?>>All wallets</option>
                        <?php if ($parentWalletId > 0): ?>
                        <option value="me" <?= $walletFilter === 'me' ? 'selected' : '' ?>>My transactions (parent)</option>
                        <?php endif; ?>
                        <?php foreach ($linkedStudents as $s): ?>
                        <?php if ((int) $s['wallet_id'] <= 0) continue; ?>
                        <option value="<?= (int) $s['wallet_id'] ?>" <?= $walletFilter === (string) (int) $s['wallet_id'] ? 'selected' : '' ?>>
                            <?= $e(trim($s['first_name'] . ' ' . $s['last_name'])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pa-field">
                    <label for="paType">Type</label>
                    <select id="paType" name="type">
                        <option value="" <?= $filterType === '' ? 'selected' : '' ?>>All types</option>
                        <?php foreach ($typeOptions as $val => $label): ?>
                        <option value="<?= $e($val) ?>" <?= $filterType === $val ? 'selected' : '' ?>><?= $e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pa-field-pair">
                    <div class="pa-field">
                        <label for="paFrom">From</label>
                        <input type="date" id="paFrom" name="from" value="<?= $e($dateFrom) ?>">
                    </div>
                    <div class="pa-field">
                        <label for="paTo">To</label>
                        <input type="date" id="paTo" name="to" value="<?= $e($dateTo) ?>">
                    </div>
                </div>

                <div class="pa-sheet-actions">
                    <a href="<?= PARENT_URL ?>/activity.php" class="parent-btn">Clear all</a>
                    <button type="submit" class="parent-btn parent-btn--forest">Show results</button>
                </div>
            </form>

        </div>

    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script>
function toggleParentSidebar() {
    document.getElementById('parentSidebar').classList.toggle('collapsed');
}
</script>

<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
