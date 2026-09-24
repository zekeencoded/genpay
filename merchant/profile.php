<?php
// ============================================================
//  merchant/profile.php
//  My Profile for merchant STAFF — the same hub the student portal uses
//  (student/profile.php): hero with photo, status, personal details, a
//  Settings menu into profile_edit.php / security.php, FAQs, log out.
//  Merchant admins have the Business Profile (settings.php) instead.
// ============================================================
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['merchant']);
if (!gjc_is_merchant_staff()) {
    header('Location: ' . MERCHANT_URL . '/settings.php');
    exit;
}
gjc_ensure_user_profile_schema($db);
gjc_ensure_staff_position_schema($db);

$currentUser = gjc_current_user($db);
$rawUser = $currentUser['raw'] ?? [];
$userId = (int) $currentUser['id'];

$notice = '';
if (($_GET['updated'] ?? '') === '1') {
    $notice = 'Profile updated successfully!';
} elseif (($_GET['password_updated'] ?? '') === '1') {
    $notice = 'Password updated successfully!';
}

$staffName = $currentUser['name'];
$staffInitial = strtoupper(substr($staffName, 0, 1));
$email = (string) ($currentUser['email'] ?? '');
$staffID = 'STF-' . str_pad((string) $userId, 5, '0', STR_PAD_LEFT);
$position = trim((string) ($rawUser['position'] ?? '')) ?: 'Merchant Staff';
$createdAt = (string) ($rawUser['created_at'] ?? '');
$memberSince = $createdAt !== '' ? date('F Y', strtotime($createdAt)) : 'N/A';
$profileImg = (string) ($rawUser['profile_img'] ?? '');
$profilePhotoUrl = ($profileImg !== '') ? (BASE_URL . '/' . ltrim($profileImg, '/')) : '';
$isActive = (string) ($rawUser['status'] ?? 'Active') === 'Active';

// The stall this account sells for, and who runs it.
$ownerId = gjc_merchant_owner_id($db, $userId);
$stall = null;
if ($ownerId > 0 && $ownerId !== $userId) {
    $stmt = $db->prepare(
        "SELECT m.stall_name, m.stall_id, m.operational_status, s.label AS stall_label,
                u.first_name, u.last_name, u.email, u.contact_number
           FROM users u
           LEFT JOIN merchant m ON m.userID = u.userID
           LEFT JOIN stalls   s ON s.stall_id = m.stall_id
          WHERE u.userID = ?
          LIMIT 1"
    );
    $stmt->execute([$ownerId]);
    $stall = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$stallName = trim((string) ($stall['stall_name'] ?? '')) ?: 'No stall linked';
$stallOp = (string) ($stall['operational_status'] ?? 'active');
$stallOpen = $stall && $stallOp === 'active';
$ownerName = $stall ? trim(($stall['first_name'] ?? '') . ' ' . ($stall['last_name'] ?? '')) : '';

/** users.contact_number is a BIGINT, so a leading 0 comes back eaten. */
$phoneLabel = static function ($value): string {
    $digits = preg_replace('/\D+/', '', (string) $value);
    if ($digits === '' || $digits === '0') {
        return '';
    }
    return (strlen($digits) === 10 && $digits[0] === '9') ? '0' . $digits : $digits;
};
$ownerContact = $stall ? ($phoneLabel($stall['contact_number'] ?? '') ?: (string) ($stall['email'] ?? '')) : '';

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'profile';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_profile.css?v=15">
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_merchant_staff.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'My Profile';
            $topbarSubtitle = 'Manage your staff account details, status, and password security.';
            $topbarAvatarPhotoUrl = $profilePhotoUrl;
            require __DIR__ . '/../includes/partials/topbar_merchant.php';
            ?>

            <div class="sd-content">

                <?php if ($notice): ?>
                <div class="pf-alert">
                    <i class="fa-solid fa-circle-check"></i>
                    <?= $e($notice) ?>
                </div>
                <?php endif; ?>

                <!-- Hero -->
                <section class="pf-hero">
                    <div class="pf-hero-left">
                        <div class="pf-avatar-wrap">
                            <div class="pf-avatar" id="avatarCircle">
                                <?php if ($profilePhotoUrl): ?>
                                    <img id="avatarImg" src="<?= $e($profilePhotoUrl) ?>" alt="Profile Photo">
                                <?php else: ?>
                                    <span id="avatarInitial"><?= $e($staffInitial) ?></span>
                                <?php endif; ?>
                            </div>
                            <label for="photoInput" class="pf-avatar-edit" title="Change photo">
                                <i class="fa-solid fa-camera"></i>
                            </label>
                            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
                        </div>

                        <div class="pf-identity">
                            <span>Staff Account</span>
                            <h2><?= $e($staffName) ?></h2>
                            <p><?= $e($email) ?> &middot; <?= $e($staffID) ?></p>
                            <span class="sd-role-badge"><?= $e(strtoupper($position)) ?></span>
                            <br>
                            <a href="<?= MERCHANT_URL ?>/profile_edit.php" class="pf-edit-btn">
                                <i class="fa-solid fa-pen"></i> Edit Profile
                            </a>
                            <div class="pf-photo-msg" id="photoMsg"></div>
                        </div>
                    </div>

                    <div class="pf-wallet-box">
                        <span>Works At</span>
                        <h3><?= $e($stallName) ?></h3>
                        <p><?= $stall && $stall['stall_id'] ? 'Stall ' . $e($stall['stall_id']) . ' &middot; ' : '' ?>Member since <?= $e($memberSince) ?></p>
                    </div>
                </section>

                <div class="pf-cols">
                <div class="pf-col">

                <!-- Personal details (editable from Edit Profile) -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>Personal Details</h3>
                            <p>Your details on record.</p>
                        </div>
                    </div>

                    <div class="pf-status-list">
                        <?php
                        $sexOnFile = trim((string) ($rawUser['sex'] ?? ''));
                        $addressOnFile = gjc_format_address($rawUser);
                        $dobOnFile = $rawUser['date_of_birth'] ?? null;
                        $dobAge = gjc_age_from_dob($dobOnFile);
                        $phoneOnFile = $phoneLabel($rawUser['contact_number'] ?? '');
                        foreach ([
                            'Contact Number' => $phoneOnFile !== '' ? $phoneOnFile : 'Not set',
                            'Sex'            => $sexOnFile !== '' ? $sexOnFile : 'Not set',
                            'Age'            => $dobAge !== null ? gjc_age_label($dobOnFile) : 'Not set',
                            'Date of Birth'  => $dobAge !== null ? gjc_dob_label($dobOnFile) : 'Not set',
                            'Address'        => $addressOnFile !== '' ? $addressOnFile : 'Not set',
                        ] as $pdLabel => $pdValue): ?>
                        <div>
                            <span><?= $e($pdLabel) ?></span>
                            <strong class="pf-detail-value <?= $pdValue === 'Not set' ? 'is-empty' : '' ?>"><?= $e($pdValue) ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="pf-note">
                        Keep these up to date from <a href="<?= MERCHANT_URL ?>/profile_edit.php">Edit Profile</a>.
                        Your age is worked out from your date of birth, so it is never entered by hand.
                    </div>
                </section>

                <!-- Account status (read-only — managed by the merchant admin) -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>Account Status</h3>
                            <p>Current access and stall settings.</p>
                        </div>
                    </div>

                    <div class="pf-status-list">
                        <div>
                            <span>Account Status</span>
                            <strong class="pf-pill <?= $isActive ? 'green' : 'red' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></strong>
                        </div>
                        <div>
                            <span>POS Access</span>
                            <strong class="pf-pill <?= $isActive && $stallOpen ? 'green' : 'gold' ?>">
                                <?= $isActive && $stallOpen ? 'Enabled' : 'Paused' ?>
                            </strong>
                        </div>
                        <div>
                            <span>Position</span>
                            <strong class="pf-detail-value"><?= $e($position) ?></strong>
                        </div>
                        <div>
                            <span>Stall Status</span>
                            <strong class="pf-pill <?= $stallOpen ? 'green' : ($stall ? 'gold' : 'gray') ?>">
                                <?= $stall ? $e(ucwords(str_replace('_', ' ', $stallOp))) : 'Not linked' ?>
                            </strong>
                        </div>
                        <div>
                            <span>Merchant Admin</span>
                            <strong class="pf-detail-value <?= $ownerName === '' ? 'is-empty' : '' ?>"><?= $e($ownerName !== '' ? $ownerName : 'Not set') ?></strong>
                        </div>
                        <div>
                            <span>Admin Contact</span>
                            <strong class="pf-detail-value <?= $ownerContact === '' ? 'is-empty' : '' ?>"><?= $e($ownerContact !== '' ? $ownerContact : 'Not set') ?></strong>
                        </div>
                    </div>

                    <div class="pf-note">
                        Your position and account status are managed by your merchant admin.
                    </div>
                </section>

                </div><!-- /.pf-col -->
                <div class="pf-col">

                <!-- Settings menu -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>Settings</h3>
                            <p>Manage your account and security.</p>
                        </div>
                    </div>

                    <div class="pf-menu">
                        <a href="<?= MERCHANT_URL ?>/profile_edit.php" class="pf-menu-item">
                            <span class="pf-menu-item-icon"><i class="fa-solid fa-user-pen"></i></span>
                            <span class="pf-menu-item-text">
                                <strong>Edit Profile</strong>
                                <span>Update your name and contact number</span>
                            </span>
                            <i class="fa-solid fa-chevron-right pf-menu-item-chevron"></i>
                        </a>

                        <a href="<?= MERCHANT_URL ?>/security.php" class="pf-menu-item">
                            <span class="pf-menu-item-icon"><i class="fa-solid fa-shield-halved"></i></span>
                            <span class="pf-menu-item-text">
                                <strong>Change Password</strong>
                                <span>Update your login password</span>
                            </span>
                            <i class="fa-solid fa-chevron-right pf-menu-item-chevron"></i>
                        </a>
                    </div>
                </section>

                <!-- FAQs -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>FAQs</h3>
                            <p>Quick answers to common questions.</p>
                        </div>
                    </div>

                    <div class="pf-faq accordion" id="pfFaqAccordion">
                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq1">
                                    What can I do with a staff account?
                                </button>
                            </h2>
                            <div id="pfFaq1" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    You can ring up sales on the <strong>POS</strong> and update stock on <strong>Inventory</strong> for your stall. Encashment, rent, staff and the business profile are handled by your merchant admin.
                                </div>
                            </div>
                        </div>

                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq2">
                                    What is GenCoin (GC)?
                                </button>
                            </h2>
                            <div id="pfFaq2" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    GenCoin is GenPay's in-app currency, fixed at &#8369;10 = 1 GC. Students pay with it at your stall, and every sale goes straight to your stall's wallet &mdash; never to your staff account.
                                </div>
                            </div>
                        </div>

                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq3">
                                    Why does my POS say it's paused?
                                </button>
                            </h2>
                            <div id="pfFaq3" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    Either your account was set to inactive by your merchant admin, or the stall itself is suspended by the finance office. Check <strong>Account Status</strong> above and ask your merchant admin.
                                </div>
                            </div>
                        </div>

                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq4">
                                    How do I change my position or email?
                                </button>
                            </h2>
                            <div id="pfFaq4" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    Only your merchant admin can change those, from their Staff Management page. You can update your own name, contact number and personal details from <strong>Edit Profile</strong>.
                                </div>
                            </div>
                        </div>

                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq5">
                                    I forgot my password. What do I do?
                                </button>
                            </h2>
                            <div id="pfFaq5" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    Ask your merchant admin to reset it. You will be asked to choose a new one the next time you sign in. If you're already logged in and just want to change it, use <strong>Change Password</strong> in Settings above.
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                </div><!-- /.pf-col -->
                </div><!-- /.pf-cols -->

                <button type="button" class="pf-btn pf-btn--block is-danger" onclick="openLogoutModal(event);">
                    <i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Log Out
                </button>

                <div class="pf-version">GenPay v<?= $e(GJC_APP_VERSION) ?></div>

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>

    <script>
    /* ── Profile photo upload (same endpoint as the student profile) ── */
    const PHOTO_API = '<?= BASE_URL ?>/api/profile_photo.php';

    document.getElementById('photoInput').addEventListener('change', async function() {
        const file = this.files[0];
        if (!file) return;

        const msg = document.getElementById('photoMsg');
        msg.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';
        msg.style.color = 'rgba(255,255,255,.7)';

        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('photo', file);

        try {
            const res  = await fetch(PHOTO_API, {method: 'POST', body: fd, credentials: 'same-origin'});
            const data = await res.json();
            if (data.success) {
                const circle = document.getElementById('avatarCircle');
                let img = document.getElementById('avatarImg');
                const initial = document.getElementById('avatarInitial');
                if (initial) initial.remove();
                if (!img) {
                    img = document.createElement('img');
                    img.id = 'avatarImg';
                    img.alt = 'Profile Photo';
                    circle.appendChild(img);
                }
                img.src = data.photo_url;

                const topbar = document.getElementById('topbarAvatar');
                if (topbar) {
                    topbar.style.overflow = 'hidden';
                    let tImg = document.getElementById('topbarAvatarImg');
                    if (!tImg) {
                        topbar.textContent = '';
                        tImg = document.createElement('img');
                        tImg.id = 'topbarAvatarImg';
                        tImg.alt = '';
                        tImg.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
                        topbar.appendChild(tImg);
                    }
                    tImg.src = data.photo_url;
                }

                msg.innerHTML = '<i class="fa-solid fa-check"></i> Photo updated.';
                msg.style.color = '#4ade80';
                setTimeout(() => { msg.innerHTML = ''; }, 3000);
            } else {
                msg.textContent = data.error || 'Upload failed.';
                msg.style.color = '#fca5a5';
            }
        } catch(err) {
            msg.innerHTML = 'Network error. Please try again.';
            msg.style.color = '#fca5a5';
        }
        this.value = '';
    });
    </script>

</body>

</html>
