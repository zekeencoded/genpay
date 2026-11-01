<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);

$parentUserId = gjc_user_id();
$currentUser  = gjc_current_user($db);

$pStmt = $db->prepare("SELECT id, low_balance_threshold FROM parents WHERE user_id = ?");
$pStmt->execute([$parentUserId]);
$parentRow = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$parentRow) {
    header('Location: ' . PARENT_URL . '/dashboard.php');
    exit;
}
$parentId       = (int) $parentRow['id'];
$alertThreshold = (float) $parentRow['low_balance_threshold'];

$linkedStmt = $db->prepare(
    "SELECT u.userID, u.first_name, u.last_name,
            si.studentID,
            sw.id AS wallet_id, sw.balance, sw.is_frozen, sw.daily_spend_limit
       FROM parent_student_links psl
       JOIN users u ON u.userID = psl.student_user_id
       LEFT JOIN student_info si ON si.userID = u.userID
       LEFT JOIN student_wallets sw ON sw.user_id = u.userID
      WHERE psl.parent_id = ?
      ORDER BY u.last_name, u.first_name"
);
$linkedStmt->execute([$parentId]);
$linkedStudents = $linkedStmt->fetchAll(PDO::FETCH_ASSOC);

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Linked Students | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=27">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_dashboard.css?v=22">
</head>
<body class="gp-theme">
<div class="parent-layout">

    <?php require __DIR__ . '/../includes/partials/sidebar_parent.php'; ?>

    <main class="parent-main">

        <?php
        $topbarTitle = 'Linked Students';
        $topbarSubtitle = "Wallets your children have linked to your account.";
        $topbarSubtitleMobile = true;
        require __DIR__ . '/../includes/partials/topbar_parent.php';
        ?>

        <div class="parent-content parent-content--wide">

            <a href="<?= PARENT_URL ?>/dashboard.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
            </a>

            <div class="parent-card">
                <div class="parent-card-head">
                    <h5><i class="fa-solid fa-user-graduate me-2" style="color:var(--gp-green-700)"></i>Linked Students</h5>
                    <a class="page-action" href="<?= PARENT_URL ?>/link_student.php"><i class="fa-solid fa-user-plus"></i>Link a student</a>
                </div>

                <?php if (!$linkedStudents): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-user-graduate"></i>
                    <p>No students linked yet.<br>Use <strong>Link a student</strong> to connect your child's account.</p>
                </div>
                <?php endif; ?>

                <?php foreach ($linkedStudents as $s):
                    $balLow = (float)$s['balance'] < $alertThreshold && $alertThreshold > 0;
                    $balChipClass = $balLow ? 'low' : 'ok';
                ?>
                <div class="student-row">
                    <div class="student-avatar"><?= strtoupper(substr($s['first_name'], 0, 1)) ?></div>
                    <div class="student-info">
                        <strong><?= htmlspecialchars(trim($s['first_name'] . ' ' . $s['last_name'])) ?></strong>
                        <small><?= htmlspecialchars($s['studentID'] ?? 'N/A') ?></small>
                    </div>
                    <span class="balance-chip <?= $balChipClass ?>">&#8369;<?= number_format((float)$s['balance'], 2) ?></span>
                    <?php if ($s['is_frozen']): ?>
                    <span class="frozen-badge"><i class="fa-solid fa-lock me-1"></i>Frozen</span>
                    <?php endif; ?>
                    <div class="student-actions">
                        <a href="<?= PARENT_URL ?>/student.php?uid=<?= (int)$s['userID'] ?>" class="btn-view"><i class="fa-solid fa-eye me-1"></i>Ledger</a>
                        <a href="<?= PARENT_URL ?>/controls.php?uid=<?= (int)$s['userID'] ?>" class="btn-controls"><i class="fa-solid fa-sliders me-1"></i>Controls</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>

    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
<script>
const ALERTS_API   = '<?= PARENT_URL ?>/api/alerts.php';
const CONTROLS_API = '<?= PARENT_URL ?>/api/controls.php';
const LINK_API     = '<?= PARENT_URL ?>/api/link.php';
</script>
<script src="<?= JS_URL ?>/parent_dashboard.js?v=1"></script>

<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
