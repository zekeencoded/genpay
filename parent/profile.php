<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';

gjc_require_role(['parent']);
gjc_ensure_user_profile_schema($db);
gjc_ensure_parent_schema($db);
gjc_ensure_parent_wallet_schema($db);

$currentUser = gjc_current_user($db);
$rawUser     = $currentUser['raw'] ?? [];
$idCol       = gjc_column($db, 'users', ['id', 'userID']);
$columns     = gjc_table_columns($db, 'users');
$notice      = '';
$error       = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $idCol) {
    $action = (string) ($_POST['profile_action'] ?? '');

    if ($action === 'profile') {
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName  = trim((string) ($_POST['last_name']  ?? ''));
        $phone     = trim((string) ($_POST['phone']       ?? ''));

        // Sex / birth date / address / emergency contact, validated by the same
        // helper the student profile uses so both portals enforce one rule set.
        $details = gjc_collect_profile_details($_POST, $columns);

        if ($firstName === '' || $lastName === '') {
            $error = 'First name and last name are required.';
        } elseif ($details['error'] !== null) {
            $error = $details['error'];
        } else {
            $updates = [];
            $values  = [];
            foreach ([
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'name'       => trim($firstName . ' ' . $lastName),
                'phone'      => $phone,
            ] + $details['values'] as $col => $val) {
                if (in_array($col, $columns, true)) {
                    $updates[] = "{$col} = ?";
                    $values[]  = $val;
                }
            }
            if ($updates) {
                $values[] = $currentUser['id'];
                try {
                    gjc_save_profile_with_address(
                        $db, (int) $currentUser['id'],
                        'UPDATE users SET ' . implode(', ', $updates) . " WHERE {$idCol} = ?",
                        $values, $details['values']
                    );
                    $notice = 'Profile updated successfully!';
                } catch (Throwable $e) {
                    error_log('parent/profile.php: ' . $e->getMessage());
                    $error = 'Profile could not be saved. Please try again.';
                }
            }
        }

    } elseif ($action === 'password') {
        $current  = (string) ($_POST['current_password']  ?? '');
        $new      = (string) ($_POST['new_password']      ?? '');
        $confirm  = (string) ($_POST['confirm_password']  ?? '');
        $stored   = (string) ($rawUser['password']         ?? '');

        // bcrypt only — the plaintext fallback that used to sit here was removed
        // 2026-09-06 (no un-hashed rows exist; see record.php).
        $valid = $stored !== '' && password_verify($current, $stored);

        if (!in_array('password', $columns, true)) {
            $error = 'Password column not found.';
        } elseif (!$valid) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            $db->prepare("UPDATE users SET password = ? WHERE {$idCol} = ?")
               ->execute([password_hash($new, PASSWORD_DEFAULT), $currentUser['id']]);
            // No before-state: see the note in student/security.php.
            logAudit($db, (int)$currentUser['id'], gjc_current_role(),
                'PASSWORD_CHANGE', 'users',
                null,
                ['password_changed' => true, 'changed_via' => 'parent_profile'],
                null,
                ['target_id' => (int)$currentUser['id']]);
            $notice = 'Password updated successfully!';
        }
    }

    $currentUser = gjc_current_user($db);
    $rawUser     = $currentUser['raw'] ?? [];
}

$firstName  = (string) ($rawUser['first_name'] ?? '');
$lastName   = (string) ($rawUser['last_name']  ?? '');
if ($firstName === '' && $lastName === '') {
    $parts     = preg_split('/\s+/', trim($currentUser['name']), 2);
    $firstName = $parts[0] ?? '';
    $lastName  = $parts[1] ?? '';
}
$fullName      = $currentUser['name'];
$initial       = strtoupper(substr($fullName, 0, 1));
$email         = (string) ($currentUser['email']        ?? '');
$phone         = (string) ($rawUser['phone']            ?? '');
$createdAt     = (string) ($rawUser['created_at']       ?? '');
$memberSince   = $createdAt !== '' ? date('F Y', strtotime($createdAt)) : 'N/A';
$profileImg    = (string) ($rawUser['profile_img']      ?? '');
$profilePhotoUrl = ($profileImg !== '') ? (BASE_URL . '/' . ltrim($profileImg, '/')) : '';

// Hero figures: the wallet and how many students it serves.
$parentId      = gjc_parent_id_for_user($db, (int) $currentUser['id']);
$walletBalance = (float) gjc_parent_wallet($db, $parentId)['balance'];
$linkedStmt    = $db->prepare('SELECT COUNT(*) FROM parent_student_links WHERE parent_id = ?');
$linkedStmt->execute([$parentId]);
$linkedCount   = (int) $linkedStmt->fetchColumn();

// Open the tab whose form was just submitted, so its result is in view.
$phTab  = ($_POST['profile_action'] ?? '') === 'password' ? 'password' : 'details';
$phTabs = ['details' => 'Edit profile', 'password' => 'Change password'];

$currentPage = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=24">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/profile_hub.css?v=6">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_profile.css?v=10">
</head>
<body class="gp-theme">
<div class="parent-layout">

    <?php require __DIR__ . '/../includes/partials/sidebar_parent.php'; ?>

    <main class="parent-main">

        <?php
        $topbarTitle = 'My Profile';
        $topbarSubtitle = 'Manage your account details and security.';
        require __DIR__ . '/../includes/partials/topbar_parent.php';
        ?>

        <div class="parent-content parent-content--wide">

            <?php if ($notice): ?>
            <div class="flash ok"><i class="fa-solid fa-circle-check me-1"></i><?= htmlspecialchars($notice) ?></div>
            <?php elseif ($error): ?>
            <div class="flash err"><i class="fa-solid fa-circle-xmark me-1"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Hero: the parent's GenPay card as an object (who you are), with
                 the wallet beside it. Same design as student/profile.php. -->
            <section class="ph-id" aria-label="Account summary">
                <div class="ph-id-card">
                    <div class="ph-id-brand">
                        <img src="<?= ICONS_URL ?>/gp_logo.png" alt="">
                        GenPay
                    </div>
                    <div class="ph-id-holder">
                        <div class="ph-id-photo" id="avatarCircle">
                            <?php if ($profilePhotoUrl): ?>
                                <img id="avatarImg" src="<?= htmlspecialchars($profilePhotoUrl) ?>" alt="Profile Photo">
                            <?php else: ?>
                                <span id="avatarInitial"><?= htmlspecialchars($initial) ?></span>
                            <?php endif; ?>
                            <label for="photoInput" role="button" tabindex="0" class="ph-avatar-edit" title="Change photo" aria-label="Change photo">
                                <i class="fa-solid fa-camera"></i>
                            </label>
                            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" hidden>
                        </div>
                        <div class="ph-id-who">
                            <h2 class="ph-id-name"><?= htmlspecialchars($fullName) ?></h2>
                            <p class="ph-id-no is-text">Parent / Guardian</p>
                            <p class="ph-id-since">Member since <?= htmlspecialchars($memberSince) ?></p>
                        </div>
                    </div>
                </div>

                <div class="ph-id-wallet">
                    <div class="ph-id-top">
                        <div>
                            <span class="ph-id-k">Wallet balance</span>
                            <span class="ph-id-amount"><?= gjc_gc_amount($walletBalance) ?> <small>GC</small></span>
                            <span class="ph-id-k">&#8776; <?= gjc_money($walletBalance) ?></span>
                        </div>
                    </div>
                    <div class="ph-photo-msg" id="photoMsg"></div>

                    <dl class="ph-id-facts">
                        <div>
                            <dt>Linked students</dt>
                            <dd><?= $linkedCount ?></dd>
                        </div>
                        <div>
                            <dt>Phone</dt>
                            <dd><?= $phone !== '' ? htmlspecialchars($phone) : '<span>Not set</span>' ?></dd>
                        </div>
                        <div>
                            <dt>Email</dt>
                            <dd><?= htmlspecialchars($email) ?></dd>
                        </div>
                    </dl>
                </div>
            </section>

            <!-- One form at a time instead of both stacked. -->
            <div class="ph-tabs" role="tablist" aria-label="Profile sections">
                <?php foreach ($phTabs as $key => $label): ?>
                <button type="button" class="ph-tab<?= $key === $phTab ? ' active' : '' ?>" id="ph-tab-<?= $key ?>"
                        data-bs-toggle="tab" data-bs-target="#ph-pane-<?= $key ?>" role="tab"
                        aria-controls="ph-pane-<?= $key ?>" aria-selected="<?= $key === $phTab ? 'true' : 'false' ?>">
                    <?= $label ?>
                </button>
                <?php endforeach; ?>
            </div>

            <div class="tab-content">

                <div class="tab-pane<?= $phTab === 'details' ? ' active' : '' ?>" id="ph-pane-details" role="tabpanel" aria-labelledby="ph-tab-details" tabindex="0">
                    <!-- Edit profile -->
                    <div class="pcard">
                        <h5><i class="fa-solid fa-pen me-2" style="color:var(--gp-green-700)"></i>Edit Profile</h5>
                        <p>Update your name, contact details and personal information.</p>
                        <form method="POST">
                            <input type="hidden" name="profile_action" value="profile">
                            <div class="pgrid">
                                <div class="pfield">
                                    <label>First Name</label>
                                    <input type="text" name="first_name" value="<?= htmlspecialchars($firstName) ?>" required>
                                </div>
                                <div class="pfield">
                                    <label>Last Name</label>
                                    <input type="text" name="last_name" value="<?= htmlspecialchars($lastName) ?>" required>
                                </div>
                            </div>
                            <div class="pfield">
                                <label>Phone Number</label>
                                <input type="text" name="phone" value="<?= htmlspecialchars($phone) ?>" placeholder="e.g. 09171234567">
                            </div>
                            <div class="pfield">
                                <label>Email Address</label>
                                <input type="email" value="<?= htmlspecialchars($email) ?>" disabled>
                                <small>Email cannot be changed. Contact Finance if needed.</small>
                            </div>

                            <?php
                            $pdRow   = $rawUser;
                            $pdField = 'pfield';
                            $pdGrid  = 'pgrid';
                            require __DIR__ . '/../includes/partials/profile_details_fields.php';
                            ?>

                            <button type="submit" class="btn-primary-save"><i class="fa-solid fa-floppy-disk me-1"></i>Save Changes</button>
                        </form>
                    </div>
                </div>

                <div class="tab-pane<?= $phTab === 'password' ? ' active' : '' ?>" id="ph-pane-password" role="tabpanel" aria-labelledby="ph-tab-password" tabindex="0">
                    <!-- Change password -->
                    <div class="pcard">
                        <h5><i class="fa-solid fa-lock me-2" style="color:var(--gp-green-700)"></i>Change Password</h5>
                        <p>Keep your account secure by updating your password regularly.</p>
                        <form method="POST">
                            <input type="hidden" name="profile_action" value="password">
                            <div class="pfield">
                                <label>Current Password</label>
                                <input type="password" name="current_password" required autocomplete="current-password">
                            </div>
                            <div class="pgrid">
                                <div class="pfield">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" minlength="6" required autocomplete="new-password">
                                </div>
                                <div class="pfield">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" minlength="6" required autocomplete="new-password">
                                </div>
                            </div>
                            <button type="submit" class="btn-primary-save"><i class="fa-solid fa-key me-1"></i>Update Password</button>
                        </form>
                    </div>
                </div>

            </div>

        </div>
    </main>
</div>

<?php require __DIR__ . '/../includes/partials/logout_modal.php'; ?>
<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script>
function toggleParentSidebar() {
    document.getElementById('parentSidebar').classList.toggle('collapsed');
}

/* ── Profile photo upload ─────────────────────────────────── */
const PHOTO_API = '<?= BASE_URL ?>/api/profile_photo.php';

document.getElementById('photoInput').addEventListener('change', async function() {
    const file = this.files[0];
    if (!file) return;

    const msg = document.getElementById('photoMsg');
    msg.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';
    msg.style.color = 'var(--gp-muted)';

    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('photo', file);

    try {
        const res  = await fetch(PHOTO_API, {method: 'POST', body: fd, credentials: 'same-origin'});
        const data = await res.json();
        if (data.success) {
            const circle  = document.getElementById('avatarCircle');
            const initial = document.getElementById('avatarInitial');
            if (initial) initial.remove();
            let img = document.getElementById('avatarImg');
            if (!img) {
                img = document.createElement('img');
                img.id = 'avatarImg';
                img.alt = 'Profile Photo';
                circle.appendChild(img);
            }
            img.src = data.photo_url;

            // Also update topbar avatar
            const topbar = document.getElementById('topbarAvatar');
            if (topbar) {
                topbar.style.padding  = '0';
                topbar.style.overflow = 'hidden';
                const tInit = document.getElementById('topbarAvatarInitial');
                if (tInit) tInit.remove();
                let tImg = document.getElementById('topbarAvatarImg');
                if (!tImg) {
                    tImg = document.createElement('img');
                    tImg.id = 'topbarAvatarImg';
                    tImg.alt = '';
                    tImg.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;';
                    topbar.appendChild(tImg);
                }
                tImg.src = data.photo_url;
            }

            msg.innerHTML = '<i class="fa-solid fa-check"></i> Photo updated.';
            msg.style.color = 'var(--gp-success)';
            setTimeout(() => { msg.innerHTML = ''; }, 3000);
        } else {
            msg.innerHTML = data.error || 'Upload failed.';
            msg.style.color = 'var(--gp-danger)';
        }
    } catch(err) {
        msg.innerHTML = 'Network error. Please try again.';
        msg.style.color = 'var(--gp-danger)';
    }
    this.value = '';
});
</script>

<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
