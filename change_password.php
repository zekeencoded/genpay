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
    } elseif (strlen($new) < 8) {
        $error = "Password must be at least 8 characters.";
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
                    force_password_change = 0
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
            ['force_password_change' => 1],
            ['force_password_change' => 0],
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | GenPay</title>

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800&family=Oswald:wght@600;700&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Same layout as the sign-in page, which this screen follows. -->
    <link rel="stylesheet" href="<?= CSS_URL ?>/public_theme.css?v=1">
    <link rel="stylesheet" href="<?= CSS_URL ?>/login.css?v=33">

</head>

<body class="landing signin-body">

    <div class="signin-split">

        <aside class="signin-brand">
            <a href="<?= BASE_URL ?>/" class="signin-seal"><img src="<?= ICONS_URL ?>/gp_logo_clear.png" alt="GenPay home" width="160" height="160"></a>
            <p class="signin-brand-name">GenPay</p>
            <p class="signin-brand-line">Cashless Payment System of Gen. De Jesus College</p>
        </aside>

        <main class="signin">

            <p class="signin-badge">Security Update Required</p>

            <h1>Set Your New Password</h1>
            <p class="signin-lede">
                For security reasons, you are required to change your temporary password before
                accessing your account dashboard.
            </p>

            <?php if ($error): ?>
            <div class="signin-error" role="alert"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="signin-success" role="status"><?php echo $success; ?></div>
            <?php endif; ?>

            <form method="POST" class="signin-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gjc_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                <?php foreach (['new_pass' => 'New Password', 'confirm_pass' => 'Confirm Password'] as $id => $label): ?>
                <div class="signin-field">
                    <label for="<?= $id ?>"><?= $label ?></label>
                    <div class="signin-pass">
                        <input type="password" name="<?= $id ?>" id="<?= $id ?>" required autocomplete="new-password">
                        <button type="button" class="signin-eye" data-target="<?= $id ?>" aria-label="Show password" aria-controls="<?= $id ?>">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                                <path class="signin-eye-slash" d="M3 3l18 18"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>

                <button type="submit" class="signin-submit">Update Password</button>

            </form>

        </main>

    </div>

    <script>
        document.querySelectorAll(".signin-eye").forEach((button) => {
            button.addEventListener("click", () => {
                const target = document.getElementById(button.dataset.target);
                if (!target) return;

                const shouldShow = target.type === "password";
                target.type = shouldShow ? "text" : "password";
                button.setAttribute("aria-label", shouldShow ? "Hide password" : "Show password");
                button.setAttribute("aria-pressed", String(shouldShow));
                button.classList.toggle("is-visible", shouldShow);
            });
        });
    </script>

</body>

</html>
