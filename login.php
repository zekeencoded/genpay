<?php
session_start();
require_once __DIR__ . "/connection/config.php";

$error = "";

require_once __DIR__ . "/connection/pdo.php";
require_once __DIR__ . "/connection/app.php";
require_once __DIR__ . "/record.php";

use Classes\Record;

// A visitor with a valid remember-me cookie skips the form entirely.
gjc_attempt_remember_login($db);
if (gjc_user_id() > 0 && empty($_SESSION['force_change'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$Record = new Record($db);
$message = $Record->loginUser();
$error = $message ?: $error;

if (!$error && ($_GET['reason'] ?? '') === 'password_reset') {
    $error = 'Your password changed. Sign in with your current password or the temporary password provided by the finance office.';
}

if (!$error && ($_GET['reason'] ?? '') === 'deactivated') {
    $error = 'Your account has been deactivated. Please contact your merchant admin.';
}

if (!$error && ($_GET['reason'] ?? '') === 'suspended') {
    $untilTs = strtotime((string) ($_GET['until'] ?? ''));
    $error = 'Your merchant account is temporarily suspended'
        . ($untilTs ? ' until ' . date('M d, Y g:i A', $untilTs) : '')
        . ' due to repeated attempts to list restricted products.';
}

// Suspended mid-session: show when access returns.
if (!$error && ($_GET['reason'] ?? '') === 'account_suspended') {
    $untilTs = strtotime((string) ($_GET['until'] ?? ''));
    $error = 'Your account has been suspended by the GenPay finance office'
        . ($untilTs ? ' until ' . date('M d, Y g:i A', $untilTs) : '')
        . '. Sign in for details, or contact the finance office.';
}

// Finance-issued ban (admin/users.php). Permanent, so unlike the suspension
// banner above there is no date to offer and no "until" to read.
if (!$error && ($_GET['reason'] ?? '') === 'account_banned') {
    $error = 'Your account has been permanently banned from GenPay. '
        . 'Sign in for details, or contact the finance office if you believe this is a mistake.';
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
    <title>Sign in | GenPay</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800&family=Oswald:wght@600;700&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet">

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

            <h1>Sign in</h1>
            <p class="signin-lede">Use your email or student ID.</p>

                <?php if ($error): ?>
                    <div class="signin-error" role="alert"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST" class="signin-form">

                    <div class="signin-field">
                        <label for="signin-email">Email or Student ID</label>
                        <input id="signin-email" type="text" name="email" required autocomplete="username"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>

                    <div class="signin-field">
                        <label for="signin-pass">Password</label>
                        <div class="signin-pass">
                            <input id="signin-pass" type="password" name="password" required autocomplete="current-password">
                            <button type="button" class="signin-eye" onclick="togglePass()" aria-label="Show password" aria-controls="signin-pass">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                    <path class="signin-eye-slash" d="M3 3l18 18"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <label class="signin-remember">
                        <input type="checkbox" name="remember" value="1" <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                        Remember me
                    </label>

                    <button class="signin-submit" name="login">Sign in</button>

                </form>

            <p class="signin-help">Forgot your password? Bring your ID to the Finance Office to request a reset.</p>

    </main>

    </div>

    <script>
        function togglePass() {
            const password = document.getElementById("signin-pass");
            const button = document.querySelector(".signin-eye");
            const shouldShow = password.type === "password";

            password.type = shouldShow ? "text" : "password";
            button.setAttribute("aria-label", shouldShow ? "Hide password" : "Show password");
            button.setAttribute("aria-pressed", String(shouldShow));
            button.classList.toggle("is-visible", shouldShow);
        }
    </script>

</body>

</html>
