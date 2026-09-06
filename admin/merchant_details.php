<?php
// ============================================================
//  admin/merchant_details.php — one stall, in full
//  The dashboard's Merchant/Tenant Directory card used to open a
//  modal; this is that view as a real page, widened to everything
//  finance holds on a stall: proprietor profile, the physical
//  stall record, the application and its documents, staff logins,
//  rent standing, inventory compliance and management activity.
//
//  Revenue privacy still applies — no sales figures, no POS
//  transaction history, no merchant wallet. Rent is shown
//  read-only here; collections belong on lease_details.php.
// ============================================================
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/MerchantTenantDirectory.php';

gjc_require_role(['finance']);
$currentUser = gjc_current_user($db);
$currentPage = 'dashboard';
$directory   = new MerchantTenantDirectory($db);

// Signed-token addressing, same as the other single-record pages.
$merchantId = gjc_verify_view_token($_GET['token'] ?? null, 'merchant');
$summary    = $merchantId !== null ? $directory->stallSummary($merchantId) : null;

if (!$summary) {
    http_response_code(404);
}

function md_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Label/value row, mirroring the uv-field pattern on the other detail pages. */
function md_field(string $icon, string $label, ?string $value, bool $mono = false): void
{
    $value = trim((string) $value);
    echo '<div class="gp-field"><div class="gp-field-ic"><i class="' . md_e($icon) . '"></i></div><div>'
        . '<div class="gp-field-label">' . md_e($label) . '</div>'
        . '<div class="gp-field-value' . ($mono ? ' is-mono' : '') . '">' . ($value !== '' ? md_e($value) : '&mdash;') . '</div>'
        . '</div></div>';
}

$perPage = 10;

if ($summary) {
    $merchantUserId = (int) $summary['merchant_user_id'];

    // Opening the page marks this stall's activity as checked — shared stamp,
    // so the dashboard badge clears for every finance admin. This used to live
    // in the API's `details` action, which the page no longer calls.
    gjc_ensure_merchant_card_views_schema($db);
    $db->prepare(
        "INSERT INTO merchant_card_views (merchant_id, last_viewed_at, viewed_by)
         VALUES (?, NOW(), ?)
         ON DUPLICATE KEY UPDATE last_viewed_at = NOW(), viewed_by = VALUES(viewed_by)"
    )->execute([$merchantId, gjc_user_id()]);

    // ── Proprietor ───────────────────────────────────────────────────────
    $ownerStmt = $db->prepare("SELECT * FROM users WHERE userID = ? LIMIT 1");
    $ownerStmt->execute([$merchantUserId]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── The merchant row itself (notes, registration date, stall code) ───
    $merchantStmt = $db->prepare("SELECT * FROM merchant WHERE merchantID = ? LIMIT 1");
    $merchantStmt->execute([$merchantId]);
    $merchantRow = $merchantStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Physical stall ───────────────────────────────────────────────────
    // merchant.stall_id names the stall directly; stalls.merchant_id is the
    // reverse link (and holds merchantID, not a user id). Try both, since
    // older rows only ever carried one of the two.
    $stall = null;
    if (gjc_table_exists($db, 'stalls')) {
        $stallStmt = $db->prepare(
            "SELECT * FROM stalls WHERE stall_id = ? OR merchant_id = ? ORDER BY (stall_id = ?) DESC LIMIT 1"
        );
        $code = (string) ($merchantRow['stall_id'] ?? '');
        $stallStmt->execute([$code, $merchantId, $code]);
        $stall = $stallStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Application on file ──────────────────────────────────────────────
    // The awarded application is the one carrying this merchant's user id;
    // fall back to a match on the stall code for stalls awarded before that
    // column was backfilled.
    $application = null;
    if (gjc_table_exists($db, 'stall_applications')) {
        $appStmt = $db->prepare(
            "SELECT * FROM stall_applications
              WHERE merchant_user_id = ? OR (merchant_user_id IS NULL AND stall_id = ? AND stall_id <> '')
              ORDER BY (merchant_user_id = ?) DESC, awarded_at DESC, id DESC
              LIMIT 1"
        );
        $appStmt->execute([$merchantUserId, (string) ($merchantRow['stall_id'] ?? ''), $merchantUserId]);
        $application = $appStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Staff logins under this owner ────────────────────────────────────
    $staff = [];
    if (in_array('merchant_owner_id', gjc_table_columns($db, 'users'), true)) {
        $staffStmt = $db->prepare(
            "SELECT userID, first_name, last_name, email, position, status, sub_role, created_at
               FROM users WHERE merchant_owner_id = ? ORDER BY first_name ASC, userID ASC"
        );
        $staffStmt->execute([$merchantUserId]);
        $staff = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Rent standing (read-only — collections live on lease_details.php) ─
    $lease   = $directory->activeLease($merchantUserId);
    $account = $lease['account'] ?? null;

    // ── Which section is on screen ───────────────────────────────────────
    // Tabs are addressed in the URL rather than switched in the browser, so a
    // paged table or a filtered list stays on its own tab across a reload, and
    // a tab can be linked to directly (the rent roll does exactly that).
    $tabs = [
        'overview'    => ['Overview',     'fa-solid fa-circle-info'],
        'lease'       => ['Lease & rent', 'fa-solid fa-file-signature'],
        'application' => ['Application',  'fa-solid fa-folder-open'],
        'staff'       => ['Staff',        'fa-solid fa-users'],
        'inventory'   => ['Inventory',    'fa-solid fa-box'],
        'activity'    => ['Activity',     'fa-solid fa-clock-rotate-left'],
    ];
    $tab = (string) ($_GET['tab'] ?? 'overview');
    if (!isset($tabs[$tab])) {
        $tab = 'overview';
    }

    // ── Inventory compliance (filtered and paged through the URL) ────────
    $invSearch   = trim((string) ($_GET['q'] ?? ''));
    $invCategory = trim((string) ($_GET['cat'] ?? ''));
    $invRestrict = trim((string) ($_GET['restrict'] ?? ''));
    if (!in_array($invRestrict, ['', 'restricted', 'allowed'], true)) {
        $invRestrict = '';
    }
    $invPage = max(1, (int) ($_GET['ipage'] ?? 1));

    // The stat tile counts the whole menu, so it stays truthful on every tab
    // even while the Inventory tab is showing a filtered slice of it.
    $productTotal = 0;
    if (gjc_table_exists($db, 'merchant_inventory')) {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM merchant_inventory WHERE merchant_user_id = ?");
        $countStmt->execute([$merchantUserId]);
        $productTotal = (int) $countStmt->fetchColumn();
    }

    // Only the visible tab's list is queried; the rest cost nothing.
    $inventory = $tab === 'inventory'
        ? $directory->pagedInventory($merchantUserId, $invSearch, $invCategory, $invRestrict, $invPage, $perPage)
        : null;

    // ── Management activity ──────────────────────────────────────────────
    $actPage  = max(1, (int) ($_GET['apage'] ?? 1));
    $activity = $tab === 'activity'
        ? $directory->pagedActivity($merchantUserId, $actPage, $perPage)
        : null;

    $activityMeta = [
        'MENU_MUTATION'       => ['Product / Menu', 'bg-success'],
        'USER_ACCOUNT'        => ['Staff / Profile', 'bg-primary'],
        'PRODUCT_RESTRICTION' => ['Banned Item', 'bg-danger'],
    ];

    /** A few telling scalars out of an audit row's JSON, not the raw payload. */
    $activitySummary = static function (?string $json): string {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            return $json ? mb_substr((string) $json, 0, 120) : '—';
        }
        $preferred = ['event', 'product_name', 'attempted_name', 'name', 'email', 'stall_name',
                      'sku', 'matched_reason', 'status', 'restriction_note', 'reason', 'price'];
        $parts = [];
        foreach ($preferred as $key) {
            if (count($parts) < 3 && isset($data[$key]) && $data[$key] !== '' && !is_array($data[$key])) {
                $parts[] = str_replace('_', ' ', $key) . ': ' . $data[$key];
            }
        }
        if (!$parts) {
            foreach ($data as $key => $value) {
                if ($value !== null && $value !== '' && !is_array($value)) {
                    $parts[] = str_replace('_', ' ', (string) $key) . ': ' . $value;
                }
                if (count($parts) >= 3) {
                    break;
                }
            }
        }
        return $parts ? implode(' · ', $parts) : '—';
    };

    // Every link back to this page keeps the token, the tab and the filters, so
    // switching tabs and coming back lands you where you were.
    $selfUrl = static function (array $overrides = []) use ($tab, $invSearch, $invCategory, $invRestrict, $invPage, $actPage): string {
        $params = array_filter([
            'token'    => (string) ($_GET['token'] ?? ''),
            'tab'      => $overrides['tab']      ?? $tab,
            'q'        => $overrides['q']        ?? $invSearch,
            'cat'      => $overrides['cat']      ?? $invCategory,
            'restrict' => $overrides['restrict'] ?? $invRestrict,
            'ipage'    => $overrides['ipage']    ?? ($invPage > 1 ? $invPage : ''),
            'apage'    => $overrides['apage']    ?? ($actPage > 1 ? $actPage : ''),
        ], static fn ($v) => $v !== '' && $v !== null);

        return htmlspecialchars(ADMIN_URL . '/merchant_details.php?' . http_build_query($params), ENT_QUOTES);
    };

    // Uploads are served through the admin document proxy, never linked
    // directly — same DOC_URL shape admin/stall_applications.php uses.
    $docUrl = static fn (string $path): string => htmlspecialchars(
        ADMIN_URL . '/doc?f=' . rawurlencode(ltrim(str_replace('\\', '/', $path), '/')),
        ENT_QUOTES
    );

    $statusClass = match (strtolower((string) $summary['operational_status'])) {
        'active' => 'bg-success',
        'suspended', 'inactive' => 'bg-danger',
        default  => 'bg-warning text-dark',
    };

    $leaseClass = [
        'overdue' => 'is-late',
        'settled' => 'is-ok',
        'ahead'   => 'is-ahead',
        'pending' => 'is-pending',
        'closed'  => 'is-closed',
    ][$account['state'] ?? ''] ?? '';
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
    <title><?= $summary ? md_e($summary['stall_name']) . ' — Stall' : 'Content Not Available' ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=37">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="gp-theme">
<div class="admin-layout">

    <!-- ── Sidebar ──────────────────────────────────────────────────────── -->
    <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

    <!-- ── Main ─────────────────────────────────────────────────────────── -->
    <main class="admin-main">

        <header class="topbar">
            <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
            <div>
                <h1><?= $summary ? md_e($summary['stall_name']) : 'Stall' ?></h1>
                <p>
                    <?php if ($summary): ?>
                        <?= md_e($summary['proprietor_name']) ?>
                        &middot; Stall #<?= (int) $summary['merchant_id'] ?>
                        <?php if (!empty($merchantRow['stall_id'])): ?>
                            &middot; <?= md_e($merchantRow['stall_id']) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        Merchant record
                    <?php endif; ?>
                </p>
            </div>
            <div class="admin-user">
                <span><?= md_e($currentUser['name']) ?></span>
                <div class="avatar"><i class="fa-solid fa-user-tie"></i></div>
            </div>
        </header>

        <div class="gp-detail-back mb-3">
            <a href="<?= ADMIN_URL ?>/dashboard.php#tenantDirectoryGrid">
                <i class="fa-solid fa-arrow-left"></i> Back to merchant directory
            </a>
        </div>

        <?php if (!$summary): ?>

            <section class="premium-panel">
                <div class="gp-empty">
                    <i class="fa-regular fa-circle-question"></i>
                    <strong>This stall isn't available.</strong>
                    <p>The link you followed may be broken, or the merchant record may have been removed.</p>
                    <a href="<?= ADMIN_URL ?>/dashboard.php" class="view-btn mt-3 d-inline-block">Go to Dashboard</a>
                </div>
            </section>

        <?php else: ?>

            <div id="merchantAlert"></div>

            <!-- ── Standing at a glance ─────────────────────────────────── -->
            <section class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="detail-stat h-100">
                        <span>Operating status</span>
                        <strong class="gp-stat-plain">
                            <span class="badge <?= $statusClass ?>">
                                <?= md_e(ucwords(str_replace('_', ' ', $summary['operational_status']))) ?>
                            </span>
                        </strong>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="detail-stat h-100">
                        <span>Rent standing</span>
                        <strong class="gp-stat-plain">
                            <span class="gp-rent-badge <?= md_e($leaseClass) ?>">
                                <?= md_e($account['state_label'] ?? 'No lease') ?>
                            </span>
                        </strong>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="detail-stat h-100">
                        <span>Products listed</span>
                        <strong><?= $productTotal ?></strong>
                        <small>in the stall menu</small>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="detail-stat h-100">
                        <span>Staff logins</span>
                        <strong><?= count($staff) ?></strong>
                        <small><?= count($staff) === 1 ? 'account under this stall' : 'accounts under this stall' ?></small>
                    </div>
                </div>
            </section>

            <div class="gp-notice is-note mb-4">
                <i class="fa-solid fa-shield-halved"></i>
                <div>
                    <strong>Revenue privacy enforced.</strong>
                    Sales figures, POS history and the merchant wallet are deliberately excluded
                    from this view &mdash; rent collection appears under Lease &amp; rent, what the
                    stall earns does not.
                </div>
            </div>

            <!-- ── Section switcher ─────────────────────────────────────── -->
            <nav class="gp-tabs mb-4" aria-label="Stall sections">
                <?php foreach ($tabs as $key => [$label, $icon]): ?>
                    <a class="gp-tab <?= $tab === $key ? 'is-active' : '' ?>"
                       href="<?= $selfUrl(['tab' => $key]) ?>"
                       <?= $tab === $key ? 'aria-current="page"' : '' ?>>
                        <i class="<?= md_e($icon) ?>"></i>
                        <span><?= md_e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'lease'): ?>
            <!-- ── Rent standing (read-only) ────────────────────────────── -->
            <section class="premium-panel mb-4" id="rent">
                <div class="panel-header d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h3>Lease &amp; rent</h3>
                        <p>Where this stall stands on rent. Edits and collections happen on the lease page.</p>
                    </div>
                    <?php if ($lease): ?>
                        <a class="view-btn" href="<?= ADMIN_URL ?>/lease_details.php?token=<?= rawurlencode(gjc_make_view_token((int) $lease['id'], 'lease')) ?>">
                            Open lease ledger
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!$lease): ?>
                    <div class="gp-notice is-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div>
                            <strong>No lease contract on file.</strong>
                            No rent is being tracked for this stall. Create one on
                            <a href="<?= ADMIN_URL ?>/leases.php">Leases &amp; Rent</a>.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="gp-ledger-summary <?= md_e($leaseClass) ?>">
                        <span class="gp-rent-badge <?= md_e($leaseClass) ?>"><?= md_e($account['state_label']) ?></span>
                        <p><?= md_e($account['summary']) ?></p>
                    </div>

                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <div class="detail-stat h-100">
                                <span>Monthly rent</span>
                                <strong><?= gjc_money($lease['monthly_rent']) ?></strong>
                                <small>every <?= md_e(date('jS', strtotime($lease['lease_start']))) ?> of the month</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="detail-stat h-100">
                                <span>Unpaid rent</span>
                                <?php if ($account['outstanding'] > 0.005): ?>
                                    <strong class="gp-amount-due"><?= gjc_money($account['outstanding']) ?></strong>
                                <?php elseif ($account['advance'] > 0.005): ?>
                                    <strong class="gp-amount-credit"><?= gjc_money($account['advance']) ?> ahead</strong>
                                <?php else: ?>
                                    <strong>Nothing owed</strong>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="detail-stat h-100">
                                <span>Next charge due</span>
                                <strong><?= $account['next_due_date']
                                    ? md_e(date('M j, Y', strtotime($account['next_due_date'])))
                                    : 'Nothing left to bill' ?></strong>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="detail-stat h-100">
                                <span>Contract term</span>
                                <strong><?= (int) $account['term_months'] ?> months</strong>
                                <small><?= md_e(date('M j, Y', strtotime($lease['lease_start']))) ?>
                                    &ndash; <?= md_e(date('M j, Y', strtotime($lease['lease_end']))) ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="gp-fields mt-3">
                        <?php
                        md_field('fa-solid fa-file-signature', 'Contract status', ucfirst((string) $lease['status']));
                        md_field('fa-solid fa-vault', 'Security deposit', gjc_money_plain((float) $lease['deposit_amount']));
                        md_field('fa-solid fa-money-bill-wave', 'Collected to date', gjc_money_plain((float) $account['collected']));
                        md_field('fa-solid fa-note-sticky', 'Contract notes', (string) $lease['contract_notes']);
                        ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($tab === 'overview'): ?>
            <!-- ── Proprietor ───────────────────────────────────────────── -->
            <section class="premium-panel mb-4" id="proprietor">
                <div class="panel-header d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h3>Proprietor</h3>
                        <p>The account that owns this stall and is billed for its rent.</p>
                    </div>
                    <?php if ($owner): ?>
                        <a class="view-btn" href="<?= ADMIN_URL ?>/view_user.php?token=<?= rawurlencode(gjc_make_view_token($merchantUserId, 'user')) ?>">
                            Open user record
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!$owner): ?>
                    <p class="text-muted mb-0">No user account is linked to this stall.</p>
                <?php else: ?>
                    <div class="gp-fields">
                        <?php
                        $ownerName = trim(implode(' ', array_filter([
                            $owner['first_name'] ?? '', $owner['middle_name'] ?? '',
                            $owner['last_name'] ?? '', $owner['suffix'] ?? '',
                        ], static fn ($p) => trim((string) $p) !== '')));
                        md_field('fa-solid fa-user', 'Full name', $ownerName);
                        md_field('fa-regular fa-envelope', 'Email', (string) ($owner['email'] ?? ''));
                        md_field('fa-solid fa-phone', 'Contact number', (string) ($owner['contact_number'] ?? ''));
                        md_field('fa-solid fa-venus-mars', 'Sex', (string) ($owner['sex'] ?? ''));
                        md_field('fa-solid fa-hourglass-half', 'Age', gjc_age_label($owner['date_of_birth'] ?? null));
                        md_field('fa-solid fa-cake-candles', 'Date of birth', gjc_dob_label($owner['date_of_birth'] ?? null));
                        md_field('fa-solid fa-location-dot', 'Address', gjc_format_address($owner));
                        md_field('fa-solid fa-circle-dot', 'Account status', (string) ($owner['status'] ?? ''));
                        md_field('fa-solid fa-id-badge', 'Account role', trim((string) ($owner['sub_role'] ?? '')) !== ''
                            ? ucwords(str_replace('_', ' ', (string) $owner['sub_role']))
                            : 'Merchant');
                        md_field('fa-regular fa-calendar', 'Registered', !empty($owner['created_at'])
                            ? date('M j, Y · g:i A', strtotime((string) $owner['created_at']))
                            : '');
                        md_field('fa-solid fa-fingerprint', 'User ID', (string) $merchantUserId, true);
                        ?>
                    </div>

                    <?php if (!empty($owner['suspended_until']) || !empty($owner['suspension_reason'])): ?>
                        <div class="gp-notice is-warning mt-3">
                            <i class="fa-solid fa-user-lock"></i>
                            <div>
                                <strong>This account is under suspension.</strong>
                                <?= md_e(trim((string) ($owner['suspension_reason'] ?? ''))) ?>
                                <?php if (!empty($owner['suspended_until'])): ?>
                                    Until <?= md_e(date('M j, Y', strtotime((string) $owner['suspended_until']))) ?>.
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <!-- ── Physical stall ───────────────────────────────────────── -->
            <section class="premium-panel mb-4" id="stall">
                <div class="panel-header">
                    <div>
                        <h3>Stall record</h3>
                        <p>The physical unit on the stall map, and how this merchant is registered against it.</p>
                    </div>
                </div>

                <div class="gp-fields">
                    <?php
                    md_field('fa-solid fa-store', 'Trading name', (string) $summary['stall_name']);
                    md_field('fa-solid fa-hashtag', 'Stall code', (string) ($merchantRow['stall_id'] ?? ''), true);
                    if ($stall) {
                        md_field('fa-solid fa-map-pin', 'Stall label', (string) $stall['label']);
                        md_field('fa-solid fa-table-cells', 'Position', trim((string) $stall['row_label'] . (string) $stall['col_number']));
                        md_field('fa-solid fa-ruler-combined', 'Floor area', !empty($stall['area_sqm'])
                            ? rtrim(rtrim(number_format((float) $stall['area_sqm'], 2), '0'), '.') . ' sqm'
                            : '');
                        md_field('fa-solid fa-tag', 'Listed monthly rate', isset($stall['monthly_rate'])
                            ? gjc_money_plain((float) $stall['monthly_rate'])
                            : '');
                        md_field('fa-solid fa-circle-dot', 'Stall map status', ucfirst((string) $stall['status']));
                    }
                    md_field('fa-regular fa-calendar', 'Merchant since', !empty($merchantRow['created_at'])
                        ? date('M j, Y', strtotime((string) $merchantRow['created_at']))
                        : '');
                    md_field('fa-solid fa-note-sticky', 'Merchant notes', (string) ($merchantRow['notes'] ?? ''));
                    ?>
                </div>

                <?php if (!$stall): ?>
                    <p class="gp-pane-hint mt-3 mb-0">
                        No matching row on the stall map — this merchant was registered without being
                        pinned to a physical unit.
                    </p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($tab === 'application'): ?>
            <!-- ── Application & documents ──────────────────────────────── -->
            <section class="premium-panel mb-4" id="application">
                <div class="panel-header">
                    <div>
                        <h3>Application &amp; documents</h3>
                        <p>How this stall was awarded, and the paperwork submitted with it.</p>
                    </div>
                </div>

                <?php if (!$application): ?>
                    <p class="text-muted mb-0">
                        No stall application is on file — this merchant was onboarded directly rather
                        than through <a href="<?= ADMIN_URL ?>/stall_applications.php">Stall Applications</a>.
                    </p>
                <?php else: ?>
                    <div class="gp-fields">
                        <?php
                        md_field('fa-solid fa-briefcase', 'Business name', (string) $application['business_name']);
                        md_field('fa-solid fa-circle-check', 'Application status', ucwords(str_replace('_', ' ', (string) $application['status'])));
                        md_field('fa-regular fa-paper-plane', 'Submitted', !empty($application['created_at'])
                            ? date('M j, Y', strtotime((string) $application['created_at'])) : '');
                        md_field('fa-solid fa-gavel', 'Awarded', !empty($application['awarded_at'])
                            ? date('M j, Y', strtotime((string) $application['awarded_at'])) : '');
                        md_field('fa-solid fa-file-contract', 'Contract reference', (string) ($application['contract_ref'] ?? ''), true);
                        md_field('fa-solid fa-pen-nib', 'Contract signed', !empty($application['signed_at'])
                            ? date('M j, Y', strtotime((string) $application['signed_at'])) : '');
                        md_field('fa-solid fa-vault', 'Deposit on application', isset($application['deposit_amount'])
                            ? gjc_money_plain((float) $application['deposit_amount']) : '');
                        md_field('fa-solid fa-forward', 'Advance on application', isset($application['advance_amount'])
                            ? gjc_money_plain((float) $application['advance_amount']) : '');
                        md_field('fa-solid fa-receipt', 'Down payment', isset($application['down_payment_amount']) && $application['down_payment_amount'] !== null
                            ? gjc_money_plain((float) $application['down_payment_amount'])
                              . (trim((string) ($application['down_payment_reference'] ?? '')) !== ''
                                  ? ' · ' . $application['down_payment_reference'] : '')
                            : '');
                        md_field('fa-solid fa-calendar-day', 'Rental start on contract', !empty($application['rental_start_date'])
                            ? date('M j, Y', strtotime((string) $application['rental_start_date'])) : '');
                        ?>
                    </div>

                    <?php
                    $docs = array_filter([
                        'Applicant photo'  => $application['profile_picture'] ?? '',
                        'Business permit'  => $application['business_permit'] ?? '',
                        'Sanitary permit'  => $application['sanitary_permit'] ?? '',
                        'GJC requirements' => $application['gjc_requirements'] ?? '',
                        'Clearance'        => $application['clearance'] ?? '',
                        'Signed contract'  => $application['contract_file'] ?? '',
                    ], static fn ($p) => trim((string) $p) !== '' && $p !== 'pending_path');
                    ?>
                    <h6 class="gp-subhead">Documents on file</h6>
                    <?php if (!$docs): ?>
                        <p class="gp-pane-hint mb-0">No documents were uploaded with this application.</p>
                    <?php else: ?>
                        <div class="gp-doc-links">
                            <?php foreach ($docs as $label => $path): ?>
                                <a class="gp-doc-link" href="<?= $docUrl((string) $path) ?>" target="_blank" rel="noopener">
                                    <i class="fa-regular fa-file-lines"></i>
                                    <span><?= md_e($label) ?></span>
                                    <i class="fa-solid fa-up-right-from-square gp-doc-link-out"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($tab === 'staff'): ?>
            <!-- ── Staff accounts ───────────────────────────────────────── -->
            <section class="premium-panel mb-4" id="staff">
                <div class="panel-header">
                    <div>
                        <h3>Staff accounts</h3>
                        <p>Logins the proprietor has created for this stall. They can run the POS but not encash.</p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Position</th>
                                <th>Status</th>
                                <th>Added</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$staff): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No staff accounts under this stall.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($staff as $s): ?>
                            <?php $sName = trim((string) $s['first_name'] . ' ' . (string) $s['last_name']) ?: (string) $s['email']; ?>
                            <tr>
                                <td><strong><?= md_e($sName) ?></strong></td>
                                <td><?= md_e($s['email']) ?></td>
                                <td><?= md_e(trim((string) $s['position']) !== '' ? $s['position'] : '—') ?></td>
                                <td>
                                    <span class="badge <?= strtolower((string) $s['status']) === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= md_e($s['status']) ?>
                                    </span>
                                </td>
                                <td><?= !empty($s['created_at']) ? md_e(date('M j, Y', strtotime((string) $s['created_at']))) : '—' ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= ADMIN_URL ?>/view_user.php?token=<?= rawurlencode(gjc_make_view_token((int) $s['userID'], 'user')) ?>">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($tab === 'inventory'): ?>
            <!-- ── Inventory compliance ─────────────────────────────────── -->
            <section class="premium-panel mb-4" id="inventory">
                <div class="panel-header">
                    <div>
                        <h3>Inventory compliance</h3>
                        <p>Everything this stall sells. Restricting a product also pulls it from their POS.</p>
                    </div>
                </div>

                <form method="get" class="lease-filter-bar">
                    <input type="hidden" name="token" value="<?= md_e($_GET['token'] ?? '') ?>">
                    <input type="hidden" name="tab" value="inventory">
                    <?php if ($actPage > 1): ?>
                        <input type="hidden" name="apage" value="<?= (int) $actPage ?>">
                    <?php endif; ?>
                    <input type="search" name="q" class="form-control" placeholder="Product name or SKU…" value="<?= md_e($invSearch) ?>">
                    <input type="text" name="cat" class="form-control" placeholder="Category" value="<?= md_e($invCategory) ?>">
                    <select name="restrict" class="form-select">
                        <option value="">All products</option>
                        <option value="restricted" <?= $invRestrict === 'restricted' ? 'selected' : '' ?>>Restricted only</option>
                        <option value="allowed" <?= $invRestrict === 'allowed' ? 'selected' : '' ?>>Allowed only</option>
                    </select>
                    <button type="submit" class="btn btn-outline-secondary">Filter</button>
                    <?php if ($invSearch !== '' || $invCategory !== '' || $invRestrict !== ''): ?>
                        <a href="<?= $selfUrl(['q' => '', 'cat' => '', 'restrict' => '', 'ipage' => '']) ?>" class="btn btn-link">Clear</a>
                    <?php endif; ?>
                    <span class="ms-auto text-muted small">
                        <?= (int) $inventory['total'] ?> product<?= (int) $inventory['total'] === 1 ? '' : 's' ?><?= (int) $inventory['total'] !== $productTotal ? ' of ' . $productTotal : '' ?>
                    </span>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th class="text-end">Price</th>
                                <th>POS status</th>
                                <th>Compliance</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$inventory['rows']): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No inventory items match that filter.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($inventory['rows'] as $item): ?>
                            <?php
                                $restricted = (int) $item['is_restricted'] === 1;
                                $available  = (int) $item['is_available'] === 1 && !$restricted;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= md_e($item['product_name']) ?></strong>
                                    <?php if (trim((string) $item['sku']) !== ''): ?>
                                        <div class="gp-cell-note"><?= md_e($item['sku']) ?></div>
                                    <?php endif; ?>
                                    <?php if (trim((string) $item['restriction_note']) !== ''): ?>
                                        <div class="gp-cell-note is-late"><?= md_e($item['restriction_note']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= md_e($item['category']) ?></td>
                                <td class="text-end"><?= gjc_money($item['price']) ?></td>
                                <td>
                                    <span class="badge <?= $available ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= $available ? 'Enabled' : 'Disabled' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $restricted ? 'bg-danger' : 'bg-success' ?>">
                                        <?= $restricted ? 'Restricted' : 'Allowed' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button type="button"
                                            class="btn btn-sm <?= $restricted ? 'btn-outline-success' : 'btn-outline-danger' ?> js-toggle-restriction"
                                            data-item-id="<?= (int) $item['id'] ?>"
                                            data-restrict="<?= $restricted ? 0 : 1 ?>"
                                            data-name="<?= md_e($item['product_name']) ?>">
                                        <?= $restricted ? 'Clear' : 'Flag/Restrict' ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ((int) $inventory['total_pages'] > 1): ?>
                <nav class="d-flex justify-content-between align-items-center mt-3">
                    <span class="text-muted small">
                        Page <?= (int) $inventory['page'] ?> of <?= (int) $inventory['total_pages'] ?>
                    </span>
                    <span class="btn-group btn-group-sm">
                        <?php if ((int) $inventory['page'] > 1): ?>
                            <a class="btn btn-outline-secondary" href="<?= $selfUrl(['ipage' => (int) $inventory['page'] - 1]) ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ((int) $inventory['page'] < (int) $inventory['total_pages']): ?>
                            <a class="btn btn-outline-secondary" href="<?= $selfUrl(['ipage' => (int) $inventory['page'] + 1]) ?>">Next</a>
                        <?php endif; ?>
                    </span>
                </nav>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($tab === 'activity'): ?>
            <!-- ── Management activity ──────────────────────────────────── -->
            <section class="premium-panel" id="activity">
                <div class="panel-header">
                    <div>
                        <h3>Management activity</h3>
                        <p>
                            Actions by this stall's owner and staff — the same events the dashboard
                            badge counts. Routine sales are not shown.
                        </p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>By</th>
                                <th>Action</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$activity['rows']): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No merchant activity recorded yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($activity['rows'] as $row): ?>
                            <?php [$label, $cls] = $activityMeta[$row['action_type']] ?? [$row['action_type'], 'bg-secondary']; ?>
                            <tr>
                                <td class="text-nowrap"><?= md_e(date('M j, Y · g:i A', strtotime((string) $row['timestamp']))) ?></td>
                                <td>
                                    <strong><?= md_e(trim((string) $row['actor_name']) !== '' ? $row['actor_name'] : 'Unknown user') ?></strong>
                                    <div class="gp-cell-note"><?= md_e($row['user_role'] ?? '') ?></div>
                                </td>
                                <td><span class="badge <?= md_e($cls) ?>"><?= md_e($label) ?></span></td>
                                <td><small><?= md_e($activitySummary($row['new_value'])) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ((int) $activity['total_pages'] > 1): ?>
                <nav class="d-flex justify-content-between align-items-center mt-3">
                    <span class="text-muted small">
                        <?= (int) $activity['total'] ?> entr<?= (int) $activity['total'] === 1 ? 'y' : 'ies' ?>,
                        page <?= (int) $activity['page'] ?> of <?= (int) $activity['total_pages'] ?>
                    </span>
                    <span class="btn-group btn-group-sm">
                        <?php if ((int) $activity['page'] > 1): ?>
                            <a class="btn btn-outline-secondary" href="<?= $selfUrl(['apage' => (int) $activity['page'] - 1]) ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ((int) $activity['page'] < (int) $activity['total_pages']): ?>
                            <a class="btn btn-outline-secondary" href="<?= $selfUrl(['apage' => (int) $activity['page'] + 1]) ?>">Next</a>
                        <?php endif; ?>
                    </span>
                </nav>
                <?php endif; ?>
            </section>
            <?php endif; ?>

        <?php endif; ?>

    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
window.merchantDetailConfig = { endpoint: '<?= ADMIN_URL ?>/api/get_stall_details.php' };
</script>
<script src="<?= JS_URL ?>/admin_merchant_details.js?v=1"></script>
</body>
</html>
