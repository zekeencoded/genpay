<?php
// ============================================================
//  merchant/profile.php
//  My Profile for merchant STAFF — the same profile system as the student
//  portal (student/profile.php) and the owner's Business Profile
//  (settings.php), all styled by profile_hub.css: identity hero with key
//  figures, a completeness nudge, details / merchant admin on the left,
//  status / settings on the right, FAQs in a Help sheet.
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
$posEnabled = $isActive && $stallOpen;
$ownerName = $stall ? trim(($stall['first_name'] ?? '') . ' ' . ($stall['last_name'] ?? '')) : '';

/** users.contact_number is a BIGINT, so a leading 0 comes back eaten. */
$phoneLabel = static function ($value): string {
    $digits = preg_replace('/\D+/', '', (string) $value);
    if ($digits === '' || $digits === '0') {
        return '';
    }
    return (strlen($digits) === 10 && $digits[0] === '9') ? '0' . $digits : $digits;
};
$ownerPhone = $stall ? $phoneLabel($stall['contact_number'] ?? '') : '';
$ownerEmail = $stall ? trim((string) ($stall['email'] ?? '')) : '';

// ── Personal details + completeness ───────────────────────────────────────
$phoneOnFile = $phoneLabel($rawUser['contact_number'] ?? '');
$sexOnFile = trim((string) ($rawUser['sex'] ?? ''));
$addressOnFile = gjc_format_address($rawUser);
$dobOnFile = $rawUser['date_of_birth'] ?? null;
$dobAge = gjc_age_from_dob($dobOnFile);

$editUrl = MERCHANT_URL . '/profile_edit.php';
$profileChecks = [
    ['done' => $profilePhotoUrl !== '', 'label' => 'Add a photo',        'photo' => true],
    ['done' => $phoneOnFile !== '',     'label' => 'Add mobile number'],
    ['done' => $sexOnFile !== '',       'label' => 'Add sex'],
    ['done' => $dobAge !== null,        'label' => 'Add date of birth'],
    ['done' => $addressOnFile !== '',   'label' => 'Add address'],
];
$checksDone = count(array_filter($profileChecks, static fn($c) => $c['done']));
$checksTotal = count($profileChecks);
$checksPct = (int) round($checksDone / $checksTotal * 100);

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
    <link rel="stylesheet" href="<?= CSS_URL ?>/profile_hub.css?v=1">
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_merchant_staff.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'My Profile';
            $topbarSubtitle = 'Your staff account, the stall you work at, and your security.';
            $topbarAvatarPhotoUrl = $profilePhotoUrl;
            require __DIR__ . '/../includes/partials/topbar_merchant.php';
            ?>

            <div class="sd-content ph">

                <?php if ($notice): ?>
                <div class="pf-alert">
                    <i class="fa-solid fa-circle-check"></i>
                    <?= $e($notice) ?>
                </div>
                <?php endif; ?>

                <!-- Hero -->
                <section class="ph-hero" aria-label="Account summary">
                    <div class="ph-hero-top">
                        <div class="ph-avatar" id="avatarCircle">
                            <?php if ($profilePhotoUrl): ?>
                                <img id="avatarImg" src="<?= $e($profilePhotoUrl) ?>" alt="Profile Photo">
                            <?php else: ?>
                                <span id="avatarInitial"><?= $e($staffInitial) ?></span>
                            <?php endif; ?>
                            <label for="photoInput" role="button" tabindex="0" class="ph-avatar-edit" title="Change photo" aria-label="Change photo">
                                <i class="fa-solid fa-camera"></i>
                            </label>
                            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" hidden>
                        </div>

                        <div class="ph-identity">
                            <span class="ph-eyebrow">Staff account</span>
                            <h2 class="ph-name"><?= $e($staffName) ?></h2>
                            <p class="ph-meta"><?= $e($staffID) ?> &middot; <?= $e($email) ?> &middot; Member since <?= $e($memberSince) ?></p>
                            <div class="ph-photo-msg" id="photoMsg"></div>
                        </div>

                        <div class="ph-hero-actions">
                            <a href="<?= $e($editUrl) ?>" class="ph-btn-ghost">
                                <i class="fa-solid fa-pen"></i> Edit profile
                            </a>
                        </div>
                    </div>

                    <div class="ph-stats is-odd">
                        <div class="ph-stat">
                            <span class="ph-stat-k">Works at</span>
                            <span class="ph-stat-v is-text"><?= $e($stallName) ?></span>
                            <span class="ph-stat-n"><?= $stall && $stall['stall_id'] ? 'Stall ' . $e($stall['stall_id']) : 'Ask your merchant admin to link you' ?></span>
                        </div>
                        <div class="ph-stat">
                            <span class="ph-stat-k">Position</span>
                            <span class="ph-stat-v is-text"><?= $e($position) ?></span>
                            <span class="ph-stat-n">Set by your merchant admin</span>
                        </div>
                        <div class="ph-stat">
                            <span class="ph-stat-k">POS access</span>
                            <span class="ph-stat-v"><?= $posEnabled ? 'Enabled' : 'Paused' ?></span>
                            <span class="ph-stat-n"><?= $posEnabled ? 'You can ring up sales' : (!$isActive ? 'Your account is inactive' : 'The stall is not open') ?></span>
                        </div>
                    </div>
                </section>

                <?php if ($checksDone < $checksTotal): ?>
                <section class="ph-card ph-nudge" aria-label="Profile completeness">
                    <div class="ph-ring" style="--ph-pct: <?= $checksPct ?>%"><span><?= $checksPct ?>%</span></div>
                    <div class="ph-grow">
                        <span class="ph-t">Your profile is <?= $checksDone ?> of <?= $checksTotal ?> complete</span>
                        <span class="ph-s">Your merchant admin and the Finance Office use these to reach you.</span>
                    </div>
                    <div class="ph-chips">
                        <?php foreach ($profileChecks as $check): if ($check['done']) continue; ?>
                            <?php if (!empty($check['photo'])): ?>
                                <label for="photoInput" role="button" tabindex="0" class="ph-chip"><i class="fa-solid fa-plus"></i><?= $e($check['label']) ?></label>
                            <?php else: ?>
                                <a href="<?= $e($editUrl) ?>" class="ph-chip"><i class="fa-solid fa-plus"></i><?= $e($check['label']) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <div class="ph-grid">
                <div class="ph-col">

                <!-- Personal details (editable from Edit Profile) -->
                <section class="ph-card">
                    <div class="ph-card-head">
                        <div>
                            <h3>Personal details</h3>
                            <p>Your details on record</p>
                        </div>
                        <a class="ph-link" href="<?= $e($editUrl) ?>"><i class="fa-solid fa-pen"></i>Edit</a>
                    </div>

                    <?php
                    $notSet = static fn(): string => 'Not set <a class="ph-link" href="' . htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') . '">Add</a>';
                    ?>
                    <dl class="ph-dl">
                        <div>
                            <dt>Sex</dt>
                            <dd class="<?= $sexOnFile === '' ? 'is-empty' : '' ?>"><?= $sexOnFile !== '' ? $e($sexOnFile) : $notSet() ?></dd>
                        </div>
                        <div>
                            <dt>Mobile number</dt>
                            <dd class="<?= $phoneOnFile === '' ? 'is-empty' : '' ?>"><?= $phoneOnFile !== '' ? $e($phoneOnFile) : $notSet() ?></dd>
                        </div>
                        <div>
                            <dt>Date of birth</dt>
                            <dd class="<?= $dobAge === null ? 'is-empty' : '' ?>"><?= $dobAge !== null ? $e(gjc_dob_label($dobOnFile)) : $notSet() ?></dd>
                        </div>
                        <div>
                            <dt>Age</dt>
                            <dd class="<?= $dobAge === null ? 'is-empty' : '' ?>"><?= $dobAge !== null ? $e(gjc_age_label($dobOnFile)) : 'Worked out from date of birth' ?></dd>
                        </div>
                        <div class="is-wide">
                            <dt>Address</dt>
                            <dd class="<?= $addressOnFile === '' ? 'is-empty' : '' ?>"><?= $addressOnFile !== '' ? $e($addressOnFile) : $notSet() ?></dd>
                        </div>
                    </dl>
                </section>

                <!-- Merchant admin (the owner this account works for) -->
                <section class="ph-card">
                    <div class="ph-card-head">
                        <div>
                            <h3>Your merchant admin</h3>
                            <p>Manages your position, access and password resets</p>
                        </div>
                    </div>
                    <?php if ($ownerName !== ''): ?>
                        <div class="ph-row">
                            <span class="ph-ini"><?= $e(strtoupper(substr($ownerName, 0, 1))) ?></span>
                            <span class="ph-grow">
                                <span class="ph-t"><?= $e($ownerName) ?></span>
                                <span class="ph-s"><?= $e($stallName) ?></span>
                            </span>
                        </div>
                        <div class="ph-kv">
                            <span>Mobile number</span>
                            <div><?= $ownerPhone !== '' ? '<a class="ph-link" href="tel:' . $e($ownerPhone) . '">' . $e($ownerPhone) . '</a>' : '<span class="is-empty">Not set</span>' ?></div>
                        </div>
                        <div class="ph-kv">
                            <span>Email</span>
                            <div><?= $ownerEmail !== '' ? '<a class="ph-link" href="mailto:' . $e($ownerEmail) . '">' . $e($ownerEmail) . '</a>' : '<span class="is-empty">Not set</span>' ?></div>
                        </div>
                    <?php else: ?>
                        <div class="ph-note">
                            <i class="fa-solid fa-circle-info"></i>
                            <span>This account is not linked to a stall yet.</span>
                        </div>
                    <?php endif; ?>
                </section>

                </div><!-- /.ph-col -->
                <div class="ph-col">

                <!-- Account status (read-only — managed by the merchant admin) -->
                <section class="ph-card">
                    <div class="ph-card-head">
                        <div>
                            <h3>Account status</h3>
                            <p>Your access and the stall's standing</p>
                        </div>
                    </div>
                    <div class="ph-kv">
                        <span>Account</span>
                        <div><span class="ph-pill has-dot <?= $isActive ? 'is-ok' : 'is-bad' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span></div>
                    </div>
                    <div class="ph-kv">
                        <span>POS access</span>
                        <div><span class="ph-pill has-dot <?= $posEnabled ? 'is-ok' : 'is-warn' ?>"><?= $posEnabled ? 'Enabled' : 'Paused' ?></span></div>
                    </div>
                    <div class="ph-kv">
                        <span>Stall status</span>
                        <div><span class="ph-pill has-dot <?= $stallOpen ? 'is-ok' : ($stall ? 'is-warn' : '') ?>"><?= $stall ? $e(ucwords(str_replace('_', ' ', $stallOp))) : 'Not linked' ?></span></div>
                    </div>
                    <div class="ph-note">
                        <i class="fa-solid fa-lock"></i>
                        <span>Your position and account status are managed by your merchant admin.</span>
                    </div>
                </section>

                <!-- Settings -->
                <section class="ph-card">
                    <div class="ph-card-head">
                        <div><h3>Settings</h3></div>
                    </div>
                    <nav class="ph-menu" aria-label="Profile settings">
                        <a href="<?= $e($editUrl) ?>" class="ph-menu-item">
                            <span class="ph-ic"><i class="fa-solid fa-user-pen"></i></span>
                            <span class="ph-grow">
                                <span class="ph-t">Edit profile</span>
                                <span class="ph-s">Name, mobile number, address and personal details</span>
                            </span>
                            <i class="fa-solid fa-chevron-right ph-chev"></i>
                        </a>
                        <a href="<?= MERCHANT_URL ?>/security.php" class="ph-menu-item">
                            <span class="ph-ic"><i class="fa-solid fa-shield-halved"></i></span>
                            <span class="ph-grow">
                                <span class="ph-t">Change password</span>
                                <span class="ph-s">Update your login password</span>
                            </span>
                            <i class="fa-solid fa-chevron-right ph-chev"></i>
                        </a>
                        <button type="button" class="ph-menu-item" data-bs-toggle="modal" data-bs-target="#phHelpModal">
                            <span class="ph-ic"><i class="fa-solid fa-circle-question"></i></span>
                            <span class="ph-grow">
                                <span class="ph-t">Help &amp; FAQs</span>
                                <span class="ph-s">Staff access, GenCoin, the POS and passwords</span>
                            </span>
                            <i class="fa-solid fa-chevron-right ph-chev"></i>
                        </button>
                        <button type="button" class="ph-menu-item is-danger" onclick="openLogoutModal(event);">
                            <span class="ph-ic is-bad"><i class="fa-solid fa-arrow-right-from-bracket"></i></span>
                            <span class="ph-grow"><span class="ph-t">Log out</span></span>
                        </button>
                    </nav>
                </section>

                <div class="ph-foot">GenPay v<?= $e(GJC_APP_VERSION) ?></div>

                </div><!-- /.ph-col -->
                </div><!-- /.ph-grid -->

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>

    <!-- Help & FAQs (a bottom sheet on phones) -->
    <div class="modal fade ph-sheet" id="phHelpModal" tabindex="-1" aria-labelledby="phHelpTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="phHelpTitle">Help &amp; FAQs</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <details class="ph-faq" open>
                        <summary>What can I do with a staff account?</summary>
                        <p>You can ring up sales on the <strong>POS</strong> and update stock on <strong>Inventory</strong> for your stall. Encashment, rent, staff and the business profile are handled by your merchant admin.</p>
                    </details>
                    <details class="ph-faq">
                        <summary>What is GenCoin (GC)?</summary>
                        <p>GenCoin is GenPay's in-app currency, fixed at &#8369;10 = 1 GC. Students pay with it at your stall, and every sale goes straight to your stall's wallet &mdash; never to your staff account.</p>
                    </details>
                    <details class="ph-faq">
                        <summary>Why does my POS say it's paused?</summary>
                        <p>Either your account was set to inactive by your merchant admin, or the stall itself is suspended by the finance office. Check <strong>Account status</strong> on your profile and ask your merchant admin.</p>
                    </details>
                    <details class="ph-faq">
                        <summary>How do I change my position or email?</summary>
                        <p>Only your merchant admin can change those, from their Staff Management page. You can update your own name, mobile number and personal details from <strong>Edit profile</strong>.</p>
                    </details>
                    <details class="ph-faq">
                        <summary>I forgot my password. What do I do?</summary>
                        <p>Ask your merchant admin to reset it. You will be asked to choose a new one the next time you sign in. If you're already logged in and just want to change it, use <strong>Change password</strong> in Settings.</p>
                    </details>
                </div>
            </div>
        </div>
    </div>

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
                // A new photo can complete the profile, which changes the
                // nudge — simplest honest refresh is a reload.
                location.href = location.pathname + '?updated=1';
                return;
            } else {
                msg.textContent = data.error || 'Upload failed.';
                msg.style.color = '#fca5a5';
            }
        } catch(err) {
            msg.textContent = 'Network error. Please try again.';
            msg.style.color = '#fca5a5';
        }
        this.value = '';
    });
    </script>

    <script>
    // Keyboard access for the <label> buttons that open a hidden file picker.
    document.querySelectorAll('label[role="button"][for="photoInput"]').forEach(function (lbl) {
        lbl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); document.getElementById('photoInput').click(); }
        });
    });
    </script>

</body>

</html>
