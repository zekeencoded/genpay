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
    $q = $db->prepare("SELECT COUNT(*) FROM users WHERE merchant_owner_id = ?");
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

$currentPage = 'settings';
$logoUrl = $merchant && $merchant['profile_img'] ? BASE_URL . '/' . $merchant['profile_img'] : null;

$walletDisplayName = $merchant['stall_name'] ?? $currentUser['name'];
$walletQrPayload = json_encode([
    'type' => 'merchant_wallet',
    'merchant_wallet_id' => $wallet['id'],
    'merchant_user_id' => $userId,
    'merchant' => $walletDisplayName,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$walletQrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&ecc=H&margin=12&data=' . rawurlencode($walletQrPayload);
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
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=53">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=30">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant_settings.css?v=8">
</head>
<body class="gp-theme">
<div class="merchant-layout">
    <?php require __DIR__ . '/../includes/partials/sidebar_merchant_admin.php'; ?>

    <main class="merchant-main">
        <?php
        $topbarTitle = 'Business Profile';
        $topbarSubtitle = "This display name and logo appear publicly on the Stall Directory.";
        require __DIR__ . '/../includes/partials/topbar_merchant.php';
        ?>

        <?php if (!$merchant): ?>
        <section class="merchant-premium-panel">
            <p class="text-muted mb-0">No merchant record is linked to this account yet.</p>
        </section>
        <?php else: ?>
        <section class="merchant-premium-panel">
            <div class="merchant-panel-header">
                <div>
                    <h3>Stall Directory Listing</h3>
                    <p>Stall <?= gjc_e($merchant['stall_id'] ?? 'Not yet assigned') ?><?= $merchant['stall_label'] ? ' - ' . gjc_e($merchant['stall_label']) : '' ?></p>
                </div>
            </div>

            <form id="profileForm" enctype="multipart/form-data" class="mt-3">
                <div class="row g-4 align-items-start">
                    <div class="col-12 col-md-3 text-center">
                        <?php if ($logoUrl): ?>
                        <img id="logoPreview" class="profile-logo-preview" src="<?= htmlspecialchars($logoUrl) ?>" alt="Current logo">
                        <?php else: ?>
                        <div id="logoPreview" class="profile-logo-fallback"><?= htmlspecialchars(mb_substr($merchant['stall_name'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <label class="btn btn-outline-secondary btn-sm mt-3 w-100" for="logoInput">Change Logo</label>
                        <input type="file" id="logoInput" name="logo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="d-none">
                        <div class="form-text">JPG or PNG, max 5 MB.</div>
                    </div>
                    <div class="col-12 col-md-9">
                        <label class="form-label fw-semibold">Display Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="stall_name" id="stallNameInput"
                               value="<?= htmlspecialchars($merchant['stall_name']) ?>" maxlength="255" required>
                        <div class="form-text">Shown as your company name on the public Stall Directory.</div>

                        <div id="profileMsg" class="mt-3"></div>
                        <button type="submit" class="login-btn mt-3" id="profileSubmitBtn">Save Changes</button>
                    </div>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($merchant): ?>

        <!-- ── Business at a glance ─────────────────────────────────────── -->
        <section class="merchant-premium-panel mt-4">
            <div class="merchant-panel-header">
                <div>
                    <h3><i class="fa-solid fa-chart-simple" style="margin-right:6px"></i>Business at a glance</h3>
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
                    <h3><i class="fa-solid fa-id-card" style="margin-right:6px"></i>Proprietor &amp; contact</h3>
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
                    <h3><i class="fa-solid fa-store" style="margin-right:6px"></i>Stall &amp; tenancy</h3>
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
                    <h3><i class="fa-solid fa-folder-open" style="margin-right:6px"></i>Compliance documents</h3>
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

        <section class="merchant-premium-panel mt-4" id="walletQrPanel">
            <div class="merchant-panel-header d-flex justify-content-between align-items-center">
                <div>
                    <h3>Shop Wallet QR</h3>
                    <p>Print this once and tape it to your cardboard menu. Students scan it to pay for their whole cart &mdash; it never expires and carries no fixed amount.</p>
                </div>
                <button type="button" class="merchant-view-btn" onclick="window.print()">
                    <i class="fa-solid fa-print"></i> Print
                </button>
            </div>

            <div class="wallet-qr-print-card">
                <img src="<?= htmlspecialchars($walletQrImageUrl) ?>" alt="Shop Wallet QR" class="wallet-qr-image">
                <div class="wallet-qr-caption">
                    <strong><?= gjc_e($walletDisplayName) ?></strong>
                    <span>Scan to pay your GenPay cart total</span>
                </div>
            </div>
        </section>
    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script>
const PROFILE_API = '<?= MERCHANT_URL ?>/api/profile.php';

document.getElementById('logoInput')?.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        let img = document.getElementById('logoPreview');
        if (img.tagName !== 'IMG') {
            const newImg = document.createElement('img');
            newImg.id = 'logoPreview';
            newImg.className = 'profile-logo-preview';
            img.replaceWith(newImg);
            img = newImg;
        }
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
});

document.getElementById('profileForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('profileSubmitBtn');
    const msg = document.getElementById('profileMsg');
    btn.disabled = true;
    btn.textContent = 'Saving...';
    try {
        const r = await fetch(PROFILE_API, { method: 'POST', body: new FormData(this) });
        const d = await r.json();
        msg.innerHTML = `<div class="alert ${d.success ? 'alert-success' : 'alert-danger'} mb-0">${d.message}</div>`;
    } catch (err) {
        msg.innerHTML = '<div class="alert alert-danger mb-0">Unable to contact the server. Please try again.</div>';
    }
    btn.disabled = false;
    btn.textContent = 'Save Changes';
});
</script>
<?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>
</html>
