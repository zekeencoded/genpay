<?php
// merchant/profile_edit.php — Edit Profile for merchant STAFF. Same page as
// student/profile_edit.php; position/status/email stay with the merchant
// admin (staff.php → Manage).
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';

gjc_require_role(['merchant']);
if (!gjc_is_merchant_staff()) {
    header('Location: ' . MERCHANT_URL . '/settings.php');
    exit;
}
gjc_ensure_user_profile_schema($db);

$currentUser = gjc_current_user($db);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $columns = gjc_table_columns($db, 'users');

    if (!gjc_csrf_verify()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } else {
        $firstName  = trim((string) ($_POST['first_name'] ?? ''));
        $middleName = trim((string) ($_POST['middle_name'] ?? ''));
        $lastName   = trim((string) ($_POST['last_name'] ?? ''));
        $suffix     = trim((string) ($_POST['suffix'] ?? ''));
        $phone      = preg_replace('/\D+/', '', (string) ($_POST['contact_number'] ?? ''));

        $details = gjc_collect_profile_details($_POST, $columns);

        if ($firstName === '' || $lastName === '') {
            $error = 'First name and last name are required.';
        } elseif (mb_strlen($firstName) > 60 || mb_strlen($middleName) > 60 || mb_strlen($lastName) > 60) {
            $error = 'Name fields must not exceed 60 characters.';
        } elseif (mb_strlen($suffix) > 20) {
            $error = 'Suffix must not exceed 20 characters.';
        } elseif ($phone !== '' && !preg_match('/^0?9\d{9}$/', $phone)) {
            $error = 'Enter an 11-digit mobile number, like 09171234567.';
        } elseif ($details['error'] !== null) {
            $error = $details['error'];
        } else {
            $values = [
                'first_name'     => $firstName,
                'middle_name'    => $middleName !== '' ? $middleName : null,
                'last_name'      => $lastName,
                'suffix'         => $suffix !== '' ? $suffix : null,
                'contact_number' => $phone !== '' ? $phone : '0',
            ] + $details['values'];
            $values = array_intersect_key($values, array_flip($columns));
            $auditValues = $values + array_intersect_key($details['values'], array_flip([
                'address_barangay', 'address_city', 'address_province',
            ]));

            $set = implode(', ', array_map(static fn($c) => "{$c} = ?", array_keys($values)));
            try {
                gjc_save_profile_with_address(
                    $db, (int) $currentUser['id'],
                    "UPDATE users SET {$set} WHERE userID = ?",
                    [...array_values($values), (int) $currentUser['id']], $details['values']
                );
            } catch (Throwable $e) {
                error_log('merchant/profile_edit.php: ' . $e->getMessage());
                $error = 'Profile could not be saved. Please try again.';
            }

            if ($error === '') {
                logAudit(
                    $db,
                    (int) $currentUser['id'],
                    gjc_current_role(),
                    'USER_ACCOUNT',
                    'users',
                    array_intersect_key($currentUser['raw'] ?? [], $auditValues),
                    ['event' => 'profile_updated', 'changed_via' => 'merchant_staff_profile'] + $auditValues,
                    null,
                    ['target_id' => (int) $currentUser['id']]
                );
    
                header('Location: ' . MERCHANT_URL . '/profile.php?updated=1');
                exit;
            }
        }
    }
}

$rawUser = $currentUser['raw'] ?? [];
// Re-show what was typed after a validation error, not the stored value.
$field = static fn(string $key): string => (string) ($_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST[$key] ?? '')
    : ($rawUser[$key] ?? ''));

$phoneDigits = preg_replace('/\D+/', '', $field('contact_number'));
if ($phoneDigits === '0') {
    $phoneDigits = '';
} elseif (strlen($phoneDigits) === 10 && $phoneDigits[0] === '9') {
    $phoneDigits = '0' . $phoneDigits;
}

$email = (string) ($currentUser['email'] ?? '');
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
    <title>Edit Profile | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_profile.css?v=14">
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_merchant_staff.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'Edit Profile';
            $topbarSubtitle = 'Update your personal account information.';
            $topbarAvatarPhotoUrl = $profilePhotoUrl;
            require __DIR__ . '/../includes/partials/topbar_merchant.php';
            ?>

            <div class="sd-content">

                <a href="<?= MERCHANT_URL ?>/profile.php" class="pf-back-link">
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
                            <h3>Edit Profile</h3>
                            <p>Update your personal account information.</p>
                        </div>
                    </div>

                    <form method="POST" class="pf-form">
                        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

                        <div class="pf-form-grid">
                            <div class="pf-field">
                                <label>First Name</label>
                                <input type="text" name="first_name" value="<?= $e($field('first_name')) ?>" maxlength="60" autocomplete="given-name" required>
                            </div>

                            <div class="pf-field">
                                <label>Middle Name</label>
                                <input type="text" name="middle_name" value="<?= $e($field('middle_name')) ?>" maxlength="60" autocomplete="additional-name">
                            </div>
                        </div>

                        <div class="pf-form-grid">
                            <div class="pf-field">
                                <label>Last Name</label>
                                <input type="text" name="last_name" value="<?= $e($field('last_name')) ?>" maxlength="60" autocomplete="family-name" required>
                            </div>

                            <div class="pf-field">
                                <label>Suffix</label>
                                <input type="text" name="suffix" value="<?= $e($field('suffix')) ?>" maxlength="20" placeholder="Jr., Sr., III" autocomplete="honorific-suffix">
                            </div>
                        </div>

                        <div class="pf-field">
                            <label>Contact Number</label>
                            <input type="tel" name="contact_number" value="<?= $e($phoneDigits) ?>" maxlength="13" placeholder="09XXXXXXXXX" inputmode="numeric" autocomplete="tel">
                        </div>

                        <div class="pf-form-grid">
                            <div class="pf-field">
                                <label>Email Address</label>
                                <input type="email" value="<?= $e($email) ?>" disabled>
                                <small>Email cannot be changed. Ask your merchant admin.</small>
                            </div>

                            <div class="pf-field">
                                <label>Position</label>
                                <input type="text" value="<?= $e(trim((string) ($rawUser['position'] ?? '')) ?: 'Merchant Staff') ?>" disabled>
                                <small>Set by your merchant admin.</small>
                            </div>
                        </div>

                        <?php
                        $pdRow   = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_merge($rawUser, $_POST) : $rawUser;
                        $pdField = 'pf-field';
                        $pdGrid  = 'pf-form-grid';
                        require __DIR__ . '/../includes/partials/profile_details_fields.php';
                        ?>

                        <button type="submit" class="pf-btn">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                        </button>
                    </form>
                </section>

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>

</body>

</html>
