<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);
gjc_ensure_user_profile_schema($db);
gjc_ensure_student_entry_year_schema($db);

/**
 * CRUD "Read": view a single user record as the account's real profile. The
 * record is identified by a signed, opaque token in the URL (?token=...).
 * Because the token is an HMAC over the id, editing the URL by hand fails
 * verification and falls through to "not found" — so the token can sit in the
 * URL safely, and the page still can't be walked by incrementing an id.
 *
 * Everything below the user row is read-only: profile data is pulled with plain
 * SELECTs (never the gjc_*_wallet() accessors, which would create wallet rows).
 */
$userId = gjc_verify_view_token($_GET['token'] ?? null, 'user');

$user = null;
if ($userId !== null) {
    $idCol = gjc_column($db, 'users', ['userID', 'id']) ?? 'userID';
    $stmt = $db->prepare("SELECT * FROM users WHERE {$idCol} = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($user) {
        $user = gjc_with_user_address($db, $user, (int) $userId);
    }
}

if (!$user) {
    http_response_code(404);
}

$esc = gjc_e(...);

if ($user) {
    $roleId = (int) ($user['roleID'] ?? 0);
    $roleLabels = [
        1 => 'Student', 2 => 'Merchant', 3 => 'Finance', 4 => 'Finance',
        5 => 'Merchant', 6 => 'Merchant Staff', 7 => 'Parent',
    ];
    $roleLabel = $roleLabels[$roleId] ?? 'User';
    $roleGroup = match ($roleId) {
        1 => 'student',
        2, 5, 6 => 'merchant',
        3, 4 => 'finance',
        7 => 'parent',
        default => 'other',
    };

    $firstName = trim((string) ($user['first_name'] ?? ''));
    $lastName  = trim((string) ($user['last_name'] ?? ''));
    $fullName = trim(implode(' ', array_filter([
        $firstName,
        trim((string) ($user['middle_name'] ?? '')),
        $lastName,
        trim((string) ($user['suffix'] ?? '')),
    ], static fn($p) => $p !== '')));
    if ($fullName === '') {
        $fullName = 'Unnamed User';
    }

    $initials = strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
    if ($initials === '') {
        $initials = 'U';
    }

    // Real profile photo only when the stored value looks like an image path —
    // sample rows carry junk like '' or 'f' that must not become an <img>.
    $profileImg = (string) ($user['profile_img'] ?? '');
    $photoUrl = preg_match('/\.(png|jpe?g|webp|gif)$/i', $profileImg)
        ? BASE_URL . '/' . ltrim($profileImg, '/')
        : null;

    $status = trim((string) ($user['status'] ?? 'Active'));
    if ($status === '') {
        $status = 'Active';
    }
    $statusKey = strtolower($status);
    $statusClass = match (true) {
        $statusKey === 'active' => 'is-active',
        in_array($statusKey, ['inactive', 'blocked', 'suspended', 'banned'], true) => 'is-inactive',
        default => 'is-neutral',
    };

    $contact = trim((string) ($user['contact_number'] ?? ''));
    $email = trim((string) ($user['email'] ?? ''));
    $createdAt = !empty($user['created_at'])
        ? date('M d, Y · g:i A', strtotime((string) $user['created_at']))
        : '—';

    // ---- Role-specific profile data (read-only) --------------------------
    // A wallet balance is shown only to the account's own owner. This page is
    // finance-only, so the viewer is an admin looking at someone else's record
    // — in practice the balance stays hidden unless an admin opens their own
    // account (finance accounts have no wallet, so the box simply won't appear).
    $isOwner = (gjc_user_id() === (int) $userId);
    $walletBalance = null;   // shown in the hero only when $isOwner
    $student = null;
    $merchant = null;
    $parent = null;

    if ($roleGroup === 'student') {
        if (gjc_table_exists($db, 'student_info')) {
            // Year level for the active school year, else the entry year level.
            $hasEnrollments = gjc_table_exists($db, 'student_enrollments');
            $q = $db->prepare(
                "SELECT si.studentID, "
                . ($hasEnrollments ? "COALESCE(se.yr_lvl, si.entry_yr_lvl)" : "si.entry_yr_lvl") . " AS yr_lvl,
                        si.graduated_at, c.course_code, c.course_name
                   FROM student_info si
                   LEFT JOIN course c ON c.courseID = si.courseID "
                . ($hasEnrollments ? "LEFT JOIN student_enrollments se ON se.student_user_id = si.userID AND se.school_year_id = ? " : "")
                . "WHERE si.userID = ? LIMIT 1"
            );
            $q->execute($hasEnrollments ? [gjc_active_school_year_id($db) ?? 0, $userId] : [$userId]);
            $student = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (gjc_table_exists($db, 'student_wallets')) {
            $q = $db->prepare("SELECT balance, is_frozen, daily_spend_limit FROM student_wallets WHERE user_id = ? LIMIT 1");
            $q->execute([$userId]);
            if ($sw = $q->fetch(PDO::FETCH_ASSOC)) {
                $walletBalance = (float) $sw['balance'];
                $student['is_frozen'] = (int) $sw['is_frozen'] === 1;
                $student['daily_limit'] = (float) $sw['daily_spend_limit'];
            }
        }
    } elseif ($roleGroup === 'merchant') {
        if (gjc_table_exists($db, 'merchant')) {
            $q = $db->prepare(
                "SELECT m.stall_name, m.operational_status, m.notes,
                        s.label AS stall_label, s.row_label, s.col_number,
                        s.area_sqm, s.monthly_rate, s.status AS stall_status
                   FROM merchant m
                   LEFT JOIN stalls s ON s.stall_id = m.stall_id
                  WHERE m.userID = ? LIMIT 1"
            );
            $q->execute([$userId]);
            $merchant = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (gjc_table_exists($db, 'merchant_wallets')) {
            $q = $db->prepare("SELECT balance FROM merchant_wallets WHERE user_id = ? LIMIT 1");
            $q->execute([$userId]);
            $b = $q->fetchColumn();
            if ($b !== false) {
                $walletBalance = (float) $b;
            }
        }
    } elseif ($roleGroup === 'parent') {
        $parent = ['linked' => 0, 'threshold' => null];
        if (gjc_table_exists($db, 'parents')) {
            $q = $db->prepare("SELECT id, low_balance_threshold FROM parents WHERE user_id = ? LIMIT 1");
            $q->execute([$userId]);
            if ($p = $q->fetch(PDO::FETCH_ASSOC)) {
                $parent['threshold'] = $p['low_balance_threshold'];
                if (gjc_table_exists($db, 'parent_wallets')) {
                    $q2 = $db->prepare("SELECT balance FROM parent_wallets WHERE parent_id = ? LIMIT 1");
                    $q2->execute([(int) $p['id']]);
                    $b = $q2->fetchColumn();
                    if ($b !== false) {
                        $walletBalance = (float) $b;
                    }
                }
                if (gjc_table_exists($db, 'parent_student_links')) {
                    $q3 = $db->prepare("SELECT COUNT(*) FROM parent_student_links WHERE parent_id = ?");
                    $q3->execute([(int) $p['id']]);
                    $parent['linked'] = (int) $q3->fetchColumn();
                }
            }
        }
    }

    // Human-facing display id (students resolve to their real school ID above).
    if ($roleGroup === 'student') {
        $displayId = trim((string) ($student['studentID'] ?? '')) !== ''
            ? (string) $student['studentID']
            : 'GJC' . date('Y') . '-????';
    } elseif ($roleGroup === 'merchant') {
        $displayId = 'MER-' . str_pad((string) $userId, 4, '0', STR_PAD_LEFT);
    } elseif ($roleGroup === 'finance') {
        $displayId = 'FIN-' . str_pad((string) $userId, 4, '0', STR_PAD_LEFT);
    } elseif ($roleGroup === 'parent') {
        $displayId = 'PAR-' . str_pad((string) $userId, 4, '0', STR_PAD_LEFT);
    } else {
        $displayId = 'GJC-' . str_pad((string) $userId, 4, '0', STR_PAD_LEFT);
    }
}

// Field row renderer for the detail cards.
$field = static function (string $icon, string $label, string $value, bool $mono = false) use ($esc): void {
    echo '<div class="uv-field"><div class="uv-field-ic"><i class="' . $esc($icon) . '"></i></div><div>'
        . '<div class="uv-field-label">' . $esc($label) . '</div>'
        . '<div class="uv-field-value' . ($mono ? ' is-mono' : '') . '">' . $esc($value) . '</div>'
        . '</div></div>';
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $user ? 'User Details' : 'Content Not Available' ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=15">
</head>

<body class="gp-theme">
    <div class="uv-wrap">
        <?php if (!$user): ?>
        <!-- Deliberately generic (Google/Facebook style): reveals nothing about
             whether the token was missing, expired, or tampered with. -->
        <div class="uv-notfound">
            <div class="uv-nf-ic"><i class="fa-regular fa-circle-question"></i></div>
            <h1>This page isn't available</h1>
            <p>The link you followed may be broken, or the record may have been removed.</p>
            <a href="<?= ADMIN_URL ?>/users.php" class="gp-btn gp-btn--forest">Go to Users</a>
        </div>
        <?php else: ?>
        <div class="uv-topbar">
            <a class="uv-back" href="<?= ADMIN_URL ?>/users.php">
                <i class="fa-solid fa-arrow-left"></i> Back to Users
            </a>
        </div>

        <section class="uv-hero">
            <div class="uv-hero-id">
                <div class="uv-avatar">
                    <?php if ($photoUrl): ?>
                    <img src="<?= $esc($photoUrl) ?>" alt="" onerror="this.remove()">
                    <?php endif; ?>
                    <?= $esc($initials) ?>
                </div>
                <div class="uv-hero-main">
                    <div class="uv-eyebrow"><?= $esc($roleLabel) ?> account</div>
                    <h1 class="uv-name"><?= $esc($fullName) ?></h1>
                    <div class="uv-id-line">
                        <span class="gp-hero-badge"><?= $esc($roleLabel) ?></span>
                        <span class="uv-mono"><?= $esc($displayId) ?></span>
                        <span class="uv-status <?= $statusClass ?>"><?= $esc($status) ?></span>
                    </div>
                </div>
            </div>

            <?php if ($walletBalance !== null && $isOwner): /* balance is visible only to the account's own owner */ ?>
            <div class="uv-hero-wallet">
                <span class="uv-hw-label">Wallet Balance</span>
                <div class="uv-hw-value"><?= gjc_gc_amount($walletBalance) ?> GC</div>
                <span class="uv-hw-sub">&#8776; <?= gjc_money($walletBalance) ?></span>
            </div>
            <?php endif; ?>
        </section>

        <?php if ($roleGroup === 'student'): ?>
        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div><h3>Academic</h3><p>Enrollment details on record.</p></div>
            </div>
            <div class="uv-fields">
                <?php
                $courseText = trim((string) ($student['course_name'] ?? ''));
                if ($courseText !== '' && !empty($student['course_code'])) {
                    $courseText .= ' (' . $student['course_code'] . ')';
                }
                $yrText = !empty($student['yr_lvl']) ? 'Year ' . $student['yr_lvl'] : '—';
                $standing = !empty($student['graduated_at'])
                    ? 'Graduated · ' . date('M Y', strtotime((string) $student['graduated_at']))
                    : 'Enrolled';
                $field('fa-solid fa-id-card', 'Student ID', $displayId, true);
                $field('fa-solid fa-graduation-cap', 'Course', $courseText !== '' ? $courseText : '—');
                $field('fa-solid fa-layer-group', 'Year Level', $yrText);
                $field('fa-solid fa-user-graduate', 'Standing', $standing);
                ?>
            </div>
        </section>

        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div><h3>Wallet controls</h3><p>Spending controls on this wallet.</p></div>
            </div>
            <div class="uv-fields">
                <?php
                // Student wallet balance is intentionally not shown in this view.
                $field('fa-solid fa-snowflake', 'Wallet Status', (!empty($student['is_frozen']) ? 'Frozen' : 'Active'));
                $dl = (float) ($student['daily_limit'] ?? 0);
                $field('fa-solid fa-gauge-high', 'Daily Limit', ($dl > 0 ? '₱' . number_format($dl, 2) . ' / day' : 'No limit'));
                ?>
            </div>
        </section>
        <?php elseif ($roleGroup === 'merchant'): ?>
        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div><h3>Business</h3><p>Stall and operating details.</p></div>
            </div>
            <div class="uv-fields">
                <?php
                $stallName = trim((string) ($merchant['stall_name'] ?? ''));
                $loc = trim((string) ($merchant['stall_label'] ?? ''));
                if ($loc === '') {
                    $rc = trim((string) ($merchant['row_label'] ?? '') . (string) ($merchant['col_number'] ?? ''));
                    $loc = $rc !== '' ? $rc : 'Unassigned';
                }
                $opStatus = trim((string) ($merchant['operational_status'] ?? ''));
                $area = !empty($merchant['area_sqm']) ? rtrim(rtrim(number_format((float) $merchant['area_sqm'], 2), '0'), '.') . ' sqm' : '—';
                $rate = isset($merchant['monthly_rate']) && $merchant['monthly_rate'] !== null ? gjc_money($merchant['monthly_rate']) : '—';
                $field('fa-solid fa-store', 'Stall Name', $stallName !== '' ? $stallName : '—');
                $field('fa-solid fa-location-dot', 'Location', $loc);
                $field('fa-solid fa-circle-dot', 'Operational Status', $opStatus !== '' ? ucfirst($opStatus) : '—');
                $field('fa-solid fa-ruler-combined', 'Stall Area', $area);
                echo '<div class="uv-field"><div class="uv-field-ic"><i class="fa-solid fa-money-bill-wave"></i></div><div>'
                    . '<div class="uv-field-label">Monthly Rate</div>'
                    . '<div class="uv-field-value">' . $rate . '</div></div></div>';
                ?>
            </div>
        </section>
        <?php elseif ($roleGroup === 'parent'): ?>
        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div><h3>Guardian</h3><p>Linked students and alert settings.</p></div>
            </div>
            <div class="uv-fields">
                <?php
                $linked = (int) ($parent['linked'] ?? 0);
                $threshold = ($parent['threshold'] ?? null) !== null ? gjc_money($parent['threshold']) : 'Not set';
                $field('fa-solid fa-children', 'Linked Students', (string) $linked);
                echo '<div class="uv-field"><div class="uv-field-ic"><i class="fa-solid fa-bell"></i></div><div>'
                    . '<div class="uv-field-label">Low-balance Alert</div>'
                    . '<div class="uv-field-value">' . $threshold . '</div></div></div>';
                ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Personal details apply to every role, so this card sits outside the
             per-role blocks above. Read-only here: the account's owner maintains
             these from their own profile page. -->
        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div><h3>Personal details</h3><p>Demographics on record for this account.</p></div>
            </div>
            <div class="uv-fields">
                <?php
                $sex = trim((string) ($user['sex'] ?? ''));
                $address = gjc_format_address($user);
                $dob = $user['date_of_birth'] ?? null;
                $field('fa-solid fa-venus-mars', 'Sex', $sex !== '' ? $sex : '—');
                // Age stands as its own field but is still computed from the
                // birth date below it, so the two can never contradict.
                $field('fa-solid fa-hourglass-half', 'Age', gjc_age_label($dob));
                $field('fa-solid fa-cake-candles', 'Date of Birth', gjc_dob_label($dob));
                $field('fa-solid fa-location-dot', 'Address', $address !== '' ? $address : '—');
                ?>
            </div>
        </section>

        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div><h3>Contact &amp; account</h3><p>How to reach this account.</p></div>
            </div>
            <div class="uv-fields">
                <?php
                $field('fa-regular fa-envelope', 'Email', $email !== '' ? $email : '—');
                $field('fa-solid fa-phone', 'Contact Number', $contact !== '' ? $contact : '—');
                $field('fa-solid fa-user-shield', 'Role', $roleLabel);
                $field('fa-regular fa-calendar', 'Registered', $createdAt);
                $field('fa-solid fa-hashtag', 'Display ID', $displayId, true);
                $field('fa-solid fa-fingerprint', 'System ID', (string) $userId, true);
                ?>
            </div>
        </section>

        <?php endif; ?>
    </div>
</body>

</html>
