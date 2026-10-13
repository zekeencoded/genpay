<?php
require_once __DIR__ . '/connection/config.php';
require_once __DIR__ . '/connection/pdo.php';
require_once __DIR__ . '/connection/app.php';
require_once __DIR__ . '/connection/audit_logger.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


if (!isset($_SESSION['force_change'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit();
}

gjc_ensure_first_login_schema($db);
gjc_require_role(['student', 'merchant', 'finance', 'parent']);

$error = "";
$success = "";


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $new = $_POST['new_pass'] ?? '';
    $confirm = $_POST['confirm_pass'] ?? '';

    if (!gjc_csrf_verify()) {
        $error = "Your session expired. Refresh the page and try again.";
    } elseif ($new !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($new) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif (strlen($new) > 72) {
        $error = "Password must be no more than 72 bytes.";
    } else {
        $userId = gjc_user_id();
        if (!$userId) {
            header('Location: ' . BASE_URL . '/login.php');
            exit();
        }

        $hash = password_hash($new, PASSWORD_BCRYPT);
        $update = $db->prepare(
            "UPDATE users
                SET password = ?,
                    force_password_change = 0,
                    is_first_login = 0,
                    password_changed = 1
              WHERE userID = ? AND SHA2(password, 256) = ?"
        );
        $update->execute([$hash, $userId, $_SESSION['password_fingerprint']]);
        if ($update->rowCount() !== 1) {
            gjc_clear_remember_token($db);
            session_unset();
            session_destroy();
            header('Location: ' . BASE_URL . '/login.php?reason=password_reset');
            exit;
        }
        $_SESSION['password_fingerprint'] = hash('sha256', $hash);

        logAudit(
            $db,
            $userId,
            gjc_current_role(),
            'PASSWORD_CHANGE',
            'users',
            ['force_password_change' => 1, 'is_first_login' => 1, 'password_changed' => 0],
            ['force_password_change' => 0, 'is_first_login' => 0, 'password_changed' => 1],
            null,
            ['target_id' => $userId]
        );

        unset($_SESSION['force_change']);

        $success = "Password updated successfully. Redirecting...";

        header('refresh:2;url=' . BASE_URL . '/dashboard.php');
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>Change Password | GenPay</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    
    <link rel="stylesheet" href="<?= CSS_URL ?>/change_password.css?v=21">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">

</head>

<body>

    <div class="wrapper">

        <div class="card-box">

            <div class="badge-top">Security Update Required</div>

            <div class="title">Set Your New Password</div>

            <div class="desc">
                For security reasons, you are required to change your temporary password before
                accessing your account dashboard.
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gjc_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                <div class="input-group-box">
                    <input type="password" name="new_pass" id="new_pass" required placeholder=" ">
                    <label>New Password</label>

                    <button type="button" class="eye" data-target="new_pass" aria-label="Show password">
                        <img src="<?= ICONS_URL ?>/eye.png" alt="">
                    </button>
                </div>

                <div class="input-group-box">
                    <input type="password" name="confirm_pass" id="confirm_pass" required placeholder=" ">
                    <label>Confirm Password</label>

                    <button type="button" class="eye" data-target="confirm_pass" aria-label="Show password">
                        <img src="<?= ICONS_URL ?>/eye.png" alt="">
                    </button>
                </div>

                <button type="submit">Update Password</button>

            </form>

        </div>

    </div>

    <script>
        function togglePass(button) {
            const target = document.getElementById(button.dataset.target);
            if (!target) return;

            const shouldShow = target.type === "password";
            target.type = shouldShow ? "text" : "password";
            button.setAttribute("aria-label", shouldShow ? "Hide password" : "Show password");
            button.classList.toggle("is-visible", shouldShow);
        }

        document.querySelectorAll(".eye").forEach((button) => {
            button.addEventListener("click", () => togglePass(button));
        });
    </script>

</body>

</html>
