<?php
require_once __DIR__ . '/connection/config.php';
require_once __DIR__ . '/connection/pdo.php';
require_once __DIR__ . '/connection/app.php';
require_once __DIR__ . '/connection/audit_logger.php';

$logoutUserId = gjc_user_id();
$logoutRole = gjc_current_role();
if ($logoutUserId > 0) {
    logAudit(
        $db,
        $logoutUserId,
        $logoutRole,
        'LOGOUT',
        'users',
        ['session' => 'active'],
        ['session' => 'destroyed'],
        null,
        ['target_id' => $logoutUserId]
    );
}

// Logging out also revokes the remember-me token, otherwise the next visit
// would silently sign the user straight back in.
gjc_clear_remember_token($db);

$_SESSION = [];
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>Logging Out | GenPay</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/logout.css?v=5">
</head>
<body>
    <script>
        Swal.fire({
            icon: 'success',
            title: 'Logged Out Successfully',
            text: 'Redirecting to home page...',
            timer: 1500,
            showConfirmButton: false,
            allowOutsideClick: false,
            background: '#ffffff',
            color: '#052916',
            iconColor: '#117039'
        }).then(() => {
            window.location.href = 'index.php';
        });
    </script>
</body>
</html>
