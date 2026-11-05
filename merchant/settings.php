<?php
// Business Profile: the owner's public display name, logo, documents and contact.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';
require_once __DIR__ . '/../connection/audit_logger.php';

gjc_require_role(['merchant']);
if (gjc_is_merchant_staff()) {
    header('Location: ' . DASHBOARD_URL);
    exit;
}

gjc_ensure_user_profile_schema($db); // canonical personal-details schema
$currentUser = gjc_current_user($db);
$userId = (int) $currentUser['id'];

// Password & Security tab — same rules as parent/profile.php.
$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['profile_action'] ?? '') === 'password') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $stored  = (string) ($currentUser['raw']['password'] ?? '');

    if (!gjc_csrf_verify()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif ($stored === '' || !password_verify($current, $stored)) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $db->prepare('UPDATE users SET password = ? WHERE userID = ?')
           ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        // No before-state: see the note in student/security.php.
        logAudit($db, $userId, gjc_current_role(),
            'PASSWORD_CHANGE', 'users',
            null,
            ['password_changed' => true, 'changed_via' => 'merchant_business_profile'],
            null,
            ['target_id' => $userId]);
        $notice = 'Password updated successfully!';
    }
}

$wallet = gjc_merchant_wallet($db, $userId);

$stmt = $db->prepare(
    "SELECT m.merchantID, m.stall_name, m.stall_id, m.operational_status, m.created_at AS tenant_since,
            s.label AS stall_label, s.row_label, s.col_number, s.area_sqm, s.monthly_rate, s.status AS stall_status,
            u.profile_img, u.first_name, u.middle_name, u.last_name, u.suffix,
            u.email, u.contact_number, u.status AS account_status, u.created_at AS account_created
       FROM merchant m
       LEFT JOIN stalls s ON s.stall_id = m.stall_id
       LEFT JOIN users  u ON u.userID = m.userID
      WHERE m.userID = ?
      LIMIT 1"
);
$stmt->execute([$userId]);
$merchant = $stmt->fetch(PDO::FETCH_ASSOC);
if ($merchant) {
    $addressFields = ['address_barangay', 'address_city', 'address_province'];
    $merchant = array_replace(
        $merchant, array_fill_keys($addressFields, null),
        array_intersect_key($currentUser['raw'] ?? [], array_flip($addressFields))
    );
}

// ── Application record (may not exist for older merchants) ──
$application = null;
if (gjc_table_exists($db, 'stall_applications')) {
    $appStmt = $db->prepare(
        "SELECT business_name, proprietor_name, contact_number, email,
                street, barangay, city, province, sex,
                business_permit, sanitary_permit, clearance, gjc_requirements,
                deposit_amount, advance_amount, rental_start_date, payment_schedule_day,
                contract_file, contract_ref, awarded_at
           FROM " . gjc_stall_application_source($db) . "
          WHERE merchant_user_id = ? AND status = 'awarded'
          ORDER BY awarded_at DESC, id DESC
          LIMIT 1"
    );
    $appStmt->execute([$userId]);
    $application = $appStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Business at a glance ─────────────────────────────────────────────────
$glance = ['products' => 0, 'staff' => 0, 'restricted' => 0];

if (gjc_table_exists($db, 'merchant_inventory')) {
    $q = $db->prepare("SELECT COUNT(*), COALESCE(SUM(is_restricted), 0) FROM merchant_inventory WHERE merchant_user_id = ?");
    $q->execute([$userId]);
    [$glance['products'], $glance['restricted']] = array_map('intval', $q->fetch(PDO::FETCH_NUM));
}

// The team: every staff account that can sign in to this stall's POS.
$team = [];
if (in_array('merchant_owner_id', gjc_table_columns($db, 'users'), true)) {
    gjc_ensure_staff_position_schema($db);
    $q = $db->prepare(
        "SELECT first_name, last_name, position, profile_img
           FROM users
          WHERE merchant_owner_id = ? AND staff_removed_at IS NULL
          ORDER BY first_name, last_name"
    );
    $q->execute([$userId]);
    $team = $q->fetchAll(PDO::FETCH_ASSOC);
    $glance['staff'] = count($team);
}

// Today's gross sales — the same earning types the dashboard counts.
$salesToday = ['amount' => 0.0, 'orders' => 0];
if ($wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
    $q = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0), COUNT(*)
           FROM transactions
          WHERE merchant_wallet_id = ?
            AND transaction_type = ?
            AND created_at >= CURDATE()"
    );
    $q->execute([$wallet['id'], CirculationEngine::TXN_PAYMENT]);
    [$amount, $orders] = $q->fetch(PDO::FETCH_NUM);
    $salesToday = ['amount' => (float) $amount, 'orders' => (int) $orders];
}

/** Restore the leading zero lost by the BIGINT contact_number column. */
function profile_phone($value): string
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) === 10 && $digits[0] === '9') {
        $digits = '0' . $digits;
    }

    return $digits;
}

/** Renders a value, or a muted "not on file" line that says who can add it. */
function profile_value(?string $value, string $missing = 'Not on file'): string
{
    $value = trim((string) $value);

    return $value !== ''
        ? '<span class="gp-bp-value">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span>'
        : '<span class="gp-bp-missing">' . htmlspecialchars($missing, ENT_QUOTES, 'UTF-8') . '</span>';
}

$fullName = trim(implode(' ', array_filter([
    $merchant['first_name'] ?? '',
    $merchant['middle_name'] ?? '',
    $merchant['last_name'] ?? '',
    $merchant['suffix'] ?? '',
])));

// What the owner saved themselves (Edit contact) wins over the copy captured
// with their stall application.
$ownAddress = $merchant ? gjc_format_address($merchant) : '';
$address = $ownAddress !== '' ? $ownAddress : ($application
    ? trim(implode(', ', array_filter([
        $application['street'] ?? '',
        $application['barangay'] ?? '',
        $application['city'] ?? '',
        $application['province'] ?? '',
    ])))
    : '');

// The newest copy of each document wins: one the owner uploaded here, else
// the one captured with their awarded stall application.
$complianceDocs = gjc_merchant_compliance_docs();
$docFiles = $merchant ? gjc_merchant_document_files($db, $userId, $application) : [];
$csrfToken = gjc_csrf_token();

// "Stall B1" already names the unit, so don't print the code twice.
$stallTitle = 'No stall assigned yet';
if ($merchant && !empty($merchant['stall_id'])) {
    $stallTitle = trim((string) $merchant['stall_label']) !== '' ? (string) $merchant['stall_label'] : 'Stall ' . $merchant['stall_id'];
}

$currentPage = 'settings';
$logoUrl = $merchant && $merchant['profile_img'] ? BASE_URL . '/' . $merchant['profile_img'] : null;

$ownerPhone = $merchant ? profile_phone($merchant['contact_number'] ?: ($application['contact_number'] ?? '')) : '';
if ($ownerPhone === '0') {
    $ownerPhone = '';
}

// ── Completeness chips (each opens its action) ──
$profileChecks = $merchant ? [
    ['done' => $logoUrl !== null,                          'label' => 'Upload a logo',              'href' => null],
    ['done' => trim((string) $merchant['stall_name']) !== '', 'label' => 'Add a display name',      'href' => '#listing'],
    ['done' => $ownerPhone !== '',                         'label' => 'Add mobile number',          'href' => 'contact'],
    ['done' => isset($docFiles['business_permit']),        'label' => 'Upload business permit',     'href' => 'doc:business_permit'],
    ['done' => isset($docFiles['sanitary_permit']),        'label' => 'Upload sanitary permit',     'href' => 'doc:sanitary_permit'],
    ['done' => isset($docFiles['clearance']),              'label' => 'Upload barangay clearance',  'href' => 'doc:clearance'],
] : [];
$checksDone = count(array_filter($profileChecks, static fn($c) => $c['done']));
$checksTotal = count($profileChecks);
$checksPct = $checksTotal ? (int) round($checksDone / $checksTotal * 100) : 0;

$opStatus = (string) ($merchant['operational_status'] ?? 'active');
$memberSince = !empty($merchant['account_created']) ? date('F Y', strtotime($merchant['account_created'])) : 'N/A';

// Open the tab whose form was just submitted, so its result is in view.
$phTab  = ($_POST['profile_action'] ?? '') === 'password' ? 'password' : 'listing';
$phTabs = [
    'listing'   => 'Directory Listing',
    'documents' => 'Documents',
    'details'   => 'Stall &amp; Contact',
    'password'  => 'Password &amp; Security',
    'settings'  => 'Settings',
];
$paymentDay = (int) ($application['payment_schedule_day'] ?? 0);
$ordinal = static function (int $n): string {
    $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    return $n . $suffix;
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
    <title>Business Profile | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=67">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=34">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=3">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_profile.css?v=15">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant_settings.css?v=13">
    <link rel="stylesheet" href="<?= CSS_URL ?>/profile_hub.css?v=8">
</head>
<body class="gp-theme">
<div class="merchant-layout">
    <?php require __DIR__ . '/../includes/partials/sidebar_merchant_admin.php'; ?>

    <main class="merchant-main">
        <?php
        $topbarTitle = 'Business Profile';
        $topbarSubtitle = 'Your stall listing, account details and security.';
        require __DIR__ . '/../includes/partials/topbar_merchant.php';
        ?>

        <?php if (!$merchant): ?>
        <section class="merchant-premium-panel">
            <p class="text-muted mb-0">No merchant record is linked to this account yet.</p>
        </section>
        <?php else: ?>
        <div class="ph">

        <?php if ($notice): ?>
        <div class="pf-alert"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($notice) ?></div>
        <?php elseif ($error): ?>
        <div class="pf-alert is-error"><i class="fa-solid fa-circle-xmark"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Hero: the stall's GenPay card, with the wallet beside it.
             Same design as student/profile.php and parent/profile.php. -->
        <section class="ph-id" aria-label="Stall summary">
            <div class="ph-id-card">
                <div class="ph-id-brand">
                    <img src="<?= ICONS_URL ?>/gp_logo.png" alt="">
                    GenPay
                </div>
                <div class="ph-id-holder">
                    <div class="ph-id-photo is-logo" id="heroLogo"
                         <?= $logoUrl ? 'style="background-image:url(&quot;' . htmlspecialchars($logoUrl, ENT_QUOTES) . '&quot;)"' : '' ?>>
                        <span <?= $logoUrl ? 'hidden' : '' ?>><?= htmlspecialchars(mb_strtoupper(mb_substr($merchant['stall_name'], 0, 1))) ?></span>
                        <label for="logoInput" role="button" tabindex="0" class="ph-avatar-edit" title="Change logo" aria-label="Change logo">
                            <i class="fa-solid fa-camera"></i>
                        </label>
                    </div>
                    <div class="ph-id-who">
                        <h2 class="ph-id-name" id="heroName"><?= htmlspecialchars($merchant['stall_name']) ?></h2>
                        <p class="ph-id-no<?= empty($merchant['stall_id']) ? ' is-text' : '' ?>"><?= htmlspecialchars($stallTitle) ?></p>
                        <p class="ph-id-since">Member since <?= htmlspecialchars($memberSince) ?></p>
                    </div>
                </div>
            </div>

            <div class="ph-id-wallet">
                <div class="ph-id-top">
                    <div>
                        <span class="ph-id-k">Wallet balance</span>
                        <span class="ph-id-amount"><?= gjc_gc_amount($wallet['balance']) ?> <small>GC</small></span>
                        <span class="ph-id-k">&#8776; <?= gjc_money($wallet['balance']) ?> &middot; <a href="<?= MERCHANT_URL ?>/encash.php">Encash</a></span>
                    </div>
                    <?php if (!empty($merchant['stall_id'])): ?>
                        <a class="ph-id-edit" target="_blank" rel="noopener"
                           href="<?= BASE_URL ?>/stalls.php#stall-<?= rawurlencode((string) $merchant['stall_id']) ?>">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> View in directory
                        </a>
                    <?php endif; ?>
                </div>

                <dl class="ph-id-facts">
                    <div>
                        <dt>Status</dt>
                        <dd class="<?= $opStatus === 'active' ? '' : 'is-paused' ?>"><?= $opStatus === 'active' ? 'Open for business' : htmlspecialchars(ucwords(str_replace('_', ' ', $opStatus))) ?></dd>
                    </div>
                    <div>
                        <dt>Today's sales</dt>
                        <dd><?= gjc_gc_amount($salesToday['amount']) ?> GC <span>&middot; <?= $salesToday['orders'] ?> order<?= $salesToday['orders'] === 1 ? '' : 's' ?></span></dd>
                    </div>
                    <div>
                        <dt>Products</dt>
                        <dd><?= (int) $glance['products'] ?> <span><?= $glance['restricted'] > 0 ? '&middot; ' . (int) $glance['restricted'] . ' restricted' : '' ?></span></dd>
                    </div>
                    <div>
                        <dt>Monthly rent</dt>
                        <dd><?= $merchant['monthly_rate'] !== null ? gjc_gc_amount($merchant['monthly_rate']) . ' GC' : '&mdash;' ?> <span><?= $paymentDay > 0 ? '&middot; due every ' . $ordinal($paymentDay) : '' ?></span></dd>
                    </div>
                </dl>
            </div>
        </section>

        <?php if ($checksDone < $checksTotal): ?>
        <!-- ── Completeness nudge: disappears once everything is on file ── -->
        <section class="ph-card ph-nudge" aria-label="Profile completeness">
            <div class="ph-ring" style="--ph-pct: <?= $checksPct ?>%"><span><?= $checksPct ?>%</span></div>
            <div class="ph-grow">
                <span class="ph-t">Your stall profile is <?= $checksDone ?> of <?= $checksTotal ?> complete</span>
                <?php
                $needsLogo = !$profileChecks[0]['done'];
                $needsDocs = count(array_filter(array_slice($profileChecks, 3), static fn($c) => !$c['done'])) > 0;
                ?>
                <span class="ph-s"><?= match (true) {
                    $needsLogo && $needsDocs => 'A logo helps students find you, and a full set of permits keeps your stall in good standing.',
                    $needsLogo               => 'A logo helps students find your stall in the directory.',
                    $needsDocs               => 'A full set of permits keeps your stall in good standing with the finance office.',
                    default                  => 'The finance office uses these to reach you.',
                } ?></span>
            </div>
            <div class="ph-chips">
                <?php foreach ($profileChecks as $check): if ($check['done']) continue; ?>
                    <?php if ($check['href'] === null): ?>
                        <label for="logoInput" role="button" tabindex="0" class="ph-chip"><i class="fa-solid fa-plus"></i><?= htmlspecialchars($check['label']) ?></label>
                    <?php elseif (str_starts_with($check['href'], 'doc:')): ?>
                        <label for="docInput-<?= htmlspecialchars(substr($check['href'], 4)) ?>" role="button" tabindex="0" class="ph-chip"><i class="fa-solid fa-upload"></i><?= htmlspecialchars($check['label']) ?></label>
                    <?php elseif ($check['href'] === 'contact'): ?>
                        <button type="button" class="ph-chip border-0" data-bs-toggle="modal" data-bs-target="#contactModal"><i class="fa-solid fa-plus"></i><?= htmlspecialchars($check['label']) ?></button>
                    <?php else: ?>
                        <button type="button" class="ph-chip border-0" data-ph-open-tab="listing"><i class="fa-solid fa-plus"></i><?= htmlspecialchars($check['label']) ?></button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- One section at a time, like the student and parent profiles. -->
        <?php // Phones hide the tabs: the Settings menu lists every section, each with a back link. ?>
        <div class="ph-tabs d-none d-md-flex" role="tablist" aria-label="Profile sections">
            <?php foreach ($phTabs as $key => $label): ?>
            <button type="button" class="ph-tab<?= $key === $phTab ? ' active' : '' ?>" id="ph-tab-<?= $key ?>"
                    data-bs-toggle="tab" data-bs-target="#ph-pane-<?= $key ?>" role="tab"
                    aria-controls="ph-pane-<?= $key ?>" aria-selected="<?= $key === $phTab ? 'true' : 'false' ?>">
                <?= $label ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="tab-content">

        <div class="tab-pane<?= $phTab === 'listing' ? ' active' : '' ?>" id="ph-pane-listing" role="tabpanel" aria-labelledby="ph-tab-listing" tabindex="0">
        <button type="button" class="pf-back-link border-0 d-md-none" data-ph-open-tab="settings"><i class="fa-solid fa-arrow-left"></i> Back to Settings</button>
        <!-- ── The public listing: edit on the left, the real tile on the right ── -->
        <section class="ph-card bp-listing" id="listing">
            <div class="ph-card-head">
                <div>
                    <h3>Stall Directory listing</h3>
                    <p>What students and visitors see when they browse the stalls</p>
                </div>
            </div>

            <form id="profileForm" enctype="multipart/form-data" class="bp-edit" novalidate>
                <div class="bp-edit-fields">
                    <div class="bp-field">
                        <div class="bp-field-head">
                            <label class="form-label fw-semibold mb-0" for="stallNameInput">Display name</label>
                            <span class="bp-counter" id="nameCounter" aria-live="polite"></span>
                        </div>
                        <input type="text" class="form-control" name="stall_name" id="stallNameInput"
                               value="<?= htmlspecialchars($merchant['stall_name']) ?>" maxlength="255" required
                               aria-describedby="nameHint">
                        <div class="form-text" id="nameHint">Your business name as it appears on your stall's tile.</div>
                        <div class="bp-warn" id="nameCutWarn" hidden>
                            <i class="fa-solid fa-scissors"></i>
                            <span>Too long for the tile — it gets cut off, as the preview shows. A shorter name reads better.</span>
                        </div>
                    </div>

                    <div class="bp-field">
                        <span class="form-label fw-semibold d-block">Logo</span>
                        <div class="bp-logo-row">
                            <div class="bp-logo-thumb" id="logoThumb"
                                 <?= $logoUrl ? 'style="background-image:url(&quot;' . htmlspecialchars($logoUrl, ENT_QUOTES) . '&quot;)"' : '' ?>>
                                <span <?= $logoUrl ? 'hidden' : '' ?>><?= htmlspecialchars(mb_strtoupper(mb_substr($merchant['stall_name'], 0, 1))) ?></span>
                            </div>
                            <div class="bp-logo-actions">
                                <label class="merchant-view-btn mb-0" for="logoInput" role="button" tabindex="0" id="logoPickBtn">
                                    <i class="fa-solid fa-upload me-1"></i> <span><?= $logoUrl ? 'Replace logo' : 'Upload logo' ?></span>
                                </label>
                                <button type="button" class="bp-link-btn" id="logoRemoveBtn" <?= $logoUrl ? '' : 'hidden' ?>>Remove</button>
                            </div>
                        </div>
                        <input type="file" id="logoInput" name="logo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="d-none">
                        <input type="hidden" name="remove_logo" id="removeLogoInput" value="0">
                        <div class="form-text">
                            JPG or PNG, up to 5 MB. The logo fills the whole tile and is cropped to fit,
                            so keep the important part in the middle.
                        </div>
                    </div>

                    <div class="bp-actions">
                        <button type="submit" class="login-btn" id="profileSubmitBtn" disabled>Save changes</button>
                        <button type="button" class="bp-link-btn" id="profileResetBtn" hidden>Discard changes</button>
                        <span class="bp-dirty" id="dirtyNote" hidden>Unsaved changes</span>
                    </div>
                </div>

                <figure class="bp-preview" aria-label="Preview of your Stall Directory tile">
                    <figcaption>Preview</figcaption>
                    <div class="bp-tile<?= $logoUrl ? ' has-logo' : '' ?>" id="tilePreview"
                         <?= $logoUrl ? 'style="background-image:url(&quot;' . htmlspecialchars($logoUrl, ENT_QUOTES) . '&quot;)"' : '' ?>>
                        <div class="bp-tile-id"><?= htmlspecialchars((string) ($merchant['stall_id'] ?: '—')) ?></div>
                        <div class="bp-tile-name" id="tileName"><?= htmlspecialchars($merchant['stall_name']) ?></div>
                    </div>
                    <p class="bp-preview-note" id="previewNote">
                        <?= !empty($merchant['stall_id'])
                            ? 'Same size as on the directory (on a computer). Updates as you edit — nothing goes public until you save.'
                            : 'You have no stall assigned yet, so this tile is not on the directory.' ?>
                    </p>
                </figure>
            </form>
        </section>
        </div>

        <div class="tab-pane<?= $phTab === 'documents' ? ' active' : '' ?>" id="ph-pane-documents" role="tabpanel" aria-labelledby="ph-tab-documents" tabindex="0">
        <button type="button" class="pf-back-link border-0 d-md-none" data-ph-open-tab="settings"><i class="fa-solid fa-arrow-left"></i> Back to Settings</button>
        <!-- ── Compliance documents ─────────────────────────────────────── -->
        <?php $missingDocs = count(array_diff_key($complianceDocs, $docFiles)); ?>
        <section class="ph-card" id="documents">
            <div class="ph-card-head">
                <div>
                    <h3>Compliance documents</h3>
                    <p>Upload a clear photo or PDF of each one. The finance office sees it straight away.</p>
                </div>
                <?php if ($missingDocs > 0): ?>
                    <span class="ph-pill is-warn"><?= $missingDocs ?> missing</span>
                <?php else: ?>
                    <span class="ph-pill has-dot is-ok">All on file</span>
                <?php endif; ?>
            </div>

            <?php foreach ($complianceDocs as $key => [$label, $hint]): ?>
                <?php
                $file = $docFiles[$key] ?? null;
                $labelHtml = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                if ($file === null) {
                    $sub = 'Not submitted yet &middot; JPG, PNG or PDF up to 5 MB';
                } elseif ($file['source'] === 'upload') {
                    $sub = 'Uploaded by you' . (!empty($file['uploaded_at']) ? ' on ' . date('M j, Y', strtotime($file['uploaded_at'])) : '');
                } else {
                    $sub = 'Submitted with your stall application';
                }
                ?>
                <div class="ph-row">
                    <span class="ph-ic <?= $file ? '' : 'is-bad' ?>"><i class="fa-solid fa-file-lines"></i></span>
                    <span class="ph-grow">
                        <span class="ph-t"><?= $labelHtml ?></span>
                        <span class="ph-s"><?= $sub ?></span>
                    </span>
                    <?php if ($file): ?>
                        <a class="ph-link" target="_blank" rel="noopener"
                           href="<?= MERCHANT_URL ?>/doc.php?t=<?= urlencode($key) ?>"
                           aria-label="View <?= $labelHtml ?>">View</a>
                    <?php endif; ?>
                    <?php if (!$file): // Documents on file can't be replaced by the merchant. ?>
                    <label class="ph-mini-btn d-inline-flex align-items-center gap-1 mb-0"
                           for="docInput-<?= $key ?>" role="button" tabindex="0"
                           aria-label="Upload <?= $labelHtml ?>">
                        <i class="fa-solid fa-upload"></i>Upload
                    </label>
                    <input type="file" id="docInput-<?= $key ?>" class="d-none js-doc-input"
                           data-doc="<?= $key ?>" data-label="<?= $labelHtml ?>"
                           accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($application['contract_file'])): ?>
                <div class="ph-row">
                    <span class="ph-ic"><i class="fa-solid fa-file-signature"></i></span>
                    <span class="ph-grow">
                        <span class="ph-t">Signed lease contract</span>
                        <span class="ph-s"><?= $application['contract_ref']
                            ? 'Reference ' . htmlspecialchars($application['contract_ref'], ENT_QUOTES, 'UTF-8')
                            : 'Countersigned by the finance office' ?></span>
                    </span>
                    <a class="ph-link" target="_blank" rel="noopener" href="<?= MERCHANT_URL ?>/contract.php" aria-label="View signed lease contract">View</a>
                    <a class="ph-link" href="<?= MERCHANT_URL ?>/contract.php?dl=1" aria-label="Save signed lease contract"><i class="fa-solid fa-download"></i>Save</a>
                </div>
            <?php endif; ?>
        </section>
        </div>

        <div class="tab-pane<?= $phTab === 'details' ? ' active' : '' ?>" id="ph-pane-details" role="tabpanel" aria-labelledby="ph-tab-details" tabindex="0">
        <button type="button" class="pf-back-link border-0 d-md-none" data-ph-open-tab="settings"><i class="fa-solid fa-arrow-left"></i> Back to Settings</button>
        <div class="ph-grid">
        <div class="ph-col">

        <!-- ── Stall & tenancy ──────────────────────────────────────────── -->
        <section class="ph-card">
            <div class="ph-card-head">
                <div>
                    <h3>Stall &amp; tenancy</h3>
                    <p>The unit you lease and how it is recorded</p>
                </div>
                <a class="ph-link" href="<?= MERCHANT_URL ?>/rent.php">Rent schedule <i class="fa-solid fa-chevron-right"></i></a>
            </div>
            <div class="ph-kv"><span>Stall</span><div><?= profile_value($merchant['stall_id'] ? $stallTitle : '', 'Not yet assigned') ?></div></div>
            <div class="ph-kv"><span>Position on the map</span>
                <div><?= profile_value($merchant['row_label'] ? 'Row ' . $merchant['row_label'] . ', unit ' . (int) $merchant['col_number'] : '') ?></div>
            </div>
            <div class="ph-kv"><span>Floor area</span>
                <div><?= profile_value($merchant['area_sqm'] !== null ? rtrim(rtrim(number_format((float) $merchant['area_sqm'], 2), '0'), '.') . ' sqm' : '') ?></div>
            </div>
            <div class="ph-kv"><span>Monthly rent</span>
                <div><?= $merchant['monthly_rate'] !== null ? '<span class="gp-bp-value">' . gjc_gc_inline($merchant['monthly_rate']) . '</span>' : profile_value('') ?></div>
            </div>
            <div class="ph-kv"><span>Payment day</span>
                <div><?= profile_value($paymentDay > 0 ? 'Every ' . $ordinal($paymentDay) . ' of the month' : '') ?></div>
            </div>
            <div class="ph-kv"><span>Deposit collected</span>
                <div><?= isset($application['deposit_amount'])
                    ? '<span class="gp-bp-value">' . gjc_gc_inline($application['deposit_amount']) . '</span>'
                    : profile_value('', 'Not on file') ?></div>
            </div>
            <div class="ph-kv"><span>Advance collected</span>
                <div><?= isset($application['advance_amount'])
                    ? '<span class="gp-bp-value">' . gjc_gc_inline($application['advance_amount']) . '</span>'
                    : profile_value('', 'Not on file') ?></div>
            </div>
            <div class="ph-kv"><span>Tenant since</span>
                <div><?= profile_value(
                    !empty($application['rental_start_date'])
                        ? date('M j, Y', strtotime($application['rental_start_date']))
                        : ($merchant['tenant_since'] ? date('M j, Y', strtotime($merchant['tenant_since'])) : '')
                ) ?></div>
            </div>
        </section>

        </div>
        <div class="ph-col">

        <!-- ── Proprietor & contact ─────────────────────────────────────── -->
        <section class="ph-card" id="contact">
            <div class="ph-card-head">
                <div>
                    <h3>Proprietor &amp; contact</h3>
                    <p>How the finance office and your team reach you</p>
                </div>
                <button type="button" class="ph-link" data-bs-toggle="modal" data-bs-target="#contactModal"><i class="fa-solid fa-pen"></i>Edit</button>
            </div>
            <div class="ph-kv"><span>Registered name</span><div><?= profile_value($fullName) ?></div></div>
            <div class="ph-kv"><span>Business name</span><div><?= profile_value($application['business_name'] ?? $merchant['stall_name']) ?></div></div>
            <div class="ph-kv"><span>Mobile number</span><div><?= $ownerPhone !== '' ? profile_value($ownerPhone) : '<span class="gp-bp-missing">Not set</span> <button type="button" class="ph-link" data-bs-toggle="modal" data-bs-target="#contactModal">Add</button>' ?></div></div>
            <div class="ph-kv"><span>Email</span><div><?= profile_value($merchant['email'] ?: ($application['email'] ?? '')) ?></div></div>
            <div class="ph-kv"><span>Home address</span><div><?= $address !== '' ? profile_value($address) : '<span class="gp-bp-missing">Not set</span> <button type="button" class="ph-link" data-bs-toggle="modal" data-bs-target="#contactModal">Add</button>' ?></div></div>
            <div class="ph-kv"><span>Account</span>
                <div>
                    <?php $acct = strtolower((string) ($merchant['account_status'] ?? '')); ?>
                    <span class="ph-pill has-dot <?= $acct === 'active' ? 'is-ok' : 'is-bad' ?>"><?= htmlspecialchars(ucfirst($acct) ?: 'Unknown') ?></span>
                </div>
            </div>
            <div class="ph-kv"><span>Member since</span>
                <div><?= profile_value($merchant['account_created'] ? date('M j, Y', strtotime($merchant['account_created'])) : '') ?></div>
            </div>
            <div class="ph-note">
                <i class="fa-solid fa-lock"></i>
                <span>Your registered name, business name and email are set by the finance office. Ask them if those need changing.</span>
            </div>
        </section>

        <!-- ── Team ────────────────────────────────────────────────────── -->
        <section class="ph-card">
            <div class="ph-card-head">
                <div>
                    <h3>Your team</h3>
                    <p><?= $team
                        ? count($team) . ' staff account' . (count($team) === 1 ? '' : 's') . ' can sign in to your POS'
                        : 'Staff you add can sign in to your POS' ?></p>
                </div>
                <a class="ph-link" href="<?= MERCHANT_URL ?>/staff.php">Manage <i class="fa-solid fa-chevron-right"></i></a>
            </div>
            <?php if ($team): ?>
                <?php foreach ($team as $member):
                    $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?: 'Staff member'; ?>
                    <div class="ph-row">
                        <?php $memberPhoto = trim((string) ($member['profile_img'] ?? '')); ?>
                        <span class="ph-ini">
                            <?php if ($memberPhoto !== ''): ?>
                                <img src="<?= htmlspecialchars(BASE_URL . '/' . ltrim($memberPhoto, '/')) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <?= htmlspecialchars(mb_strtoupper(mb_substr($memberName, 0, 1))) ?>
                            <?php endif; ?>
                        </span>
                        <span class="ph-grow">
                            <span class="ph-t"><?= htmlspecialchars($memberName) ?></span>
                            <span class="ph-s"><?= htmlspecialchars(trim((string) ($member['position'] ?? '')) ?: 'Merchant Staff') ?></span>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="ph-note">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>No staff yet. Add cashiers from <a href="<?= MERCHANT_URL ?>/staff.php">Staff</a> so they can ring up sales without your login.</span>
                </div>
            <?php endif; ?>
        </section>

        </div><!-- /.ph-col -->
        </div><!-- /.ph-grid -->
        </div>

        <div class="tab-pane<?= $phTab === 'password' ? ' active' : '' ?>" id="ph-pane-password" role="tabpanel" aria-labelledby="ph-tab-password" tabindex="0">
        <button type="button" class="pf-back-link border-0 d-md-none" data-ph-open-tab="settings"><i class="fa-solid fa-arrow-left"></i> Back to Settings</button>
        <section class="ph-card">
            <div class="ph-card-head">
                <div>
                    <h3>Password &amp; Security</h3>
                    <p>Keep your account secure by updating your password regularly.</p>
                </div>
            </div>
            <form method="POST" class="pf-form px-4 pb-4">
                <input type="hidden" name="profile_action" value="password">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="pf-field">
                    <label>Current Password</label>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </div>
                <div class="pf-form-grid">
                    <div class="pf-field">
                        <label>New Password</label>
                        <input type="password" name="new_password" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="pf-field">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                    </div>
                </div>
                <button type="submit" class="pf-btn">
                    <i class="fa-solid fa-shield-halved me-1"></i> Update Password
                </button>
            </form>
        </section>
        </div>

        <div class="tab-pane" id="ph-pane-settings" role="tabpanel" aria-labelledby="ph-tab-settings" tabindex="0">
        <!-- Settings: same menu as student and parent profile.php. -->
        <section class="ph-card">
            <div class="ph-card-head">
                <div><h3>Settings</h3></div>
            </div>
            <nav class="ph-menu" aria-label="Profile settings">
                <button type="button" class="ph-menu-item" data-ph-open-tab="listing">
                    <span class="ph-ic"><i class="fa-solid fa-store"></i></span>
                    <span class="ph-grow">
                        <span class="ph-t">Directory listing</span>
                        <span class="ph-s">Display name and logo students see</span>
                    </span>
                    <i class="fa-solid fa-chevron-right ph-chev"></i>
                </button>
                <button type="button" class="ph-menu-item" data-ph-open-tab="documents">
                    <span class="ph-ic"><i class="fa-solid fa-file-lines"></i></span>
                    <span class="ph-grow">
                        <span class="ph-t">Compliance documents</span>
                        <span class="ph-s">Permits, clearance and your lease contract</span>
                    </span>
                    <i class="fa-solid fa-chevron-right ph-chev"></i>
                </button>
                <button type="button" class="ph-menu-item" data-ph-open-tab="details">
                    <span class="ph-ic"><i class="fa-solid fa-user-pen"></i></span>
                    <span class="ph-grow">
                        <span class="ph-t">Stall &amp; contact</span>
                        <span class="ph-s">Tenancy, mobile number, address and team</span>
                    </span>
                    <i class="fa-solid fa-chevron-right ph-chev"></i>
                </button>
                <button type="button" class="ph-menu-item" data-ph-open-tab="password">
                    <span class="ph-ic"><i class="fa-solid fa-shield-halved"></i></span>
                    <span class="ph-grow">
                        <span class="ph-t">Password &amp; Security</span>
                        <span class="ph-s">Change your login password</span>
                    </span>
                    <i class="fa-solid fa-chevron-right ph-chev"></i>
                </button>
                <a href="<?= MERCHANT_URL ?>/rent.php" class="ph-menu-item">
                    <span class="ph-ic"><i class="fa-solid fa-file-invoice"></i></span>
                    <span class="ph-grow">
                        <span class="ph-t">Rent &amp; Lease</span>
                        <span class="ph-s">Your rent schedule and payments</span>
                    </span>
                    <i class="fa-solid fa-chevron-right ph-chev"></i>
                </a>
                <button type="button" class="ph-menu-item" data-bs-toggle="modal" data-bs-target="#phHelpModal">
                    <span class="ph-ic"><i class="fa-solid fa-circle-question"></i></span>
                    <span class="ph-grow">
                        <span class="ph-t">Help &amp; FAQs</span>
                        <span class="ph-s">GenCoin, encashment, staff and passwords</span>
                    </span>
                    <i class="fa-solid fa-chevron-right ph-chev"></i>
                </button>
            </nav>
        </section>
        </div>

        </div>

        <!-- Log out stays visible whichever tab is open (on phones the sidebar is hidden). -->
        <section class="ph-card">
            <nav class="ph-menu" aria-label="Log out">
                <button type="button" class="ph-menu-item is-danger" onclick="openLogoutModal(event);">
                    <span class="ph-ic is-bad"><i class="fa-solid fa-arrow-right-from-bracket"></i></span>
                    <span class="ph-grow"><span class="ph-t">Log out</span></span>
                </button>
            </nav>
        </section>

        <div class="ph-foot">GenPay v<?= htmlspecialchars(GJC_APP_VERSION) ?></div>

        </div>

        <!-- Help & FAQs (a bottom sheet on phones), same component as student/profile.php -->
        <div class="modal fade ph-sheet" id="phHelpModal" tabindex="-1" aria-labelledby="phHelpTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="phHelpTitle">Help &amp; FAQs</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <details class="ph-faq" open>
                            <summary>What is GenCoin (GC)?</summary>
                            <p>GenCoin is GenPay's in-app currency, fixed at &#8369;10 = 1 GC. Students pay with it at your stall, and every sale goes straight to your stall wallet.</p>
                        </details>
                        <details class="ph-faq">
                            <summary>How do I turn my sales into cash?</summary>
                            <p>Go to <strong>Encash</strong> and request a payout. The finance office releases the cash and records it against your wallet.</p>
                        </details>
                        <details class="ph-faq">
                            <summary>How do I add cashiers?</summary>
                            <p>Open <strong>Staff</strong> and add an account for each cashier. They sign in with their own login and can ring up sales on your POS.</p>
                        </details>
                        <details class="ph-faq">
                            <summary>How do I change my name, business name or email?</summary>
                            <p>Those are set by the finance office. You can change your display name and logo under <strong>Directory listing</strong>, and your mobile number and address under <strong>Stall &amp; contact</strong>.</p>
                        </details>
                        <details class="ph-faq">
                            <summary>I forgot my password. What do I do?</summary>
                            <p>Visit the finance office with a valid ID and request a password reset. If you're already logged in, use <strong>Password &amp; Security</strong> in Settings instead.</p>
                        </details>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Edit contact ───────────────────────────────────────────── -->
        <div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content gp-modal" id="contactForm" novalidate>
                    <div class="modal-header">
                        <h2 class="modal-title" id="contactModalTitle"><i class="fa-solid fa-address-book"></i>Edit contact details</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_contact">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="contactPhone">Mobile number</label>
                            <input type="tel" class="form-control" id="contactPhone" name="contact_number"
                                   value="<?= htmlspecialchars($ownerPhone) ?>" maxlength="13" placeholder="09XXXXXXXXX"
                                   inputmode="numeric" autocomplete="tel">
                        </div>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label" for="contactBarangay">Barangay</label>
                                <input type="text" class="form-control" id="contactBarangay" name="address_barangay" maxlength="120"
                                       value="<?= htmlspecialchars((string) ($merchant['address_barangay'] ?? '')) ?>" autocomplete="address-line2">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="contactCity">City / municipality</label>
                                <input type="text" class="form-control" id="contactCity" name="address_city" maxlength="120"
                                       value="<?= htmlspecialchars((string) ($merchant['address_city'] ?? '')) ?>" autocomplete="address-level2">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="contactProvince">Province</label>
                                <input type="text" class="form-control" id="contactProvince" name="address_province" maxlength="120"
                                       value="<?= htmlspecialchars((string) ($merchant['address_province'] ?? '')) ?>" autocomplete="address-level1">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="maintenance-btn muted" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="maintenance-btn primary" id="contactSaveBtn">Save</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const form = document.getElementById('profileForm');
    if (!form) return;

    const PROFILE_API = '<?= MERCHANT_URL ?>/api/profile.php';
    const MAX_BYTES   = 5 * 1024 * 1024;

    const nameInput = document.getElementById('stallNameInput');
    const counter   = document.getElementById('nameCounter');
    const cutWarn   = document.getElementById('nameCutWarn');
    const fileInput = document.getElementById('logoInput');
    const removeIn  = document.getElementById('removeLogoInput');
    const pickBtn   = document.getElementById('logoPickBtn');
    const removeBtn = document.getElementById('logoRemoveBtn');
    const thumb     = document.getElementById('logoThumb');
    const tile      = document.getElementById('tilePreview');
    const tileName  = document.getElementById('tileName');
    const saveBtn   = document.getElementById('profileSubmitBtn');
    const resetBtn  = document.getElementById('profileResetBtn');
    const dirtyNote = document.getElementById('dirtyNote');
    const heroLogo  = document.getElementById('heroLogo');
    const heroName  = document.getElementById('heroName');

    // What is live right now; the form is "dirty" whenever it differs.
    const saved = {
        name: nameInput.value,
        logo: <?= json_encode($logoUrl) ?>,
    };
    let pendingLogo = null; // data: URL of a picked-but-unsaved file
    let dirty = false;

    function shownLogo() {
        if (pendingLogo) return pendingLogo;
        return removeIn.value === '1' ? null : saved.logo;
    }

    function paintLogo() {
        const url = shownLogo();
        const bg  = url ? 'url("' + url.replace(/"/g, '%22') + '")' : '';
        tile.style.backgroundImage  = bg;
        thumb.style.backgroundImage = bg;
        heroLogo.style.backgroundImage = bg;
        heroLogo.querySelector('span').hidden = !!url;
        tile.classList.toggle('has-logo', !!url);
        const initial = thumb.querySelector('span');
        initial.hidden = !!url;
        initial.textContent = (nameInput.value.trim()[0] || '?').toUpperCase();
        pickBtn.querySelector('span').textContent = url ? 'Replace logo' : 'Upload logo';
        removeBtn.hidden = !url;
    }

    function refresh() {
        const name = nameInput.value;
        tileName.textContent = name.trim() || 'Your business name';
        tileName.classList.toggle('is-placeholder', !name.trim());
        heroName.textContent = name.trim() || saved.name;
        counter.textContent = name.length + ' / 255';
        // Measured on the preview tile itself, which is the directory tile's real width.
        cutWarn.hidden = !(name.trim() && tileName.scrollWidth > tileName.clientWidth + 1);

        dirty = name !== saved.name || pendingLogo !== null || removeIn.value === '1';
        saveBtn.disabled  = !dirty || !name.trim();
        resetBtn.hidden   = !dirty;
        dirtyNote.hidden  = !dirty;
        paintLogo();
    }

    nameInput.addEventListener('input', refresh);

    // The label opens the picker on click; make Enter/Space do the same.
    pickBtn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
    });

    fileInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png'].includes(file.type)) {
            this.value = '';
            gpToast('That file is not a JPG or PNG image.', false);
            return;
        }
        if (file.size > MAX_BYTES) {
            this.value = '';
            gpToast('That image is ' + (file.size / 1048576).toFixed(1) + ' MB. The limit is 5 MB.', false);
            return;
        }
        const reader = new FileReader();
        reader.onload = e => {
            pendingLogo = e.target.result;
            removeIn.value = '0';
            refresh();
            // The hero camera opens this same picker; bring the Save button into view.
            bootstrap.Tab.getOrCreateInstance(document.getElementById('ph-tab-listing')).show();
            document.getElementById('listing').scrollIntoView({ behavior: 'smooth', block: 'start' });
        };
        reader.readAsDataURL(file);
    });

    removeBtn.addEventListener('click', function () {
        if (pendingLogo) {
            // Only un-picks the new file; the saved logo comes back.
            pendingLogo = null;
            fileInput.value = '';
        } else {
            removeIn.value = '1';
        }
        refresh();
    });

    resetBtn.addEventListener('click', function () {
        nameInput.value = saved.name;
        pendingLogo = null;
        fileInput.value = '';
        removeIn.value = '0';
        refresh();
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        if (saveBtn.disabled) return;

        if (removeIn.value === '1' && !pendingLogo) {
            const ok = await gpConfirm({
                title: 'Remove your logo?',
                body: 'Your tile on the Stall Directory will show just your stall number and name until you upload a new one.',
                confirmLabel: 'Remove logo',
                danger: true,
            });
            if (!ok) return;
        }

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        const d = await gpFetchJson(PROFILE_API, new FormData(form));
        saveBtn.textContent = 'Save changes';

        if (d.success) {
            saved.name  = d.stall_name ?? nameInput.value;
            saved.logo  = d.logo_url ?? null;
            nameInput.value = saved.name;
            pendingLogo = null;
            fileInput.value = '';
            removeIn.value  = '0';
        }
        gpToast(d.message, !!d.success);
        refresh();
    });

    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    refresh();
})();
</script>
    <script>
    // Tabs, as on student and parent profile.php. Settings shortcuts switch tabs.
    function showPhTab(key) {
        const tab = document.getElementById('ph-tab-' + key);
        if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
        return !!tab;
    }
    document.querySelectorAll('[data-ph-open-tab]').forEach(function (item) {
        item.addEventListener('click', function () {
            if (!showPhTab(item.getAttribute('data-ph-open-tab'))) return;
            // The tabs are hidden on phones, so scroll to the panes.
            document.querySelector('.tab-content').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    // After an upload or contact save, reopen the tab it came from.
    function reloadOn(key) {
        history.replaceState(null, '', location.pathname + '#' + key);
        location.reload();
    }
    <?php if (!isset($_POST['profile_action'])): ?>
    if (!(location.hash && showPhTab(location.hash.slice(1))) && window.matchMedia('(max-width: 767.98px)').matches) {
        // Phones open on Settings unless a form was just submitted.
        showPhTab('settings');
    }
    <?php endif; ?>

    // Keyboard access for the <label> buttons that open a hidden file picker.
    document.querySelectorAll('label[role="button"][for]:not(#logoPickBtn)').forEach(function (lbl) {
        lbl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); document.getElementById(lbl.htmlFor).click(); }
        });
    });

    (function () {
        const API  = '<?= MERCHANT_URL ?>/api/business_profile.php';
        const CSRF = <?= json_encode($csrfToken ?? '') ?>;
        const MAX_BYTES = 5 * 1024 * 1024;

        // Compliance documents: picking a file uploads it straight away.
        document.querySelectorAll('.js-doc-input').forEach(function (input) {
            input.addEventListener('change', async function () {
                const file = this.files[0];
                if (!file) return;
                const label = this.dataset.label;
                if (!['image/jpeg', 'image/png', 'application/pdf'].includes(file.type)) {
                    this.value = '';
                    gpToast(label + ' must be a JPG, PNG or PDF file.', false);
                    return;
                }
                if (file.size > MAX_BYTES) {
                    this.value = '';
                    gpToast('That file is ' + (file.size / 1048576).toFixed(1) + ' MB. The limit is 5 MB.', false);
                    return;
                }
                const fd = new FormData();
                fd.append('action', 'upload_document');
                fd.append('doc_key', this.dataset.doc);
                fd.append('csrf_token', CSRF);
                fd.append('file', file);
                gpToast('Uploading ' + label.toLowerCase() + '…', true);
                const d = await gpFetchJson(API, fd);
                this.value = '';
                gpToast(d.message, !!d.success);
                if (d.success) setTimeout(() => reloadOn('documents'), 900);
            });
        });

        const form = document.getElementById('contactForm');
        if (form) {
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const btn = document.getElementById('contactSaveBtn');
                btn.disabled = true;
                btn.textContent = 'Saving…';
                const d = await gpFetchJson(API, new FormData(form));
                btn.disabled = false;
                btn.textContent = 'Save';
                gpToast(d.message, !!d.success);
                if (d.success) {
                    bootstrap.Modal.getInstance(document.getElementById('contactModal'))?.hide();
                    setTimeout(() => reloadOn('details'), 900);
                }
            });
        }
    })();
    </script>
<?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
<?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>
</html>
