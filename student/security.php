<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';

gjc_require_role(['student']);
gjc_enforce_graduate_lock($db);

$currentUser = gjc_current_user($db);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $columns = gjc_table_columns($db, 'users');
    $idColumn = gjc_column($db, 'users', ['id', 'userID']);

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $storedPassword = (string) ($currentUser['raw']['password'] ?? '');

    $validCurrentPassword = $storedPassword !== ''
        && (password_verify($currentPassword, $storedPassword) || hash_equals($storedPassword, $currentPassword));

    if (!gjc_csrf_verify()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif (!$idColumn || !in_array('password', $columns, true)) {
        $error = 'Password cannot be updated because the password column was not found.';
    } elseif (!$validCurrentPassword) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match.';
    } else {
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE {$idColumn} = ?");
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $currentUser['id']]);
        logAudit(
            $db,
            (int) $currentUser['id'],
            gjc_current_role(),
            'PASSWORD_CHANGE',
            'users',
            ['password' => 'changed_by_student_profile'],
            ['password' => 'changed_by_student_profile']
        );
        header('Location: ' . STUDENT_URL . '/profile.php?password_updated=1');
        exit;
    }
}

$rawUser = $currentUser['raw'] ?? [];
$studentName = $currentUser['name'];
$profileImg = (string) ($rawUser['profile_img'] ?? '');
$profilePhotoUrl = ($profileImg !== '') ? (BASE_URL . '/' . ltrim($profileImg, '/')) : '';

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'profile';
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
    <title>Change Password | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=30">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_profile.css?v=13">
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_student.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'Change Password';
            $topbarSubtitle = 'Update your login password for better account security.';
            $topbarAvatarPhotoUrl = $profilePhotoUrl;
            require __DIR__ . '/../includes/partials/topbar_student.php';
            ?>

            <div class="sd-content">

                <a href="<?= STUDENT_URL ?>/profile.php" class="pf-back-link">
                    <i class="fa-solid fa-arrow-left"></i> Back to Profile
                </a>

                <?php if ($error): ?>
                <div class="pf-alert is-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= $e($error) ?>
                </div>
                <?php endif; ?>

                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>Change Password</h3>
                            <p>Update your login password for better account security.</p>
                        </div>
                    </div>

                    <form method="POST" class="pf-form">
                        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

                        <div class="pf-field">
                            <label>Current Password</label>
                            <input type="password" name="current_password" autocomplete="current-password" required>
                        </div>

                        <div class="pf-form-grid">
                            <div class="pf-field">
                                <label>New Password</label>
                                <input type="password" name="new_password" minlength="6" autocomplete="new-password" required>
                            </div>

                            <div class="pf-field">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" minlength="6" autocomplete="new-password" required>
                            </div>
                        </div>

                        <button type="submit" class="pf-btn">
                            <i class="fa-solid fa-shield-halved me-1"></i> Update Password
                        </button>
                    </form>
                </section>

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_student.php'; ?>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>

</body>

</html>
