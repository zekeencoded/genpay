<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['merchant']);
// Same gate as merchant/staff.php: staff accounts are the owner's records,
// so a staff login must not be able to read its colleagues' details.
if (
    !gjc_is_merchant_admin() &&
    (gjc_current_role() !== 'merchant' || gjc_is_merchant_staff())
) {
    header('Location: ' . DASHBOARD_URL);
    exit();
}

/**
 * CRUD "Read": one staff account, in the shared .uv-* record chrome
 * (assets/css/detail_view.css). The id travels as a signed HMAC token so the
 * URL can't be walked, and the lookup stays pinned to this owner's staff
 * (merchant_owner_id + roleID 6) so no other stall's roster is reachable.
 */
$currentUser = gjc_current_user($db);
$merchantUserId = (int) $currentUser['id'];

gjc_ensure_staff_position_schema($db);

$staffId = gjc_verify_view_token($_GET['token'] ?? null, 'merchant_staff');

$staff = null;
if ($staffId !== null) {
    $stmt = $db->prepare(
        "SELECT * FROM users
          WHERE userID = ? AND merchant_owner_id = ? AND roleID = 6
          LIMIT 1"
    );
    $stmt->execute([$staffId, $merchantUserId]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$staff) {
    http_response_code(404);
}

// Local escaper — the shared gjc_e() only casts to string, and this page
// renders staff-supplied names, emails and contact numbers.
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($staff) {
    $firstName = trim((string) ($staff['first_name'] ?? ''));
    $lastName  = trim((string) ($staff['last_name'] ?? ''));
    $fullName = trim(implode(' ', array_filter([
        $firstName,
        trim((string) ($staff['middle_name'] ?? '')),
        $lastName,
        trim((string) ($staff['suffix'] ?? '')),
    ], static fn($part) => $part !== '')));
    if ($fullName === '') {
        $fullName = 'Staff #' . (int) $staff['userID'];
    }

    $initials = strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
    if (trim($initials) === '') {
        $initials = 'S';
    }

    $photo = trim((string) ($staff['profile_img'] ?? ''));
    $photoUrl = $photo !== '' ? BASE_URL . '/' . ltrim($photo, '/') : '';

    $position = trim((string) ($staff['position'] ?? ''));
    if ($position === '') {
        $position = 'Merchant Staff';
    }

    // staff.php treats a missing status as Active, so match that reading
    // rather than showing a blank badge here.
    $isActive = (string) ($staff['status'] ?? 'Active') === 'Active';
    $statusClass = $isActive ? 'is-active' : 'is-inactive';
    $statusLabel = $isActive ? 'Active' : 'Inactive';

    $addedAt = !empty($staff['created_at'])
        ? date('M d, Y h:i A', strtotime((string) $staff['created_at']))
        : 'N/A';

    $subRole = trim((string) ($staff['sub_role'] ?? ''));
    $subRoleLabel = $subRole !== '' ? ucwords(str_replace('_', ' ', $subRole)) : 'Merchant Staff';
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
    <title><?= $staff ? 'Staff Details' : 'Content Not Available' ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=21">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=11">
</head>

<body class="gp-theme">
    <div class="uv-wrap">
        <?php if (!$staff): ?>
        <div class="uv-notfound">
            <div class="uv-nf-ic"><i class="fa-regular fa-circle-question"></i></div>
            <h1>This page isn't available</h1>
            <p>The link you followed may be broken, or the account may no longer be on your staff roster.</p>
            <a href="<?= MERCHANT_URL ?>/staff.php" class="gp-btn gp-btn--forest">Go to Staff Management</a>
        </div>
        <?php else: ?>
        <div class="uv-topbar">
            <a class="uv-back" href="<?= MERCHANT_URL ?>/staff.php">
                <i class="fa-solid fa-arrow-left"></i> Back to Staff Management
            </a>
        </div>

        <section class="uv-hero">
            <div class="uv-avatar">
                <?php if ($photoUrl !== ''): ?>
                <img src="<?= $e($photoUrl) ?>" alt="" onerror="this.remove()">
                <?php endif; ?>
                <?= $e($initials) ?>
            </div>
            <div class="uv-hero-main">
                <div class="uv-eyebrow">Staff account</div>
                <h1 class="uv-name"><?= $e($fullName) ?></h1>
                <div class="uv-id-line">
                    <span class="gp-hero-badge"><?= $e($position) ?></span>
                    <span class="uv-mono">#<?= (int) $staff['userID'] ?></span>
                    <span class="uv-status <?= $statusClass ?>"><?= $e($statusLabel) ?></span>
                </div>
            </div>
        </section>

        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div>
                    <h3>Staff details</h3>
                    <p>Read-only view — change the position or status from the Staff Management page.</p>
                </div>
            </div>
            <div class="uv-fields">
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-regular fa-envelope"></i></div>
                    <div>
                        <div class="uv-field-label">Email</div>
                        <div class="uv-field-value"><?= $e(trim((string) ($staff['email'] ?? '')) ?: 'Not set') ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-phone"></i></div>
                    <div>
                        <div class="uv-field-label">Contact Number</div>
                        <div class="uv-field-value"><?= $e(trim((string) ($staff['contact_number'] ?? '')) ?: 'Not set') ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-id-badge"></i></div>
                    <div>
                        <div class="uv-field-label">Position</div>
                        <div class="uv-field-value"><?= $e($position) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-user-shield"></i></div>
                    <div>
                        <div class="uv-field-label">Access Level</div>
                        <div class="uv-field-value"><?= $e($subRoleLabel) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-regular fa-calendar-plus"></i></div>
                    <div>
                        <div class="uv-field-label">Account Created</div>
                        <div class="uv-field-value"><?= $e($addedAt) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <div class="uv-field-label">Status</div>
                        <div class="uv-field-value"><?= $isActive ? 'Active — can sign in and use the POS' : 'Inactive — sign-in is blocked' ?></div>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>
</body>

</html>
