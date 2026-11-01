<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);

$parentUserId = gjc_user_id();
$currentUser  = gjc_current_user($db);

$pStmt = $db->prepare("SELECT id FROM parents WHERE user_id = ?");
$pStmt->execute([$parentUserId]);
$parentRow = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$parentRow) {
    header('Location: ' . PARENT_URL . '/dashboard.php');
    exit;
}
$parentId = (int) $parentRow['id'];

// Link requests the student has not answered yet
$pendingStmt = $db->prepare(
    "SELECT u.first_name, u.last_name
       FROM parent_link_requests r
       JOIN users u ON u.userID = r.student_user_id
      WHERE r.parent_id = ?
      ORDER BY r.requested_at"
);
$pendingStmt->execute([$parentId]);
$pendingLinks = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link a Student | GenPay</title>
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
        $topbarTitle = 'Link a Student';
        $topbarSubtitle = "Connect your child's wallet with their school ID.";
        $topbarSubtitleMobile = true;
        require __DIR__ . '/../includes/partials/topbar_parent.php';
        ?>

        <div class="parent-content parent-content--wide link-page-wrap">

            <a href="<?= PARENT_URL ?>/students.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Linked Students
            </a>

            <form class="parent-card link-page" id="linkForm" data-next="<?= PARENT_URL ?>/students.php">
                <div class="link-page-icon"><i class="fa-solid fa-id-card"></i></div>

                <label class="link-page-label" for="linkSchoolId">Student school ID</label>
                <input class="link-page-input" type="text" id="linkSchoolId" name="school_id"
                       placeholder="GJC2026-0001" maxlength="30" autocomplete="off" autocapitalize="characters">
                <p class="link-page-hint">Their school-issued ID. They approve the link from their GenPay profile.</p>

                <button type="submit" class="link-page-submit">Find student</button>
                <div id="linkMsg" class="link-page-msg" aria-live="polite"></div>
            </form>

            <?php if ($pendingLinks): ?>
            <section class="parent-card link-page-pending">
                <h6>Pending requests</h6>
                <?php foreach ($pendingLinks as $pl):
                    $pName = trim($pl['first_name'] . ' ' . $pl['last_name']);
                ?>
                <div class="link-page-row">
                    <div class="student-avatar"><?= $e(strtoupper(substr($pl['first_name'], 0, 1))) ?></div>
                    <div class="student-info">
                        <strong><?= $e($pName) ?></strong>
                        <small>Waiting for them to approve</small>
                    </div>
                    <span class="link-page-status">Pending</span>
                </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

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
