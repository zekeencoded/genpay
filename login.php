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

// Finance-issued suspension (admin/users.php). The reason text lives on the
// account and is only shown once the user identifies themselves at login —
// this banner just says the session ended and when access returns.
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
    <title>Login | GenPay</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="<?= CSS_URL ?>/login.css?v=24">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
</head>

<body>

    <div class="login-wrapper">
        <div class="login-card">

            <div class="badge-top">Secure Campus Wallet Access</div>

            <div class="text-center mb-3">
                <img src="<?= ICONS_URL ?>/GenDeJesusFavicon.png" alt="GJC Seal"
                    style="width: 150px; height: 150px; object-fit: contain;">
            </div>

            <h1 class="brand-title">GenPay</h1>
            <p class="sub-text">Cashless Payment System</p>

            <?php if ($error): ?>
                <div class="error-box"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST">

                <div class="input-group-box">
                    <input type="text" name="email" required placeholder=" " value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <label>Email or Student ID</label>
                </div>

                <div class="input-group-box">
                    <input type="password" name="password" id="pass" required placeholder=" ">
                    <label>Password</label>

                    <button type="button" class="eye" onclick="togglePass()" aria-label="Show password">
                        <img src="<?= ICONS_URL ?>/eye.png" alt="">
                    </button>
                </div>

                <div class="options">
                    <label><input type="checkbox" name="remember" value="1" <?= !empty($_POST['remember']) ? 'checked' : '' ?>> Remember me</label>

                    <a href="#" data-bs-toggle="modal" data-bs-target="#forgotModal">
                        Forgot Password?
                    </a>
                </div>

                <button class="login-btn" name="login">SIGN IN</button>

            </form>

            <div class="signup-text">
                Doesn’t have an account yet?<br>

                <a href="#" data-bs-toggle="modal" data-bs-target="#studentModal">
                    Register as Student
                </a>
            </div>

        </div>
    </div>


    <div class="modal fade" id="studentModal">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content custom-modal">
                <div class="modal-body text-center">

                    <div class="modal-icon">
                        <img src="<?= ICONS_URL ?>/signup.png">
                    </div>

                    <h5 class="modal-title">Student Registration</h5>

                    <p class="modal-desc">
                        Follow these steps to register your account:
                    </p>

                    <ul class="modal-steps text-start">
                        <li>Go to Finance Department</li>
                        <li>Provide your School ID</li>
                        <li>Request account creation</li>
                        <li>Wait for activation</li>
                    </ul>

                    <button class="btn-close-modal" data-bs-dismiss="modal">Got it</button>

                </div>
            </div>
        </div>
    </div>


    <div class="modal fade" id="forgotModal">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content custom-modal">

                <div class="modal-body text-center">

                    <div class="modal-icon">
                        <img src="<?= ICONS_URL ?>/lock.png">
                    </div>

                    <h5 class="modal-title">Forgot Password?</h5>

                    <ul class="modal-steps text-start">
                        <li>Go to Finance Office</li>
                        <li>Bring ID</li>
                        <li>Request reset</li>
                        <li>Wait for verification</li>
                    </ul>

                    <button class="btn-close-modal" data-bs-dismiss="modal">Got it</button>

                </div>

            </div>
        </div>
    </div>

    <script>
        function togglePass() {
            const password = document.getElementById("pass");
            const button = document.querySelector(".eye");
            const shouldShow = password.type === "password";

            password.type = shouldShow ? "text" : "password";
            button.setAttribute("aria-label", shouldShow ? "Hide password" : "Show password");
            button.classList.toggle("is-visible", shouldShow);
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>
