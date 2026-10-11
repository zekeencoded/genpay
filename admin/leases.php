<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/MerchantTenantDirectory.php';

gjc_require_role(['finance']);
$currentUser = gjc_current_user($db);
$currentPage = 'leases';
$directory   = new MerchantTenantDirectory($db);

function lease_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** "in 6 days" / "today" / "10 days overdue" — the bit people actually read. */
function lease_when(?string $date, string $today): array
{
    if (!$date) {
        return ['', ''];
    }

    $days = (int) floor((strtotime($date) - strtotime($today)) / 86400);

    if ($days < 0)  return [abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' overdue', 'is-late'];
    if ($days === 0) return ['due today', 'is-soon'];
    if ($days <= 7)  return ['in ' . $days . ' day' . ($days === 1 ? '' : 's'), 'is-soon'];

    return ['in ' . $days . ' days', ''];
}

$today     = date('Y-m-d');
$thisMonth = date('Y-m');

// ── Filters ──────────────────────────────────────────────────────────────
// One "Show" control instead of the old status dropdown + overdue checkbox,
// which could be combined into states that returned nothing.
$q    = trim((string) ($_GET['q'] ?? ''));
$view = (string) ($_GET['view'] ?? 'all');
$views = [
    'all'     => 'All leases',
    'owing'   => 'Owes rent',
    'ok'      => 'Up to date',
    'pending' => 'Not started yet',
    'closed'  => 'Expired or terminated',
];
if (!isset($views[$view])) {
    $view = 'all';
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

// ── Load the rent roll ───────────────────────────────────────────────────
// Rent standing is computed in PHP from each lease's payment schedule, so the
// roll is built in full and then filtered/sorted/paged here rather than in SQL.
$rows            = [];
$leasesExist     = gjc_table_exists($db, 'merchant_leases');
$collectedMonth  = 0.0;
$unleased        = $directory->merchantsWithoutLease();

if ($leasesExist) {
    // No cron in this app, so the "rent due soon" notice rides page loads — see
    // dispatchRentReminders(). Once per tenant per billing period, whichever of
    // finance or the tenant themselves opens a page first.
    $directory->dispatchRentReminders();

    $where  = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(ml.stall_name LIKE ? OR ml.stall_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
        $needle = '%' . $q . '%';
        array_push($params, $needle, $needle, $needle, $needle, $needle);
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $stmt = $db->prepare(
        "SELECT ml.*, u.first_name, u.last_name, u.email
           FROM merchant_leases ml
           LEFT JOIN users u ON u.userID = ml.merchant_user_id
          {$whereSql}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // One grouped query for every lease on screen instead of one per row.
    $directory->primePaymentCache(array_column($rows, 'id'));

    // Rebuild each lease's standing, and repair the stored next_due_date while
    // we are here — older payments bumped that column by a flat month each time
    // they were recorded, so it had drifted away from the actual receipts. Other
    // pages (merchant/dashboard.php) read the column directly.
    $hasDueCache = in_array('next_due_date', gjc_table_columns($db, 'merchant_leases'), true);
    $dueFix = $hasDueCache ? $db->prepare("UPDATE merchant_leases SET next_due_date = ? WHERE id = ?") : null;
    foreach ($rows as $i => $row) {
        $account = $directory->leaseAccount($row);
        $rows[$i]['account'] = $account;

        $derived = $account['next_due_date'] ?? (string) $row['lease_end'];
        if ($dueFix && $derived && $derived !== (string) $row['next_due_date']) {
            $dueFix->execute([$derived, (int) $row['id']]);
        }
        $rows[$i]['next_due_date'] = $derived;
    }

    if (gjc_table_exists($db, 'merchant_rent_payments')) {
        $collectedStmt = $db->prepare(
            "SELECT COALESCE(SUM(amount_paid), 0) FROM merchant_rent_payments
              WHERE DATE_FORMAT(payment_date, '%Y-%m') = ?"
        );
        $collectedStmt->execute([$thisMonth]);
        $collectedMonth = (float) $collectedStmt->fetchColumn();
    }
}

// ── Summary (whole roll, not just the current filter) ────────────────────
// Each count uses the same test as the view filter its card links to, so the
// number on the card always matches the number of rows you land on.
$activeCount  = 0;
$activePaidUp = 0;
$owingCount   = 0;
$owingAmount  = 0.0;
$monthlyRoll  = 0.0;

foreach ($rows as $row) {
    $acct = $row['account'];

    if ($row['status'] === 'active') {
        $activeCount++;
        $monthlyRoll += (float) $row['monthly_rent'];
        if (!$acct['is_overdue']) {
            $activePaidUp++;
        }
    }

    if ($acct['is_overdue']) {
        $owingCount++;
        $owingAmount += $acct['outstanding'];
    }
}

// ── Apply the view filter ────────────────────────────────────────────────
$rows = array_values(array_filter($rows, static function (array $row) use ($view): bool {
    $state = $row['account']['state'];

    return match ($view) {
        'owing'   => $row['account']['is_overdue'],
        'ok'      => in_array($state, ['settled', 'ahead'], true) && $row['status'] === 'active',
        'pending' => $row['status'] === 'pending',
        'closed'  => in_array($row['status'], ['expired', 'terminated'], true),
        default   => true,
    };
}));

// Most overdue first, then whatever falls due soonest — the collection order.
usort($rows, static function (array $a, array $b): int {
    $ao = $a['account']['is_overdue'] ? 0 : 1;
    $bo = $b['account']['is_overdue'] ? 0 : 1;
    if ($ao !== $bo) {
        return $ao <=> $bo;
    }
    if ($ao === 0) {
        return $b['account']['days_overdue'] <=> $a['account']['days_overdue'];
    }

    return strcmp((string) ($a['account']['next_due_date'] ?? '9999'), (string) ($b['account']['next_due_date'] ?? '9999'));
});

$totalRows  = count($rows);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$leases     = array_slice($rows, ($page - 1) * $perPage, $perPage);

function gjc_lease_qs(array $overrides = []): string
{
    return htmlspecialchars(gjc_lease_qs_raw($overrides), ENT_QUOTES);
}

/** Unescaped twin of gjc_lease_qs() — for values that get URL-encoded instead. */
function gjc_lease_qs_raw(array $overrides = []): string
{
    $base = [
        'q'    => $_GET['q']    ?? '',
        'view' => $_GET['view'] ?? '',
        'page' => $_GET['page'] ?? '',
    ];
    $merged = array_filter(array_merge($base, $overrides), static fn ($v) => $v !== '' && $v !== null);

    return http_build_query($merged);
}

/**
 * Link to one lease's own page, on the section the button means. `ret` carries
 * the roll's current filter so the Back link lands the user exactly where they
 * left off.
 */
function gjc_lease_link(int $leaseId, string $tab = ''): string
{
    $url = ADMIN_URL . '/lease_details.php?token=' . rawurlencode(gjc_make_view_token($leaseId, 'lease'));
    if ($tab !== '') {
        $url .= '&tab=' . rawurlencode($tab);
    }
    $ret = gjc_lease_qs_raw();
    if ($ret !== '') {
        $url .= '&ret=' . rawurlencode($ret);
    }

    return htmlspecialchars($url, ENT_QUOTES);
}

$stateBadge = [
    'overdue' => 'gp-rent-badge is-late',
    'settled' => 'gp-rent-badge is-ok',
    'ahead'   => 'gp-rent-badge is-ahead',
    'pending' => 'gp-rent-badge is-pending',
    'closed'  => 'gp-rent-badge is-closed',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leases &amp; Rent | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
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
                <h1>Leases &amp; Rent</h1>
                <p>Who rents which stall, and whether their rent is paid up.</p>
            </div>
            <div class="admin-user">
                <span><?= lease_e($currentUser['name']) ?></span>
                <div class="avatar"><i class="fa-solid fa-user-tie"></i></div>
            </div>
        </header>


        <!-- ── Summary Cards ────────────────────────────────────────────── -->
        <section class="row g-4 mb-4">
            <div class="col-12 col-md-6 col-xl-3">
                <a href="?<?= gjc_lease_qs(['view' => 'owing', 'page' => '']) ?>" class="text-decoration-none">
                    <div class="metric-card <?= $view === 'owing' ? 'is-filtering' : '' ?>">
                        <div class="metric-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                        <span>Needs collection</span>
                        <h2><?= $owingCount ?></h2>
                        <p><?= $owingCount ? gjc_money($owingAmount) . ' still uncollected' : 'Nobody is behind on rent' ?></p>
                    </div>
                </a>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <a href="?<?= gjc_lease_qs(['view' => 'ok', 'page' => '']) ?>" class="text-decoration-none">
                    <div class="metric-card <?= $view === 'ok' ? 'is-filtering' : '' ?>">
                        <div class="metric-icon"><i class="fa-solid fa-circle-check"></i></div>
                        <span>Paid up</span>
                        <h2><?= $activePaidUp ?></h2>
                        <p>of <?= $activeCount ?> running lease<?= $activeCount === 1 ? '' : 's' ?></p>
                    </div>
                </a>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="metric-card">
                    <div class="metric-icon"><i class="fa-solid fa-money-bill-transfer"></i></div>
                    <span>Collected in <?= date('M') ?></span>
                    <h2><?= gjc_money($collectedMonth) ?></h2>
                    <p>Rent payments logged this month</p>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="metric-card">
                    <div class="metric-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                    <span>Monthly rent roll</span>
                    <h2><?= gjc_money($monthlyRoll) ?></h2>
                    <p>Billed each month across active leases</p>
                </div>
            </div>
        </section>

        <!-- ── Merchants with no lease on file ──────────────────────────── -->
        <?php if ($unleased): ?>
        <section class="gp-notice is-warning mb-4">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong><?= count($unleased) ?> stall<?= count($unleased) === 1 ? ' has' : 's have' ?> no lease on file.</strong>
                No rent is being tracked for
                <?= lease_e(implode(', ', array_map(static fn (array $m): string => $m['stall_name'], array_slice($unleased, 0, 4)))) ?><?= count($unleased) > 4 ? ' and ' . (count($unleased) - 4) . ' more' : '' ?>.
                Create a lease so they appear on the rent roll.
                <div class="mt-2 d-flex flex-wrap gap-2">
                    <?php foreach (array_slice($unleased, 0, 6) as $m): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary js-new-lease-for"
                                data-merchant-id="<?= (int) $m['merchant_user_id'] ?>"
                                data-stall-name="<?= lease_e($m['stall_name']) ?>">
                            + Lease for <?= lease_e($m['stall_name']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Rent roll ────────────────────────────────────────────────── -->
        <section class="premium-panel">
            <div class="panel-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h3>Rent roll</h3>
                    <p>Every stall contract, sorted so whoever owes the most-overdue rent is on top.</p>
                </div>
                <button class="view-btn" onclick="openNewLeaseModal()">+ New Lease</button>
            </div>

            <form method="get" class="lease-filter-bar">
                <input type="search" name="q" class="form-control" placeholder="Search stall, stall number, tenant or email…"
                       value="<?= lease_e($q) ?>">
                <select name="view" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($views as $key => $label): ?>
                        <option value="<?= lease_e($key) ?>" <?= $view === $key ? 'selected' : '' ?>>Show: <?= lease_e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline-secondary">Search</button>
                <?php if ($q !== '' || $view !== 'all'): ?>
                    <a href="<?= ADMIN_URL ?>/leases.php" class="btn btn-link">Clear</a>
                <?php endif; ?>
                <span class="ms-auto text-muted small"><?= $totalRows ?> lease<?= $totalRows === 1 ? '' : 's' ?></span>
            </form>

            <div class="table-responsive">
                <table class="table premium-table align-middle gp-rent-table">
                    <thead>
                        <tr>
                            <th data-card="title">Stall &amp; tenant</th>
                            <th data-card="hide">Monthly rent</th>
                            <th>Rent standing</th>
                            <th data-card="hide">Next charge due</th>
                            <th class="text-end" data-card="amount">Unpaid</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$leases): ?>
                        <tr>
                            <td colspan="6" class="gp-empty">
                                <?php if (!$leasesExist || (!$totalRows && $q === '' && $view === 'all')): ?>
                                    <i class="fa-solid fa-file-signature"></i>
                                    <strong>No lease contracts yet.</strong>
                                    <p>
                                        Leases normally appear here the moment you award a stall on
                                        <a href="<?= ADMIN_URL ?>/stall_applications.php">Stall Applications</a>.
                                        For a tenant who never applied through the system, use <em>+ New Lease</em>.
                                    </p>
                                <?php else: ?>
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                    <strong>Nothing matches that filter.</strong>
                                    <p><a href="<?= ADMIN_URL ?>/leases.php">Show all leases</a> instead.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($leases as $l): ?>
                        <?php
                            $acct  = $l['account'];
                            $badge = $stateBadge[$acct['state']] ?? 'gp-rent-badge';
                            [$whenText, $whenClass] = lease_when($acct['next_due_date'], $today);
                            $tenant = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?: ($l['email'] ?? 'Unassigned');
                        ?>
                        <tr class="<?= $acct['is_overdue'] ? 'is-late-row' : '' ?>">
                            <td>
                                <strong><?= lease_e($l['stall_name']) ?></strong><br>
                                <small class="text-muted">
                                    Stall <?= lease_e($l['stall_number']) ?> &middot; <?= lease_e($tenant) ?>
                                </small>
                            </td>
                            <td class="gp-num"><?= gjc_money($l['monthly_rent']) ?></td>
                            <td>
                                <span class="<?= $badge ?>"><?= lease_e($acct['state_label']) ?></span>
                                <?php if ($acct['months_behind'] > 0): ?>
                                    <div class="gp-cell-note is-late">
                                        <?= $acct['months_behind'] ?> month<?= $acct['months_behind'] === 1 ? '' : 's' ?> unpaid
                                    </div>
                                <?php elseif ($acct['months_ahead'] > 0): ?>
                                    <div class="gp-cell-note"><?= $acct['months_ahead'] ?> month<?= $acct['months_ahead'] === 1 ? '' : 's' ?> in advance</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($acct['next_due_date']): ?>
                                    <?= lease_e(date('M j, Y', strtotime($acct['next_due_date']))) ?>
                                    <div class="gp-cell-note <?= $whenClass ?>"><?= lease_e($whenText) ?></div>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                    <div class="gp-cell-note">nothing left to bill</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end gp-num">
                                <?php if ($acct['outstanding'] > 0.005): ?>
                                    <strong class="gp-amount-due"><?= gjc_money($acct['outstanding']) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="gp-row-actions">
                                    <?php if ($l['status'] === 'active'): ?>
                                        <a class="btn btn-sm btn-success" href="<?= gjc_lease_link((int) $l['id'], 'collect') ?>">
                                            Record payment
                                        </a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= gjc_lease_link((int) $l['id'], 'receipts') ?>">
                                        Details
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav class="d-flex justify-content-center mt-3">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= gjc_lease_qs(['page' => $page - 1]) ?>">&laquo; Prev</a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= gjc_lease_qs(['page' => $i]) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= gjc_lease_qs(['page' => $page + 1]) ?>">Next &raquo;</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </section>

        <!-- ── Reference: how the rent cycle works ──────────────────────── -->
        <section class="gp-howto is-footnote" id="leaseHowto">
            <button type="button" class="gp-howto-toggle" id="leaseHowtoToggle" aria-expanded="true" aria-controls="leaseHowtoBody">
                <i class="fa-solid fa-circle-info"></i>
                <span>How rent collection works here</span>
                <i class="fa-solid fa-chevron-up gp-howto-caret"></i>
            </button>
            <div class="gp-howto-body" id="leaseHowtoBody">
                <ol class="gp-howto-steps">
                    <li>
                        <strong>Leases arrive on their own.</strong>
                        Awarding a stall application on
                        <a href="<?= ADMIN_URL ?>/stall_applications.php">Stall Applications</a>
                        creates the lease for you. Use <em>+ New Lease</em> above only for a tenant
                        who never went through an application.
                    </li>
                    <li>
                        <strong>Each lease bills one month of rent at a time.</strong>
                        The first charge lands on the lease start date and repeats on that same day
                        every month — a lease starting the 20th is charged on the 20th.
                    </li>
                    <li>
                        <strong>When a tenant pays, record it against the month it covers.</strong>
                        Open the lease, hit <em>Record payment</em>, and pick the month in
                        <em>Period covered</em>. That is what marks a month settled — not the date you
                        typed it in.
                    </li>
                    <li>
                        <strong>The rent roll above is your collection list.</strong>
                        <span class="gp-rent-badge is-late">Owes rent</span> means at least one month
                        that has already been charged is still unpaid. Everything is sorted worst-first.
                    </li>
                </ol>
            </div>
        </section>

    </main>
</div>

<!-- ── New Lease Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="leaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content custom-modal">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">New lease contract</h5>
                    <small class="text-muted">For a tenant who did not come through Stall Applications.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="leaseForm" novalidate>
                    <input type="hidden" name="action" value="create_lease">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tenant <span class="text-danger">*</span></label>
                            <select class="form-select" name="merchant_user_id" id="merchantUserId" required>
                                <option value="">Loading merchants&hellip;</option>
                            </select>
                            <small class="form-text" id="merchantPickerHint">The merchant account that will be billed.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Stall number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="stall_number" id="stallNumber"
                                   required placeholder="e.g. A-01">
                            <small class="form-text">As printed on the stall map.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Stall name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="stall_name" id="stallName"
                                   required placeholder="e.g. Green Hill Canteen">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Monthly rent (&#8369;) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="monthly_rent" id="monthlyRent"
                                   step="0.01" min="0.01" required placeholder="0.00">
                            <small class="form-text">Charged once per month for the whole term.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Lease start <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="lease_start" id="leaseStart" required>
                            <small class="form-text">Rent is charged on this day of every month.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Lease end <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="lease_end" id="leaseEnd" required>
                            <small class="form-text">Auto-set to one year after the start — change it if the term differs.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Security deposit (&#8369;)</label>
                            <input type="number" class="form-control" name="deposit_amount" id="depositAmount"
                                   step="0.01" min="0" value="0">
                            <small class="form-text">Recorded for reference only — it is not billed as rent.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Start billing</label>
                            <select class="form-select" name="status" id="leaseStatus">
                                <option value="active">Yes — the stall is open (Active)</option>
                                <option value="pending">Not yet — hold it (Pending)</option>
                            </select>
                            <small class="form-text">A Pending lease is charged nothing until you activate it.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Contract notes</label>
                            <textarea class="form-control" name="contract_notes" id="contractNotes" rows="2"
                                      placeholder="Terms, deposit arrangement, special conditions…"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="gp-inline-preview" id="leasePreview"></div>
                        </div>
                    </div>
                    <div id="leaseFormMsg" class="mt-3"></div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="login-btn" style="flex:1" id="leaseSubmitBtn">Create lease</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
window.leaseApiConfig = { endpoint: '<?= ADMIN_URL ?>/api/leases.php' };
</script>
<script src="<?= JS_URL ?>/admin_leases.js?v=4"></script>
<script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>
</body>
</html>
