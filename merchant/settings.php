<?php
// ============================================================
//  merchant/settings.php
//  Business Profile - lets the merchant admin edit the display name
//  and logo that appear publicly on the Stall Directory (stalls.php).
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['merchant']);
if (gjc_is_merchant_staff()) {
    header('Location: ' . DASHBOARD_URL);
    exit;
}

$currentUser = gjc_current_user($db);
$userId = (int) $currentUser['id'];

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

// ── The application record, where the richer detail lives ────────────────
// Merchants onboarded before the application workflow have no row here, so
// every field below has to tolerate being absent rather than assumed present.
$application = null;
if (gjc_table_exists($db, 'stall_applications')) {
    $appStmt = $db->prepare(
        "SELECT business_name, proprietor_name, contact_number, email,
                street, barangay, city, province, sex,
                business_permit, sanitary_permit, clearance, gjc_requirements,
                deposit_amount, advance_amount, rental_start_date, payment_schedule_day,
                contract_file, contract_ref, awarded_at
           FROM stall_applications
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

if (in_array('merchant_owner_id', gjc_table_columns($db, 'users'), true)) {
    gjc_ensure_staff_position_schema($db);
    $q = $db->prepare("SELECT COUNT(*) FROM users WHERE merchant_owner_id = ? AND staff_removed_at IS NULL");
    $q->execute([$userId]);
    $glance['staff'] = (int) $q->fetchColumn();
}

/**
 * users.contact_number is a BIGINT, so a Philippine mobile stored as
 * 09614708398 comes back as 9614708398 with the leading zero eaten. Put it
 * back rather than showing the merchant a number they cannot dial.
 */
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

$address = $application
    ? trim(implode(', ', array_filter([
        $application['street'] ?? '',
        $application['barangay'] ?? '',
        $application['city'] ?? '',
        $application['province'] ?? '',
    ])))
    : '';

$complianceDocs = [
    'business_permit'  => ['Business permit', 'Mayor or business permit for the stall'],
    'sanitary_permit'  => ['Sanitary permit', 'Health and sanitation clearance'],
    'clearance'        => ['Barangay clearance', 'Clearance from your barangay'],
    'gjc_requirements' => ['GJC requirements', 'School-specific documents'],
];

// "Stall B1" already names the unit, so don't print the code twice.
$stallTitle = 'No stall assigned yet';
if ($merchant && !empty($merchant['stall_id'])) {
    $stallTitle = trim((string) $merchant['stall_label']) !== '' ? (string) $merchant['stall_label'] : 'Stall ' . $merchant['stall_id'];
}

$currentPage = 'settings';
$logoUrl = $merchant && $merchant['profile_img'] ? BASE_URL . '/' . $merchant['profile_img'] : null;
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
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=62">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant_settings.css?v=11">
</head>
<body class="gp-theme">
<div class="merchant-layout">
    <?php require __DIR__ . '/../includes/partials/sidebar_merchant_admin.php'; ?>

    <main class="merchant-main">
        <?php
        $topbarTitle = 'Business Profile';
        $topbarSubtitle = 'Your public listing, and the details the finance office holds for your stall.';
        require __DIR__ . '/../includes/partials/topbar_merchant.php';
        ?>

        <?php if (!$merchant): ?>
        <section class="merchant-premium-panel">
            <p class="text-muted mb-0">No merchant record is linked to this account yet.</p>
        </section>
        <?php else: ?>
        <!-- ── The public listing: edit on the left, the real tile on the right ── -->
        <section class="merchant-premium-panel bp-listing">
            <div class="merchant-panel-header d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h3>Stall Directory listing</h3>
                    <p><?= htmlspecialchars($stallTitle) ?> &middot; what students and visitors see when they browse the stalls.</p>
                </div>
                <?php if (!empty($merchant['stall_id'])): ?>
                    <a class="merchant-view-btn" target="_blank" rel="noopener"
                       href="<?= BASE_URL ?>/stalls.php#stall-<?= rawurlencode((string) $merchant['stall_id']) ?>">
                        Open the directory <i class="fa-solid fa-arrow-up-right-from-square ms-1"></i>
                    </a>
                <?php endif; ?>
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
        <?php endif; ?>

        <?php if ($merchant): ?>

        <!-- ── Business at a glance ─────────────────────────────────────── -->
        <section class="merchant-premium-panel mt-4">
            <div class="merchant-panel-header">
                <div>
                    <h3><i class="fa-solid fa-chart-simple me-2"></i>Business at a glance</h3>
                    <p>A summary of what is on your stall right now.</p>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="merchant-metric-card h-100">
                        <span>Products</span>
                        <h2 style="font-size:1.15rem"><?= (int) $glance['products'] ?></h2>
                        <p><?= $glance['restricted'] > 0
                            ? (int) $glance['restricted'] . ' restricted by the school'
                            : 'None restricted' ?></p>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="merchant-metric-card h-100">
                        <span>Staff accounts</span>
                        <h2 style="font-size:1.15rem"><?= (int) $glance['staff'] ?></h2>
                        <p>Can sign in to your POS</p>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="merchant-metric-card h-100">
                        <span>Wallet balance</span>
                        <h2 style="font-size:1.15rem"><?= gjc_money($wallet['balance']) ?></h2>
                        <p>Available to encash</p>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="merchant-metric-card h-100">
                        <span>Monthly rent</span>
                        <h2 style="font-size:1.15rem">
                            <?= $merchant['monthly_rate'] !== null ? gjc_money($merchant['monthly_rate']) : '&mdash;' ?>
                        </h2>
                        <p><a href="<?= MERCHANT_URL ?>/rent.php">See rent schedule</a></p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Proprietor & contact ─────────────────────────────────────── -->
        <section class="merchant-premium-panel mt-4">
            <div class="merchant-panel-header">
                <div>
                    <h3><i class="fa-solid fa-id-card me-2"></i>Proprietor &amp; contact</h3>
                    <p>Held by the finance office. Ask them to update anything that is wrong or missing.</p>
                </div>
            </div>

            <dl class="gp-bp-list">
                <div><dt>Registered name</dt><dd><?= profile_value($fullName) ?></dd></div>
                <div><dt>Business name</dt><dd><?= profile_value($application['business_name'] ?? $merchant['stall_name']) ?></dd></div>
                <div><dt>Mobile number</dt><dd><?= profile_value(profile_phone($merchant['contact_number'] ?: ($application['contact_number'] ?? ''))) ?></dd></div>
                <div><dt>Email</dt><dd><?= profile_value($merchant['email'] ?: ($application['email'] ?? '')) ?></dd></div>
                <div class="gp-bp-wide"><dt>Home address</dt><dd><?= profile_value($address, 'Not on file — collected with a stall application') ?></dd></div>
                <div><dt>Account status</dt>
                    <dd>
                        <?php $acct = strtolower((string) ($merchant['account_status'] ?? '')); ?>
                        <span class="gp-bp-chip <?= $acct === 'active' ? 'is-ok' : 'is-late' ?>">
                            <?= profile_value(ucfirst($acct)) ?>
                        </span>
                    </dd>
                </div>
                <div><dt>Member since</dt>
                    <dd><?= profile_value($merchant['account_created'] ? date('M j, Y', strtotime($merchant['account_created'])) : '') ?></dd>
                </div>
            </dl>
        </section>

        <!-- ── Stall & tenancy ──────────────────────────────────────────── -->
        <section class="merchant-premium-panel mt-4">
            <div class="merchant-panel-header">
                <div>
                    <h3><i class="fa-solid fa-store me-2"></i>Stall &amp; tenancy</h3>
                    <p>The unit you lease and how it is recorded in the stall map.</p>
                </div>
            </div>

            <dl class="gp-bp-list">
                <div><dt>Stall number</dt><dd><?= profile_value($merchant['stall_id'], 'Not yet assigned') ?></dd></div>
                <div><dt>Stall label</dt><dd><?= profile_value($merchant['stall_label']) ?></dd></div>
                <div><dt>Position on the map</dt>
                    <dd><?= profile_value($merchant['row_label'] ? 'Row ' . $merchant['row_label'] . ', unit ' . (int) $merchant['col_number'] : '') ?></dd>
                </div>
                <div><dt>Floor area</dt>
                    <dd><?= profile_value($merchant['area_sqm'] !== null ? rtrim(rtrim(number_format((float) $merchant['area_sqm'], 2), '0'), '.') . ' sqm' : '') ?></dd>
                </div>
                <div><dt>Operational status</dt>
                    <dd>
                        <?php $op = (string) ($merchant['operational_status'] ?? 'active'); ?>
                        <span class="gp-bp-chip <?= $op === 'active' ? 'is-ok' : 'is-late' ?>">
                            <?= profile_value(ucwords(str_replace('_', ' ', $op))) ?>
                        </span>
                    </dd>
                </div>
                <div><dt>Tenant since</dt>
                    <dd><?= profile_value(
                        !empty($application['rental_start_date'])
                            ? date('M j, Y', strtotime($application['rental_start_date']))
                            : ($merchant['tenant_since'] ? date('M j, Y', strtotime($merchant['tenant_since'])) : '')
                    ) ?></dd>
                </div>
                <div><dt>Deposit collected</dt>
                    <dd><?= isset($application['deposit_amount'])
                        ? '<span class="gp-bp-value">' . gjc_money($application['deposit_amount']) . '</span>'
                        : profile_value('', 'Not on file — collected on award') ?></dd>
                </div>
                <div><dt>Advance collected</dt>
                    <dd><?= isset($application['advance_amount'])
                        ? '<span class="gp-bp-value">' . gjc_money($application['advance_amount']) . '</span>'
                        : profile_value('', 'Not on file — collected on award') ?></dd>
                </div>
            </dl>
        </section>

        <!-- ── Compliance documents ─────────────────────────────────────── -->
        <section class="merchant-premium-panel mt-4">
            <div class="merchant-panel-header">
                <div>
                    <h3><i class="fa-solid fa-folder-open me-2"></i>Compliance documents</h3>
                    <p>The permits and clearances the finance office holds for your stall.</p>
                </div>
            </div>

            <?php if (!$application): ?>
                <div class="gp-bp-empty">
                    <i class="fa-solid fa-folder-open"></i>
                    <strong>No documents on file.</strong>
                    <p>
                        Your stall was set up directly by the finance office rather than through a
                        stall application, so no permits were captured. Bring your business permit,
                        sanitary permit and barangay clearance to the finance office to have them
                        added to your record.
                    </p>
                </div>
            <?php else: ?>
                <div class="gp-bp-docs">
                    <?php foreach ($complianceDocs as $key => [$label, $hint]): ?>
                        <?php $onFile = !empty($application[$key]); ?>
                        <div class="gp-bp-doc <?= $onFile ? '' : 'is-missing' ?>">
                            <div class="gp-bp-doc-body">
                                <strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php if ($onFile): ?>
                                <div class="gp-bp-doc-actions">
                                    <a class="merchant-view-btn" target="_blank" rel="noopener"
                                       href="<?= MERCHANT_URL ?>/doc.php?t=<?= urlencode($key) ?>">
                                        <i class="fa-solid fa-eye me-1"></i> View
                                    </a>
                                    <a class="merchant-view-btn" href="<?= MERCHANT_URL ?>/doc.php?t=<?= urlencode($key) ?>&amp;dl=1">
                                        <i class="fa-solid fa-download me-1"></i> Save
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="gp-bp-chip is-late">Not submitted</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($application['contract_file'])): ?>
                        <div class="gp-bp-doc">
                            <div class="gp-bp-doc-body">
                                <strong>Signed lease contract</strong>
                                <span><?= $application['contract_ref']
                                    ? 'Reference ' . htmlspecialchars($application['contract_ref'], ENT_QUOTES, 'UTF-8')
                                    : 'Countersigned by the finance office' ?></span>
                            </div>
                            <div class="gp-bp-doc-actions">
                                <a class="merchant-view-btn" target="_blank" rel="noopener" href="<?= MERCHANT_URL ?>/contract.php">
                                    <i class="fa-solid fa-eye me-1"></i> View
                                </a>
                                <a class="merchant-view-btn" href="<?= MERCHANT_URL ?>/contract.php?dl=1">
                                    <i class="fa-solid fa-download me-1"></i> Save
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

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
<?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
<?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>
</html>
