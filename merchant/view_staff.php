<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';

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
gjc_ensure_sales_remittances_schema($db);

$staffId = gjc_verify_view_token($_GET['token'] ?? null, 'merchant_staff');

$staff = null;
if ($staffId !== null) {
    $stmt = $db->prepare(
        "SELECT * FROM users
          WHERE userID = ? AND merchant_owner_id = ? AND roleID = 6 AND staff_removed_at IS NULL
          LIMIT 1"
    );
    $stmt->execute([$staffId, $merchantUserId]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($staff) {
        $staff = gjc_with_user_address($db, $staff, (int) $staffId);
    }
}

if (!$staff) {
    http_response_code(404);
}

// Local escaper — the shared gjc_e() only casts to string, and this page
// renders staff-supplied names, emails and contact numbers.
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($staff) {
    $staffId = (int) $staff['userID'];
    $firstName = trim((string) ($staff['first_name'] ?? ''));
    $lastName  = trim((string) ($staff['last_name'] ?? ''));
    $fullName = trim(implode(' ', array_filter([
        $firstName,
        trim((string) ($staff['middle_name'] ?? '')),
        $lastName,
        trim((string) ($staff['suffix'] ?? '')),
    ], static fn($part) => $part !== '')));
    if ($fullName === '') {
        $fullName = 'Staff #' . $staffId;
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

    $subRole = trim((string) ($staff['sub_role'] ?? ''));
    $subRoleLabel = $subRole !== '' ? ucwords(str_replace('_', ' ', $subRole)) : 'Merchant Staff';

    $tenureDays = !empty($staff['created_at'])
        ? max(0, (int) floor((time() - strtotime((string) $staff['created_at'])) / 86400))
        : null;
    $tenureLabel = $tenureDays === null ? '—'
        : ($tenureDays === 0 ? 'Joined today' : number_format($tenureDays) . ' day' . ($tenureDays === 1 ? '' : 's'));

    $mustChangePw = !empty($staff['force_password_change']) || !empty($staff['is_first_login']);
    $address = gjc_format_address($staff);
    $sex = trim((string) ($staff['sex'] ?? ''));
    $dob = $staff['date_of_birth'] ?? null;

    // ── Remittances this staff member handed in ──────────────────────────────
    $merchantWallet = gjc_merchant_wallet($db, $merchantUserId);
    $walletId = (int) ($merchantWallet['id'] ?? 0);

    $remitStmt = $db->prepare(
        "SELECT reference_no, amount, txn_count, period_start, period_end, created_at
           FROM sales_remittances
          WHERE staff_user_id = ? AND merchant_user_id = ?
          ORDER BY created_at DESC"
    );
    $remitStmt->execute([$staffId, $merchantUserId]);
    $remits = $remitStmt->fetchAll(PDO::FETCH_ASSOC);

    $remitTotal = array_sum(array_map(static fn($r) => (float) $r['amount'], $remits));
    $remitMonth = array_sum(array_map(
        static fn($r) => date('Y-m', strtotime((string) $r['created_at'])) === date('Y-m') ? (float) $r['amount'] : 0.0,
        $remits
    ));
    $lastRemitAt = $remits[0]['created_at'] ?? null;

    // ── Sales attributed to this staff member ─────────────────────────────────
    // A sale carries no staff id: POS sales record the cashier in initiated_by,
    // but student QR checkouts record the student. So a sale is theirs when
    // they rang it up, or when it falls inside a remittance window they closed
    // (the same (period_start, period_end] rule gjc_merchant_unremitted_sales uses).
    $sales = [];
    if ($walletId > 0) {
        $salesStmt = $db->prepare(
            "SELECT t.id, t.reference_no, t.transaction_type, t.amount, t.notes, t.created_at,
                    u.first_name AS buyer_first, u.last_name AS buyer_last
               FROM transactions t
               LEFT JOIN student_wallets sw ON sw.id = t.student_wallet_id
               LEFT JOIN users u ON u.userID = sw.user_id
              WHERE t.merchant_wallet_id = ?
                AND t.transaction_type IN (?, ?)
                AND (
                    t.initiated_by = ?
                    OR EXISTS (
                        SELECT 1 FROM sales_remittances r
                         WHERE r.staff_user_id = ?
                           AND r.merchant_wallet_id = t.merchant_wallet_id
                           AND t.created_at >  r.period_start
                           AND t.created_at <= r.period_end
                    )
                )
              ORDER BY t.created_at DESC"
        );
        $salesStmt->execute([
            $walletId,
            CirculationEngine::TXN_PAYMENT,
            CirculationEngine::TXN_VOUCHER_PAYMENT,
            $staffId,
            $staffId,
        ]);
        $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Sales figures come from the ledger; remitted figures from the remittance
    // rows as recorded. The two can disagree on older data: before
    // config.php pinned Asia/Manila some remittances were stamped in UTC, and
    // their windows re-counted sales an earlier remit already covered.
    $salesCount = count($sales);
    $salesTotal = array_sum(array_map(static fn($s) => (float) $s['amount'], $sales));
    $avgSale = $salesCount > 0 ? $salesTotal / $salesCount : 0.0;
    $remitToday = array_sum(array_map(
        static fn($r) => date('Y-m-d', strtotime((string) $r['created_at'])) === date('Y-m-d') ? (float) $r['amount'] : 0.0,
        $remits
    ));
    $remitOvercount = $remitTotal - $salesTotal > 0.009;

    // Item summaries live in notes ("POS Sale: 2x Rice Meal, 1x Iced Tea").
    $itemsOf = static fn(array $s): string => preg_replace('/^POS Sale:\s*/i', '', trim((string) $s['notes']));
    $topItems = [];
    foreach ($sales as $s) {
        foreach (explode(',', $itemsOf($s)) as $chunk) {
            if (preg_match('/^\s*(\d+)\s*x\s+(.+?)\s*$/i', $chunk, $m)) {
                $name = $m[2];
                $topItems[$name] = ($topItems[$name] ?? 0) + (int) $m[1];
            }
        }
    }
    arsort($topItems);
    $topItems = array_slice($topItems, 0, 6, true);
    $topMax = $topItems ? max($topItems) : 1;

    // Last 7 days of attributed sales for the activity bars.
    $week = [];
    for ($i = 6; $i >= 0; $i--) {
        $week[date('Y-m-d', strtotime("-{$i} days"))] = 0.0;
    }
    foreach ($sales as $s) {
        $day = date('Y-m-d', strtotime((string) $s['created_at']));
        if (isset($week[$day])) {
            $week[$day] += (float) $s['amount'];
        }
    }
    $weekMax = max($week) ?: 1;

    $salesShown = array_slice($sales, 0, 100);
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
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=23">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=15">
    <style>
        .uv-wrap { max-width: 1080px; }
        .sv-strip { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }

        .sv-grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 18px; }
        .sv-grid > .uv-card { margin-top: 18px; }
        @media (max-width: 820px) { .sv-grid { grid-template-columns: 1fr; } }
        .sv-card-fields { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }

        /* 7-day activity bars */
        .sv-week { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; align-items: end; height: 150px; }
        .sv-day { display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end; min-width: 0; }
        .sv-bar { width: 100%; max-width: 34px; border-radius: 3px 3px 0 0; background: var(--gp-green-700); min-height: 3px; }
        .sv-bar.is-zero { background: var(--gp-line); }
        .sv-day.is-today .sv-bar { background: var(--gp-green-950); }
        .sv-day-label { font-size: 11px; font-weight: 700; color: var(--gp-muted); }
        .sv-day-amt { font-family: var(--gp-mono); font-size: 10px; color: var(--gp-muted); white-space: nowrap; }

        /* Top items */
        .sv-items { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
        .sv-item-row { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; font-weight: 700; color: var(--gp-ink); }
        .sv-item-row span:last-child { font-family: var(--gp-mono); color: var(--gp-muted); white-space: nowrap; }
        .sv-item-track { height: 6px; border-radius: 3px; background: var(--gp-line); margin-top: 6px; overflow: hidden; }
        .sv-item-fill { height: 100%; background: var(--gp-green-700); }

        /* Record tables */
        .sv-head-row { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .sv-search { position: relative; flex: 0 1 260px; }
        .sv-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--gp-muted); font-size: 13px; }
        .sv-search input {
            width: 100%; padding: 9px 12px 9px 34px; font: inherit; font-size: 14px;
            border: 1px solid var(--gp-line); border-radius: var(--gp-radius); background: #fff; color: var(--gp-ink);
        }
        .sv-table-wrap { overflow-x: auto; margin-top: 16px; border: 1px solid var(--gp-line); border-radius: var(--gp-radius); }
        .sv-table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 640px; }
        .sv-table th {
            text-align: left; font-size: 11px; font-weight: 800; text-transform: uppercase;
            letter-spacing: .06em !important; color: var(--gp-muted);
            background: var(--gp-row-hover, #f6f8f6); padding: 11px 14px; white-space: nowrap;
        }
        .sv-table td { padding: 12px 14px; border-top: 1px solid var(--gp-line); color: var(--gp-ink); vertical-align: top; }
        .sv-table tbody tr:hover td { background: var(--gp-row-hover, #f6f8f6); }
        .sv-table .num { text-align: right; font-family: var(--gp-mono); font-weight: 700; white-space: nowrap; }
        .sv-table .ref { font-family: var(--gp-mono); font-size: 13px; font-weight: 600; white-space: nowrap; }
        .sv-table .ref a { color: var(--gp-green-700); text-decoration: none; }
        .sv-table .ref a:hover { text-decoration: underline; }
        .sv-table .when { white-space: nowrap; }
        .sv-table .sub { display: block; font-size: 12px; color: var(--gp-muted); margin-top: 2px; }
        .sv-table .num .sub { font-weight: 500; }
        .sv-table .items { max-width: 320px; color: var(--gp-muted); }
        .sv-empty { padding: 28px 14px; text-align: center; color: var(--gp-muted); }
        .sv-foot { font-size: 12px; color: var(--gp-muted); margin-top: 10px; }
        .sv-type { display: inline-block; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: var(--gp-radius-tag); background: var(--emerald-soft-2, #e6efe8); color: var(--gp-green-700); white-space: nowrap; }
    </style>
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
            <div class="uv-hero-id">
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
                        <span class="uv-mono">#<?= $staffId ?></span>
                        <span class="uv-status <?= $statusClass ?>"><?= $e($statusLabel) ?></span>
                    </div>
                </div>
            </div>
            <div class="uv-hero-wallet">
                <span class="uv-hw-label">Total Remitted</span>
                <div class="uv-hw-value"><?= gjc_gc_amount($remitTotal) ?> GC</div>
                <span class="uv-hw-sub">&asymp; <?= gjc_money($remitTotal) ?> &middot; <?= count($remits) ?> remittance<?= count($remits) === 1 ? '' : 's' ?></span>
            </div>
        </section>

        <section class="uv-strip sv-strip">
            <div class="uv-tile">
                <div class="uv-tile-label">Sales Handled</div>
                <div class="uv-tile-value"><?= number_format($salesCount) ?></div>
                <div class="uv-tile-sub">Worth <?= gjc_gc_amount($salesTotal) ?> GC &middot; <?= gjc_money($salesTotal) ?></div>
            </div>
            <div class="uv-tile">
                <div class="uv-tile-label">Remitted Today</div>
                <div class="uv-tile-value"><?= gjc_gc_amount($remitToday) ?> GC</div>
                <div class="uv-tile-sub">&asymp; <?= gjc_money($remitToday) ?></div>
            </div>
            <div class="uv-tile">
                <div class="uv-tile-label">Average Sale</div>
                <div class="uv-tile-value"><?= gjc_gc_amount($avgSale) ?> GC</div>
                <div class="uv-tile-sub">&asymp; <?= gjc_money($avgSale) ?> per sale</div>
            </div>
            <div class="uv-tile">
                <div class="uv-tile-label">Remitted This Month</div>
                <div class="uv-tile-value"><?= gjc_gc_amount($remitMonth) ?> GC</div>
                <div class="uv-tile-sub">&asymp; <?= gjc_money($remitMonth) ?> &middot; <?= $e(date('M Y')) ?></div>
            </div>
            <div class="uv-tile">
                <div class="uv-tile-label">Last Remit</div>
                <div class="uv-tile-value" style="font-size:17px"><?= $lastRemitAt ? $e(gjc_date($lastRemitAt)) : 'Never' ?></div>
                <div class="uv-tile-sub"><?= $lastRemitAt ? $e(date('g:i A', strtotime((string) $lastRemitAt))) : 'No remittance yet' ?></div>
            </div>
        </section>

        <div class="sv-grid">
            <section class="gp-card uv-card">
                <div class="gp-card-head">
                    <div><h3>Last 7 days</h3><p>Sales attributed to <?= $e($firstName ?: 'this staff member') ?> per day.</p></div>
                </div>
                <div class="sv-week">
                    <?php foreach ($week as $day => $amt): ?>
                    <div class="sv-day <?= $day === date('Y-m-d') ? 'is-today' : '' ?>" title="<?= $e(date('D, M j', strtotime($day))) ?>">
                        <span class="sv-day-amt"><?= $amt > 0 ? gjc_gc_amount($amt) . ' GC' : '' ?></span>
                        <div class="sv-bar <?= $amt > 0 ? '' : 'is-zero' ?>" style="height: <?= $amt > 0 ? max(4, round($amt / $weekMax * 100)) : 2 ?>%"></div>
                        <span class="sv-day-label"><?= $e(date('D', strtotime($day))) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="gp-card uv-card">
                <div class="gp-card-head">
                    <div><h3>Top items sold</h3><p>By quantity across all attributed sales.</p></div>
                </div>
                <?php if (!$topItems): ?>
                <p class="uv-muted">No item data yet.</p>
                <?php else: ?>
                <ul class="sv-items">
                    <?php foreach ($topItems as $name => $qty): ?>
                    <li>
                        <div class="sv-item-row"><span><?= $e($name) ?></span><span><?= (int) $qty ?> sold</span></div>
                        <div class="sv-item-track"><div class="sv-item-fill" style="width: <?= round($qty / $topMax * 100) ?>%"></div></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
        </div>

        <div class="sv-grid">
            <section class="gp-card uv-card">
                <div class="gp-card-head">
                    <div><h3>Contact &amp; account</h3><p>Change the name or position from Staff Management.</p></div>
                </div>
                <div class="uv-fields sv-card-fields">
                    <?php
                    $field = static function (string $icon, string $label, string $value) use ($e): void {
                        echo '<div class="uv-field"><div class="uv-field-ic"><i class="' . $e($icon) . '"></i></div><div>'
                            . '<div class="uv-field-label">' . $e($label) . '</div>'
                            . '<div class="uv-field-value">' . $e($value) . '</div></div></div>';
                    };
                    $field('fa-regular fa-envelope', 'Email', trim((string) ($staff['email'] ?? '')) ?: '—');
                    $field('fa-solid fa-phone', 'Contact Number', trim((string) ($staff['contact_number'] ?? '')) ?: '—');
                    $field('fa-solid fa-id-badge', 'Position', $position);
                    $field('fa-solid fa-user-shield', 'Access Level', $subRoleLabel);
                    $field('fa-regular fa-calendar-plus', 'Date Hired', gjc_datetime($staff['created_at'] ?? null));
                    $field('fa-solid fa-hourglass-half', 'Time With Stall', $tenureLabel);
                    $field('fa-solid fa-key', 'Password', $mustChangePw ? 'Temporary — must change at next sign-in' : 'Set by staff');
                    $field('fa-solid fa-circle-check', 'Sign-in', $isActive ? 'Allowed — can use the POS' : 'Blocked — account inactive');
                    ?>
                </div>
            </section>

            <section class="gp-card uv-card">
                <div class="gp-card-head">
                    <div><h3>Personal details</h3><p>Maintained by the staff member from their profile.</p></div>
                </div>
                <div class="uv-fields sv-card-fields">
                    <?php
                    $field('fa-solid fa-venus-mars', 'Sex', $sex !== '' ? $sex : '—');
                    $field('fa-solid fa-hourglass', 'Age', gjc_age_label($dob));
                    $field('fa-solid fa-cake-candles', 'Date of Birth', gjc_dob_label($dob));
                    $field('fa-solid fa-location-dot', 'Address', $address !== '' ? $address : '—');
                    ?>
                </div>
            </section>
        </div>

        <section class="gp-card uv-card">
            <div class="sv-head-row">
                <div class="gp-card-head" style="margin:0">
                    <div><h3>Remittance history</h3><p>Every batch of sales <?= $e($firstName ?: 'they') ?> handed in.</p></div>
                </div>
            </div>
            <?php if ($remitOvercount): ?>
            <p class="sv-foot" style="margin-top:12px"><i class="fa-solid fa-circle-info"></i>
                Remitted totals (<?= gjc_gc_amount($remitTotal) ?> GC &asymp; <?= gjc_money($remitTotal) ?>) are higher than the matched sales (<?= gjc_gc_amount($salesTotal) ?> GC &asymp; <?= gjc_money($salesTotal) ?>).
                Some earlier remittances counted sales a previous remit had already covered.</p>
            <?php endif; ?>
            <div class="sv-table-wrap">
                <table class="sv-table">
                    <thead>
                        <tr><th>Reference</th><th>Remitted</th><th class="num">Sales</th><th class="num">Amount</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$remits): ?>
                        <tr><td colspan="4" class="sv-empty">No remittances yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($remits as $r): ?>
                        <tr>
                            <td class="ref">
                                <?php if (!empty($r['reference_no'])): ?>
                                <a href="<?= MERCHANT_URL ?>/remittance_report.php?ref=<?= urlencode((string) $r['reference_no']) ?>"><?= $e($r['reference_no']) ?></a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="when"><?= $e(gjc_datetime($r['created_at'])) ?></td>
                            <td class="num"><?= (int) $r['txn_count'] ?></td>
                            <td class="num"><?= gjc_gc_amount($r['amount']) ?> GC<span class="sub">&asymp; <?= gjc_money($r['amount']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="gp-card uv-card">
            <div class="sv-head-row">
                <div class="gp-card-head" style="margin:0">
                    <div><h3>Sales</h3><p>Sales they rang up or remitted, newest first.</p></div>
                </div>
                <?php if ($sales): ?>
                <div class="sv-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" id="salesSearch" placeholder="Search reference, buyer, item…" autocomplete="off">
                </div>
                <?php endif; ?>
            </div>
            <div class="sv-table-wrap">
                <table class="sv-table">
                    <thead>
                        <tr><th>Reference</th><th>Date &amp; Time</th><th>Buyer</th><th>Items</th><th class="num">Amount</th></tr>
                    </thead>
                    <tbody id="salesBody">
                    <?php if (!$sales): ?>
                        <tr><td colspan="5" class="sv-empty">No sales attributed to this staff member yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($salesShown as $s):
                        $buyer = trim(($s['buyer_first'] ?? '') . ' ' . ($s['buyer_last'] ?? ''));
                        $items = $itemsOf($s);
                        $isVoucher = $s['transaction_type'] === CirculationEngine::TXN_VOUCHER_PAYMENT;
                    ?>
                        <tr data-search="<?= $e(strtolower($s['reference_no'] . ' ' . $buyer . ' ' . $items)) ?>">
                            <td class="ref">
                                <a href="<?= MERCHANT_URL ?>/view_transaction.php?token=<?= urlencode(gjc_make_view_token((int) $s['id'], 'merchant_txn')) ?>"><?= $e($s['reference_no']) ?></a>
                                <?php if ($isVoucher): ?><span class="sub"><span class="sv-type">Voucher</span></span><?php endif; ?>
                            </td>
                            <td class="when"><?= $e(gjc_datetime($s['created_at'])) ?></td>
                            <td><?= $e($buyer !== '' ? $buyer : ($isVoucher ? 'Voucher holder' : 'Walk-in')) ?></td>
                            <td class="items"><?= $e($items !== '' ? $items : '—') ?></td>
                            <td class="num"><?= gjc_gc_amount($s['amount']) ?> GC<span class="sub">&asymp; <?= gjc_money($s['amount']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($sales): ?>
                        <tr id="salesNoMatch" hidden><td colspan="5" class="sv-empty">No sales match your search.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($salesCount > count($salesShown)): ?>
            <p class="sv-foot">Showing the latest <?= count($salesShown) ?> of <?= number_format($salesCount) ?> sales. Full history is in Sales History.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>

    <?php if ($staff && $sales): ?>
    <script>
    document.getElementById('salesSearch').addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        let shown = 0;
        document.querySelectorAll('#salesBody tr[data-search]').forEach(row => {
            const match = !q || row.dataset.search.includes(q);
            row.hidden = !match;
            if (match) shown++;
        });
        document.getElementById('salesNoMatch').hidden = shown > 0;
    });
    </script>
    <?php endif; ?>
</body>

</html>
