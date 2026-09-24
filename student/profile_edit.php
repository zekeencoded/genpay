<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['student']);
gjc_enforce_student_lock($db);
gjc_ensure_user_profile_schema($db);

$currentUser = gjc_current_user($db);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $columns = gjc_table_columns($db, 'users');
    $idColumn = gjc_column($db, 'users', ['id', 'userID']);

    if (!gjc_csrf_verify()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif (!$idColumn) {
        $error = 'Profile cannot be updated because the users table ID column was not found.';
    } else {
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));

        // Sex / birth date / address / emergency contact, validated once in
        // app.php so this page and the parent portal can't disagree on what a
        // valid birth date is.
        $details = gjc_collect_profile_details($_POST, $columns);

        if ($firstName === '' || $lastName === '') {
            $error = 'First name and last name are required.';
        } elseif ($details['error'] !== null) {
            $error = $details['error'];
        } else {
            $updates = [];
            $values = [];

            foreach ([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => trim($firstName . ' ' . $lastName),
                'phone' => $phone,
            ] + $details['values'] as $column => $value) {
                if (in_array($column, $columns, true)) {
                    $updates[] = "{$column} = ?";
                    $values[] = $value;
                }
            }

            if ($updates) {
                $values[] = $currentUser['id'];
                $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $updates) . " WHERE {$idColumn} = ?");
                $stmt->execute($values);
                header('Location: ' . STUDENT_URL . '/profile.php?updated=1');
                exit;
            }
        }
    }

    $currentUser = gjc_current_user($db);
}

$rawUser = $currentUser['raw'] ?? [];
$firstName = (string) ($rawUser['first_name'] ?? '');
$lastName = (string) ($rawUser['last_name'] ?? '');

if ($firstName === '' && $lastName === '') {
    $nameParts = preg_split('/\s+/', trim($currentUser['name']), 2);
    $firstName = $nameParts[0] ?? '';
    $lastName = $nameParts[1] ?? '';
}

$studentName = $currentUser['name'];
$email = (string) ($currentUser['email'] ?? '');
$phone = (string) ($rawUser['phone'] ?? '');
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

        <?php require __DIR__ . '/../includes/partials/sidebar_student.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'Edit Profile';
            $topbarSubtitle = 'Update your personal account information.';
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
                            <h3>Edit Profile</h3>
                            <p>Update your personal account information.</p>
                        </div>
                    </div>

                    <form method="POST" class="pf-form">
                        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

                        <div class="pf-form-grid">
                            <div class="pf-field">
                                <label>First Name</label>
                                <input type="text" name="first_name" value="<?= $e($firstName) ?>" required>
                            </div>

                            <div class="pf-field">
                                <label>Last Name</label>
                                <input type="text" name="last_name" value="<?= $e($lastName) ?>" required>
                            </div>
                        </div>

                        <div class="pf-field">
                            <label>Phone Number</label>
                            <input type="text" name="phone" value="<?= $e($phone) ?>">
                        </div>

                        <div class="pf-field">
                            <label>Email Address</label>
                            <input type="email" value="<?= $e($email) ?>" disabled>
                            <small>Email cannot be changed. Contact Admin if needed.</small>
                        </div>

                        <?php
                        $pdRow   = $rawUser;
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

    <?php require __DIR__ . '/../includes/partials/bottom_nav_student.php'; ?>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>

</body>

</html>
