<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';
require_once __DIR__ . '/../connection/MerchantTenantDirectory.php';

gjc_require_role(['merchant']);

$currentUser = gjc_current_user($db);
$ownerMerchId = gjc_merchant_owner_id($db, (int) $currentUser['id']);
$wallet = gjc_merchant_wallet($db, $ownerMerchId);
$currentBalance = $wallet['balance'];
$canEncash = !gjc_is_merchant_staff();

// The most reliable trigger for the rent reminder: owners open this page daily
// to run the POS, so their own visit is what fires it. Once per billing period.
if ($canEncash) {
    (new MerchantTenantDirectory($db))->dispatchRentReminders($ownerMerchId);
}

$stallStmt = $db->prepare("SELECT stall_name, stall_id FROM merchant WHERE userID = ? LIMIT 1");
$stallStmt->execute([$ownerMerchId]);
$stallRow = $stallStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$merchantDisplayName = $stallRow['stall_name'] ?? $currentUser['name'];
$merchantStallId = $stallRow['stall_id'] ?? '';
$totalEarned = 0;
$encashmentStatus = "Available";
$earningTypes = [CirculationEngine::TXN_PAYMENT];
$earningTypePlaceholders = implode(', ', array_fill(0, count($earningTypes), '?'));

gjc_ensure_sales_remittances_schema($db);
$unremittedSales = gjc_merchant_unremitted_sales($db, $wallet['id']);
$remittedToday = gjc_merchant_remitted_sales_today($db, $wallet['id']);
$todaysSales = $remittedToday['amount'];
$pendingSales = $unremittedSales['amount'];

$lastRemitToday = null;
if ($wallet['id'] > 0) {
    $lastRemitStmt = $db->prepare(
        "SELECT amount, reference_no, period_end FROM sales_remittances
          WHERE merchant_wallet_id = ? AND DATE(period_end) = CURDATE()
          ORDER BY period_end DESC LIMIT 1"
    );
    $lastRemitStmt->execute([$wallet['id']]);
    if ($row = $lastRemitStmt->fetch(PDO::FETCH_ASSOC)) {
        $lastRemitToday = [
            'amount' => (float) $row['amount'],
            'reference_no' => $row['reference_no'],
            'time' => date('h:i A', strtotime($row['period_end'])),
        ];
    }
}

if ($wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
    $totalStmt = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM transactions
          WHERE merchant_wallet_id = ?
            AND transaction_type IN ({$earningTypePlaceholders})"
    );
    $totalStmt->execute(array_merge([$wallet['id']], $earningTypes));
    $totalEarned = (float) $totalStmt->fetchColumn();
}

// ---- Voided/cancelled orders today (staff dashboard only) ------------------
$isMerchantStaff = !$canEncash;
$voidedOrdersToday = [];
if ($isMerchantStaff) {
    if (gjc_table_exists($db, 'cart_orders')) {
        $voidStmt = $db->prepare(
            "SELECT id, 'cart' AS source, reference_no AS reference, description, amount, created_at
               FROM cart_orders
              WHERE merchant_wallet_id IN (SELECT id FROM merchant_wallets WHERE user_id = ?)
                AND status = 'voided' AND DATE(created_at) = CURDATE()
              ORDER BY created_at DESC, id DESC LIMIT 8"
        );
        $voidStmt->execute([$ownerMerchId]);
        $voidedOrdersToday = array_merge($voidedOrdersToday, $voidStmt->fetchAll(PDO::FETCH_ASSOC));
    }
    if (gjc_table_exists($db, 'merchant_qr_orders')) {
        $voidStmt = $db->prepare(
            "SELECT id, 'qr' AS source, short_code AS reference, description, amount, created_at
               FROM merchant_qr_orders
              WHERE merchant_wallet_id IN (SELECT id FROM merchant_wallets WHERE user_id = ?)
                AND status = 'voided' AND DATE(created_at) = CURDATE()
              ORDER BY created_at DESC, id DESC LIMIT 8"
        );
        $voidStmt->execute([$ownerMerchId]);
        $voidedOrdersToday = array_merge($voidedOrdersToday, $voidStmt->fetchAll(PDO::FETCH_ASSOC));
    }
    usort($voidedOrdersToday, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    $voidedOrdersToday = array_slice($voidedOrdersToday, 0, 8);
}

// ---- Recent sales (staff dashboard only) ------------------------------------
$recentSales = [];
if ($isMerchantStaff && $wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
    $stmt = $db->prepare(
        "SELECT reference_no, transaction_type, amount, created_at
           FROM transactions
          WHERE merchant_wallet_id = ?
            AND transaction_type IN ({$earningTypePlaceholders})
          ORDER BY created_at DESC
          LIMIT 8"
    );
    $stmt->execute(array_merge([$wallet['id']], $earningTypes));
    $recentSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---- Inventory stock by category (staff dashboard only) ---------------------
$inventoryCategories = ['food', 'beverage', 'snack', 'supplies', 'service', 'general'];
$inventoryStockByCategory = array_fill_keys($inventoryCategories, 0);
if ($isMerchantStaff && gjc_table_exists($db, 'merchant_inventory')) {
    $stockStmt = $db->prepare(
        "SELECT category, COALESCE(SUM(stock_qty), 0) AS total_stock
           FROM merchant_inventory
          WHERE merchant_user_id = ?
          GROUP BY category"
    );
    $stockStmt->execute([$ownerMerchId]);
    foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['category'], $inventoryStockByCategory)) {
            $inventoryStockByCategory[$row['category']] = (int) $row['total_stock'];
        }
    }
}

// ---- Date-range captions for the sales charts/cards (owner dashboard only) ----
$dailyRangeLabel     = date('M j', strtotime('-6 days')) . ' – ' . date('M j');
$weekRangeStart      = date('Y-m-d', strtotime('monday this week'));
$weekRangeLabel      = date('M j', strtotime($weekRangeStart)) . ' – ' . date('M j', strtotime($weekRangeStart . ' +6 days'));
$monthRangeLabel     = date('F Y');
$currentQuarterLabel = 'Q' . (int) ceil(((int) date('n')) / 3) . ' ' . date('Y');
$yearRangeLabel      = date('Y');

// ---- Daily sales, last 7 days (owner dashboard only) --------------------------
$sevenDaySales = [];
if (!$isMerchantStaff && $wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
    $salesStmt = $db->prepare(
        "SELECT DATE(created_at) AS sale_date, COALESCE(SUM(amount), 0) AS total
           FROM transactions
          WHERE merchant_wallet_id = ?
            AND transaction_type IN ({$earningTypePlaceholders})
            AND created_at >= (CURDATE() - INTERVAL 6 DAY)
          GROUP BY DATE(created_at)"
    );
    $salesStmt->execute(array_merge([$wallet['id']], $earningTypes));
    $salesByDate = array_column($salesStmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'sale_date');

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $sevenDaySales[] = [
            'label'  => date('D', strtotime($date)),
            'amount' => (float) ($salesByDate[$date] ?? 0),
        ];
    }
}

// ---- Weekly/monthly/quarterly/yearly sales totals (owner dashboard only) ------
$periodSales = ['weekly' => 0.0, 'monthly' => 0.0, 'quarterly' => 0.0, 'yearly' => 0.0];
if (!$isMerchantStaff && $wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
    $periodStmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN YEARWEEK(created_at, 3) = YEARWEEK(CURDATE(), 3) THEN amount ELSE 0 END), 0) AS weekly,
            COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) THEN amount ELSE 0 END), 0) AS monthly,
            COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND QUARTER(created_at) = QUARTER(CURDATE()) THEN amount ELSE 0 END), 0) AS quarterly,
            COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) THEN amount ELSE 0 END), 0) AS yearly
           FROM transactions
          WHERE merchant_wallet_id = ?
            AND transaction_type IN ({$earningTypePlaceholders})
            AND created_at >= (CURDATE() - INTERVAL 1 YEAR)"
    );
    $periodStmt->execute(array_merge([$wallet['id']], $earningTypes));
    if ($row = $periodStmt->fetch(PDO::FETCH_ASSOC)) {
        $periodSales = array_map('floatval', $row);
    }
}

// ---- Mini bar-chart breakdowns for the period cards (owner dashboard only) ----
$weeklyBreakdown = $monthlyBreakdown = $quarterlyBreakdown = $yearlyBreakdown = [];
if (!$isMerchantStaff && $wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
    $weekStart  = date('Y-m-d', strtotime('monday this week'));
    $monthStart = date('Y-m-01');
    $yearStart  = date('Y-01-01');
    $sinceDaily = min($weekStart, $monthStart);

    // Daily buckets power the Weekly (7 days) and Monthly (weeks-in-month) bars.
    $dailyStmt = $db->prepare(
        "SELECT DATE(created_at) AS d, COALESCE(SUM(amount), 0) AS total
           FROM transactions
          WHERE merchant_wallet_id = ?
            AND transaction_type IN ({$earningTypePlaceholders})
            AND created_at >= ?
          GROUP BY DATE(created_at)"
    );
    $dailyStmt->execute(array_merge([$wallet['id']], $earningTypes, [$sinceDaily]));
    $byDate = array_column($dailyStmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'd');

    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime("{$weekStart} +{$i} days"));
        $weeklyBreakdown[] = ['label' => date('D', strtotime($date)), 'amount' => (float) ($byDate[$date] ?? 0)];
    }

    $daysInMonth = (int) date('t');
    $weekBuckets = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = date('Y-m-') . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
        $weekIndex = intdiv($d - 1, 7);
        $weekBuckets[$weekIndex] = ($weekBuckets[$weekIndex] ?? 0) + (float) ($byDate[$date] ?? 0);
    }
    foreach ($weekBuckets as $idx => $total) {
        $monthlyBreakdown[] = ['label' => 'Wk ' . ($idx + 1), 'amount' => $total];
    }

    // Monthly buckets power the Quarterly (3 months) and Yearly (12 months) bars.
    $monthlyStmt = $db->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(amount), 0) AS total
           FROM transactions
          WHERE merchant_wallet_id = ?
            AND transaction_type IN ({$earningTypePlaceholders})
            AND created_at >= ?
          GROUP BY ym"
    );
    $monthlyStmt->execute(array_merge([$wallet['id']], $earningTypes, [$yearStart]));
    $byMonth = array_column($monthlyStmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'ym');

    $currentQuarter = (int) ceil(((int) date('n')) / 3);
    $qStartMonth = ($currentQuarter - 1) * 3 + 1;
    for ($m = 0; $m < 3; $m++) {
        $ym = date('Y') . '-' . str_pad((string) ($qStartMonth + $m), 2, '0', STR_PAD_LEFT);
        $quarterlyBreakdown[] = ['label' => date('M', strtotime($ym . '-01')), 'amount' => (float) ($byMonth[$ym] ?? 0)];
    }

    for ($m = 1; $m <= 12; $m++) {
        $ym = date('Y') . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        $yearlyBreakdown[] = ['label' => date('M', strtotime($ym . '-01')), 'amount' => (float) ($byMonth[$ym] ?? 0)];
    }
}

$currentPage = 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=63">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="gp-theme">

    <div class="merchant-layout">

        <?php require __DIR__ . '/../includes/partials/' . (gjc_is_merchant_staff() ? 'sidebar_merchant_staff.php' : 'sidebar_merchant_admin.php'); ?>

        <main class="merchant-main">

            <?php
            $topbarTitle = 'Dashboard';
            $topbarSubtitle = 'Monitor sales, balance, QR payments' . ($canEncash ? ', and encashment activity' : '') . '.';
            require __DIR__ . '/../includes/partials/topbar_merchant.php';
            ?>

            <section class="sd-balance mb-4">
                <div>
                    <span class="sd-balance-label">GenCoin Balance</span>
                    <div class="sd-balance-amount">
                        <span><?php echo number_format($currentBalance / 10, 1); ?></span><span class="sd-unit">GC</span>
                    </div>
                    <div class="sd-gc-row">
                        <span class="sd-gc-badge">&#8776; <?php echo gjc_money($currentBalance); ?></span>
                        <span class="sd-gc-rate">&#8369;10 = 1 GC</span>
                    </div>
                    <div class="sd-balance-actions">
                        <a class="sd-btn-solid" href="<?= MERCHANT_URL ?>/pos.php"><i class="fa-solid fa-cash-register"></i>Open POS</a>
                        <a class="sd-btn-ghost" href="<?= MERCHANT_URL ?>/pos.php?open=loadwallet"><i class="fa-solid fa-coins"></i>Send GenCoin</a>
                        <?php if ($canEncash): ?>
                        <a class="sd-btn-ghost" href="<?= MERCHANT_URL ?>/encash.php"><i class="fa-solid fa-money-bill-wave"></i>Encash</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="sd-balance-holder">
                    <strong><?php echo gjc_e($merchantDisplayName); ?></strong>
                    <span class="sd-holder-id"><?php echo gjc_e($merchantStallId ?: 'Stall not yet assigned'); ?></span>
                    <span class="sd-role-badge"><?php echo $canEncash ? 'MERCHANT ADMIN' : 'MERCHANT STAFF'; ?></span>
                </div>
            </section>

            <?php /* Phone only: on desktop the sidebar already links to all of these. */ ?>
            <section class="sd-quick mb-4 d-md-none">
                <a href="<?= MERCHANT_URL ?>/pos.php">
                    <span class="sd-quick-icon is-scan"><i class="fa-solid fa-cash-register"></i></span>
                    <span>POS</span>
                </a>
                <a href="<?= MERCHANT_URL ?>/inventory.php">
                    <span class="sd-quick-icon is-cart"><i class="fa-solid fa-boxes-stacked"></i></span>
                    <span>Inventory</span>
                </a>
                <a href="<?= MERCHANT_URL ?>/history.php">
                    <span class="sd-quick-icon is-history"><i class="fa-solid fa-receipt"></i></span>
                    <span>History</span>
                </a>
                <?php if ($canEncash): ?>
                <a href="<?= MERCHANT_URL ?>/rent.php">
                    <span class="sd-quick-icon is-rent"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                    <span>Rent</span>
                </a>
                <?php endif; ?>
                <a href="<?= $canEncash ? MERCHANT_URL . '/settings.php' : MERCHANT_URL . '/dashboard.php' ?>">
                    <span class="sd-quick-icon is-profile"><i class="fa-solid fa-store"></i></span>
                    <span>Business Profile</span>
                </a>
            </section>

            <section class="sd-stats mb-4">

                <div class="sd-stat" id="todaysSalesCard" role="button" tabindex="0" style="cursor:pointer">
                    <div class="sd-stat-top">
                        <span>Today's Sales</span>
                        <span class="sd-stat-icon is-spent"><i class="fa-solid fa-chart-line"></i></span>
                    </div>
                    <h2 class="sd-num" id="todaysSalesValue"><?php echo gjc_money($todaysSales); ?></h2>
                    <p>Admin sales and staff remittances today &middot; click to view all</p>
                </div>

                <?php if ($isMerchantStaff): ?>
                <div class="sd-stat sd-stat--remit">
                    <div class="sd-stat-top">
                        <span>Sales Remittance</span>
                    </div>
                    <p id="pendingRemitInfo">Pending: <?php echo gjc_money($pendingSales); ?></p>
                    <button type="button" id="remitSalesBtn" class="sd-btn-solid sd-remit-btn">
                        <i class="fa-solid fa-paper-plane"></i> Remit Sales
                    </button>
                    <p id="lastRemitInfo">
                        <?php echo $lastRemitToday
                            ? 'Last remitted ' . gjc_e($lastRemitToday['time']) . ' &middot; ' . gjc_money($lastRemitToday['amount'])
                            : 'No remittance yet today'; ?>
                    </p>
                </div>
                <?php else: ?>
                <div class="sd-stat">
                    <div class="sd-stat-top">
                        <span>Total Earned</span>
                        <span class="sd-stat-icon is-txns"><i class="fa-solid fa-money-bill-wave"></i></span>
                    </div>
                    <h2 class="sd-num" id="totalEarnedValue"><?php echo gjc_money($totalEarned); ?></h2>
                    <p>Lifetime merchant earnings</p>
                </div>
                <?php endif; ?>

                <?php if ($canEncash): ?>
                <div class="sd-stat sd-stat--status">
                    <div class="sd-stat-top">
                        <span>Encashment Status</span>
                        <span class="sd-stat-icon is-status"><i class="fa-solid fa-money-check-dollar"></i></span>
                    </div>
                    <h2><?php echo $encashmentStatus; ?></h2>
                    <p>Request at anytime</p>
                </div>
                <?php endif; ?>

            </section>

            <section class="row g-4 mb-4">

                <div class="col-12 col-xl-8">
                    <div class="merchant-premium-panel">
                        <?php if ($isMerchantStaff): ?>
                        <div class="merchant-panel-header">
                            <div>
                                <h3>Inventory Stock by Category</h3>
                                <p>Total stock quantity currently on record, per category</p>
                            </div>
                        </div>

                        <div class="merchant-chart-box">
                            <canvas id="merchantInventoryChart"></canvas>
                        </div>
                        <?php else: ?>
                        <div class="merchant-panel-header">
                            <div>
                                <h3>Daily Sales</h3>
                                <p>Daily merchant wallet payment performance &middot; <?php echo gjc_e($dailyRangeLabel); ?></p>
                            </div>
                        </div>

                        <div class="merchant-chart-box">
                            <canvas id="merchantSalesChart"></canvas>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isMerchantStaff): ?>
                <div class="col-12 col-xl-4">
                    <div class="merchant-premium-panel h-100 merchant-voided-panel">
                        <div class="merchant-panel-header">
                            <div>
                                <h3>Voided Transactions</h3>
                                <p>Today's orders cancelled before payment</p>
                            </div>
                        </div>

                        <?php if (empty($voidedOrdersToday)): ?>
                        <div class="text-center text-muted py-4">No voided orders today.</div>
                        <?php else: ?>
                        <div class="merchant-voided-list" tabindex="0" aria-label="Today's voided transactions">
                            <?php foreach ($voidedOrdersToday as $vo): ?>
                            <a class="merchant-voided-row" href="<?= MERCHANT_URL ?>/voided_transactions.php?source=<?= gjc_e($vo['source']) ?>&amp;token=<?= urlencode(gjc_make_view_token((int) $vo['id'], 'merchant_voided_' . $vo['source'])) ?>">
                                <div class="merchant-voided-description">
                                    <div style="font-weight:700"><?= gjc_e($vo['reference']) ?></div>
                                    <div style="font-size:12px;color:var(--text-muted)"><?= gjc_e($vo['description'] ?: 'Order') ?></div>
                                </div>
                                <div class="text-end">
                                    <div style="font-weight:700"><?= gjc_gc_price((float) $vo['amount']) ?></div>
                                    <div style="font-size:12px;color:var(--text-muted)"><?= date('h:i A', strtotime($vo['created_at'])) ?></div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <a href="<?= MERCHANT_URL ?>/voided_transactions.php" class="merchant-view-btn merchant-voided-all">Show all voided transactions <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </div>
                <?php else: ?>
                <div class="col-12 col-xl-4">
                    <div class="merchant-metric-card merchant-metric-card--chart h-100">
                        <span>Weekly Sales</span>
                        <h2><?php echo gjc_money($periodSales['weekly']); ?></h2>
                        <p><?php echo gjc_e($weekRangeLabel); ?></p>
                        <div class="merchant-metric-chart">
                            <canvas id="weeklyMiniChart"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </section>

            <?php if (!$isMerchantStaff): ?>
            <section class="row g-4 mb-4">
                <div class="col-12 col-md-4">
                    <div class="merchant-metric-card merchant-metric-card--chart h-100">
                        <span>Monthly Sales</span>
                        <h2><?php echo gjc_money($periodSales['monthly']); ?></h2>
                        <p><?php echo gjc_e($monthRangeLabel); ?></p>
                        <div class="merchant-metric-chart">
                            <canvas id="monthlyMiniChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="merchant-metric-card merchant-metric-card--chart h-100">
                        <span>Quarterly Sales</span>
                        <h2><?php echo gjc_money($periodSales['quarterly']); ?></h2>
                        <p><?php echo gjc_e($currentQuarterLabel); ?></p>
                        <div class="merchant-metric-chart">
                            <canvas id="quarterlyMiniChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="merchant-metric-card merchant-metric-card--chart h-100">
                        <span>Yearly Sales</span>
                        <h2><?php echo gjc_money($periodSales['yearly']); ?></h2>
                        <p><?php echo gjc_e($yearRangeLabel); ?></p>
                        <div class="merchant-metric-chart">
                            <canvas id="yearlyMiniChart"></canvas>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if (!$isMerchantStaff): ?>
            <?php

            $mce        = new CirculationEngine($db);
            $mceSnap    = $mce->getCirculationSnapshot();
            $mceCap     = max((float)($mceSnap['cap']                  ?? 1), 0.01);
            $mceVault   = (float)($mceSnap['vault']                    ?? 0);
            $mceMerch   = (float)($mceSnap['merchant_wallets_total']   ?? 0);
            $mceStudents= (float)($mceSnap['student_wallets_total']    ?? 0);
            $mceBalanced = abs((float)($mceSnap['circulation_drift']   ?? 0)) < 0.01;
            $mceMerchPct = $mceCap > 0 ? round(($mceMerch   / $mceCap) * 100, 1) : 0;
            $mceVaultPct = $mceCap > 0 ? round(($mceVault   / $mceCap) * 100, 1) : 0;
            $mceStudPct  = $mceCap > 0 ? round(($mceStudents/ $mceCap) * 100, 1) : 0;
            ?>

            
            <section class="me-section mb-4">

                
                <div class="me-header">
                    <div class="me-header-left">
                        <span class="me-pill">
                            <i class="fa-solid fa-shop me-pill-icon"></i>  Economy Status
                        </span>
                        <span class="me-status-badge <?= $mceBalanced ? 'me-badge-ok' : 'me-badge-err' ?>">
                            <span class="me-dot <?= $mceBalanced ? 'me-dot-green' : 'me-dot-red me-pulse' ?>"></span>
                            <?= $mceBalanced ? 'System Balanced' : 'Under Review' ?>
                        </span>
                    </div>
                    <p class="me-header-sub">Live snapshot of the campus economy. Your wallet is part of this closed loop.</p>
                </div>

                
                <div class="me-hero-strip">
                    <div class="me-hero-stat">
                        <span>Circulation Cap</span>
                        <strong>₱<?= number_format($mceCap, 2) ?></strong>
                        <small>Total authorized supply</small>
                    </div>
                    <div class="me-hero-divider"></div>
                    <div class="me-hero-stat">
                        <span>Cashier Vault</span>
                        <strong>₱<?= number_format($mceVault, 2) ?></strong>
                        <small>Ready for top-ups</small>
                    </div>
                    <div class="me-hero-divider"></div>
                    <div class="me-hero-stat">
                        <span>Student Pool</span>
                        <strong>₱<?= number_format($mceStudents, 2) ?></strong>
                        <small>Spendable by students</small>
                    </div>
                    <div class="me-hero-divider"></div>
                    <div class="me-hero-stat me-hero-highlight">
                        <span>Merchant Pool</span>
                        <strong>₱<?= number_format($mceMerch, 2) ?></strong>
                        <small><?= $canEncash ? 'Pending encashment' : 'Store earnings pool' ?></small>
                    </div>
                    <div class="me-hero-divider"></div>
                    <div class="me-hero-stat">
                        <span>Economy Health</span>
                        <strong class="<?= $mceBalanced ? 'me-text-green' : 'me-text-red' ?>">
                            <?= $mceBalanced ? ' Healthy' : ' Review' ?>
                        </strong>
                        <small><?= $mceBalanced ? 'All pools balanced' : 'Contact finance office' ?></small>
                    </div>
                </div>

                
                <div class="me-pool-grid">

                    
                    <div class="me-pool-card me-pool-merchant">
                        <div class="me-pool-glow"></div>
                        <div class="me-pool-top">
                            <div class="me-pool-icon">
                                <i class="fa-solid fa-shop"></i>
                            </div>
                            <span class="me-pool-badge">Your Pool</span>
                        </div>
                        <div class="me-pool-label">Merchant Wallets Total</div>
                        <div class="me-pool-value">₱<?= number_format($mceMerch, 2) ?></div>
                        <div class="me-pool-bar"><div class="me-pool-bar-fill" style="width:<?= $mceMerchPct ?>%"></div></div>
                        <div class="me-pool-meta"><?= $mceMerchPct ?>% of cap · <?= $canEncash ? 'Encashable at any time' : 'Tracked for merchant admin' ?></div>
                    </div>

                    
                    <div class="me-pool-card me-pool-vault">
                        <div class="me-pool-glow"></div>
                        <div class="me-pool-top">
                            <div class="me-pool-icon">
                                <i class="fa-solid fa-vault"></i>
                            </div>
                        </div>
                        <div class="me-pool-label">Cashier Vault Reserve</div>
                        <div class="me-pool-value">₱<?= number_format($mceVault, 2) ?></div>
                        <div class="me-pool-bar"><div class="me-pool-bar-fill" style="width:<?= $mceVaultPct ?>%"></div></div>
                        <div class="me-pool-meta"><?= $mceVaultPct ?>% of cap · Available for reloads</div>
                    </div>

                    
                    <div class="me-pool-card me-pool-students">
                        <div class="me-pool-glow"></div>
                        <div class="me-pool-top">
                            <div class="me-pool-icon">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </div>
                        </div>
                        <div class="me-pool-label">Student Wallets Total</div>
                        <div class="me-pool-value">₱<?= number_format($mceStudents, 2) ?></div>
                        <div class="me-pool-bar"><div class="me-pool-bar-fill" style="width:<?= $mceStudPct ?>%"></div></div>
                        <div class="me-pool-meta"><?= $mceStudPct ?>% of cap · Potential incoming payments</div>
                    </div>

                    
                    <?php if ($canEncash): ?>
                    <div class="me-pool-card me-pool-tip">
                        <div class="me-pool-glow"></div>
                        <div class="me-pool-top">
                            <div class="me-pool-icon">
                                <i class="fa-solid fa-money-check-dollar"></i>
                            </div>
                        </div>
                        <div class="me-pool-label">Encashment Flow</div>
                        <div class="me-pool-value" style="font-size:16px;line-height:1.4">
                            Your wallet → Vault → Finance Office
                        </div>
                        <div class="me-pool-meta">Points are converted to real PHP when you encash at the Accountancy Office.</div>
                    </div>
                    <?php endif; ?>

                </div>


                <?php if ($canEncash): ?>
                <div class="me-tip-row">
                    <span>Points in your merchant wallet can only be encashed - they cannot be used to pay other merchants. The campus economy is a closed loop; every peso is always tracked.</span>
                </div>
                <?php endif; ?>

            </section>
            <?php endif; ?>

            <?php if ($isMerchantStaff): ?>
            <section class="merchant-premium-panel">
                <div class="merchant-panel-header d-flex justify-content-between align-items-center">
                    <div>
                        <h3>Recent Sales</h3>
                        <p>Latest payments received by this merchant</p>
                    </div>

                    <a href="<?= MERCHANT_URL ?>/history.php" class="merchant-view-btn">View All</a>
                </div>

                <div class="table-responsive">
                    <table class="table merchant-premium-table align-middle js-datatable" id="merchantRecentSalesTable" data-page-length="8">
                        <thead>
                            <tr>
                                <th data-card="title">Ref</th>
                                <th data-card="hide">Description</th>
                                <th data-card="amount">Amount</th>
                                <th>Type</th>
                                <th>Time</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($recentSales as $sale): ?>
                            <tr>
                                <td><?php echo gjc_e($sale["reference_no"]); ?></td>
                                <td><?php echo gjc_e(ucwords(str_replace('_', ' ', $sale["transaction_type"]))); ?></td>
                                <td class="merchant-amount">+<?php echo gjc_money($sale["amount"]); ?></td>
                                <td><span class="merchant-type-pill">Payment</span></td>
                                <td><?php echo gjc_e(date('M d, h:i A', strtotime($sale["created_at"]))); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($recentSales)): ?>
                        <tfoot>
                            <tr>
                                <th colspan="2" class="text-end">Subtotal</th>
                                <th class="merchant-amount">+<?php echo gjc_money(array_sum(array_column($recentSales, 'amount'))); ?></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </section>
            <?php endif; ?>

        </main>

    </div>

    <div class="modal fade" id="todaysSalesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content custom-modal">
                <div class="modal-header">
                    <h5 class="modal-title">Today's Sales</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="todaysSalesModalLoading" class="text-center text-muted py-4">Loading...</div>
                    <div class="table-responsive" id="todaysSalesModalTableWrap" style="display:none; max-height:60vh; overflow-y:auto">
                        <table class="table merchant-premium-table align-middle">
                            <thead>
                                <tr>
                                    <th data-card="title">Ref</th>
                                    <th data-card="hide">Description</th>
                                    <th>Items Bought</th>
                                    <th data-card="amount">Amount</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody id="todaysSalesModalBody"></tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">Subtotal</th>
                                    <th id="todaysSalesModalSubtotal" class="merchant-amount"></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div id="todaysSalesModalEmpty" class="text-center text-muted py-4" style="display:none">No sales recorded today yet.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>

    <?php if ($isMerchantStaff): ?>
    <script>
    (function () {
        const ctx = document.getElementById('merchantInventoryChart');
        if (!ctx) return;

        const labels = <?= json_encode(array_map('ucfirst', array_keys($inventoryStockByCategory))) ?>;
        const data = <?= json_encode(array_values($inventoryStockByCategory)) ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Stock Quantity',
                    data: data,
                    backgroundColor: '#1e5c3a',
                    borderRadius: 6,
                    maxBarThickness: 56,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                },
                scales: {
                    x: {
                        ticks: { color: '#6b7a70' },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#6b7a70', precision: 0 },
                        grid: { color: 'rgba(13, 36, 24, 0.06)' },
                    },
                },
            },
        });
    })();
    </script>
    <?php else: ?>
    <script>
    (function () {
        const ctx = document.getElementById('merchantSalesChart');
        if (!ctx) return;

        const labels = <?= json_encode(array_column($sevenDaySales, 'label')) ?>;
        const data = <?= json_encode(array_column($sevenDaySales, 'amount')) ?>;

        const formatPeso = (amount) => '₱' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Sales',
                    data: data,
                    backgroundColor: '#1e5c3a',
                    borderRadius: 6,
                    maxBarThickness: 56,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (item) => ' ' + formatPeso(item.parsed.y),
                        },
                    },
                },
                scales: {
                    x: {
                        ticks: { color: '#6b7a70' },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#6b7a70',
                            callback: (value) => formatPeso(value),
                        },
                        grid: { color: 'rgba(13, 36, 24, 0.06)' },
                    },
                },
            },
        });
    })();

    (function () {
        const buildMiniBarChart = (canvasId, points) => {
            const el = document.getElementById(canvasId);
            if (!el || !points.length) return;

            new Chart(el, {
                type: 'bar',
                data: {
                    labels: points.map((p) => p.label),
                    datasets: [{
                        data: points.map((p) => p.amount),
                        backgroundColor: '#1e5c3a',
                        borderRadius: 3,
                        maxBarThickness: 22,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (item) => ' ₱' + Number(item.parsed.y).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                            },
                        },
                    },
                    scales: {
                        x: {
                            ticks: { color: '#6b7a70', font: { size: 9 } },
                            grid: { display: false },
                        },
                        y: { display: false, beginAtZero: true },
                    },
                },
            });
        };

        buildMiniBarChart('weeklyMiniChart', <?= json_encode($weeklyBreakdown) ?>);
        buildMiniBarChart('monthlyMiniChart', <?= json_encode($monthlyBreakdown) ?>);
        buildMiniBarChart('quarterlyMiniChart', <?= json_encode($quarterlyBreakdown) ?>);
        buildMiniBarChart('yearlyMiniChart', <?= json_encode($yearlyBreakdown) ?>);
    })();
    </script>
    <?php endif; ?>

    <script>
    const LIVE_QUEUE_API = '<?= MERCHANT_URL ?>/api/pos.php';
    // ── Live Sales Summary (Today's Sales / Total Earned / Remittance) ──────────
    const todaysSalesValue = document.getElementById('todaysSalesValue');
    const totalEarnedValue = document.getElementById('totalEarnedValue');
    const lastRemitInfo = document.getElementById('lastRemitInfo');
    const pendingRemitInfo = document.getElementById('pendingRemitInfo');

    function formatMoney(amount) {
        return '&#8369;' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    async function refreshSalesSummary() {
        try {
            const fd = new FormData();
            fd.append('action', 'get_sales_summary');
            const res = await fetch(LIVE_QUEUE_API, { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.success) return;

            todaysSalesValue.innerHTML = formatMoney(data.todays_sales);
            if (totalEarnedValue) {
                totalEarnedValue.innerHTML = formatMoney(data.total_earned);
            }
            if (pendingRemitInfo) {
                pendingRemitInfo.innerHTML = 'Pending: ' + formatMoney(data.pending_amount);
            }
            if (lastRemitInfo) {
                lastRemitInfo.textContent = data.last_remit
                    ? `Last remitted ${data.last_remit.time} · ₱${Number(data.last_remit.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                    : 'No remittance yet today';
            }
        } catch (error) {
            // Keep showing the last known values on a transient network error.
        }
    }

    setInterval(refreshSalesSummary, 5000);

    // ── Remit Sales (one click, self-declared, prints a report) ─────────────────
    const remitSalesBtn = document.getElementById('remitSalesBtn');
    if (remitSalesBtn) {
        remitSalesBtn.addEventListener('click', async () => {
            remitSalesBtn.disabled = true;
            const originalHtml = remitSalesBtn.innerHTML;
            remitSalesBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Remitting…';

            try {
                const fd = new FormData();
                fd.append('action', 'remit_sales');
                fd.append('csrf_token', <?php echo json_encode(gjc_csrf_token()); ?>);
                const res = await fetch(LIVE_QUEUE_API, { method: 'POST', body: fd });
                const data = await res.json();

                if (!data.success) {
                    alert(data.message || 'Unable to remit sales.');
                    return;
                }

                lastRemitInfo.textContent = `Last remitted ${data.time} · ₱${Number(data.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                refreshSalesSummary();
                window.open(data.report_url, '_blank');
            } catch (error) {
                alert('Network error. Please try again.');
            } finally {
                remitSalesBtn.disabled = false;
                remitSalesBtn.innerHTML = originalHtml;
            }
        });
    }

    // ── Today's Sales Modal ──────────────────────────────────────────────────────
    const todaysSalesCard = document.getElementById('todaysSalesCard');
    const todaysSalesModalEl = document.getElementById('todaysSalesModal');
    const todaysSalesModal = bootstrap.Modal.getOrCreateInstance(todaysSalesModalEl);
    const todaysSalesModalLoading = document.getElementById('todaysSalesModalLoading');
    const todaysSalesModalTableWrap = document.getElementById('todaysSalesModalTableWrap');
    const todaysSalesModalEmpty = document.getElementById('todaysSalesModalEmpty');
    const todaysSalesModalBody = document.getElementById('todaysSalesModalBody');

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    async function openTodaysSalesModal() {
        todaysSalesModal.show();
        todaysSalesModalLoading.style.display = '';
        todaysSalesModalTableWrap.style.display = 'none';
        todaysSalesModalEmpty.style.display = 'none';

        try {
            const fd = new FormData();
            fd.append('action', 'get_today_sales');
            const res = await fetch(LIVE_QUEUE_API, { method: 'POST', body: fd });
            const data = await res.json();

            todaysSalesModalLoading.style.display = 'none';

            if (!data.success || !data.sales.length) {
                todaysSalesModalEmpty.style.display = '';
                return;
            }

            todaysSalesModalBody.innerHTML = data.sales.map((sale) => `
                <tr>
                    <td>${escapeHtml(sale.reference_no)}</td>
                    <td>${escapeHtml(sale.description)}</td>
                    <td>${escapeHtml(sale.items)}</td>
                    <td class="merchant-amount">+&#8369;${Number(sale.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td>${escapeHtml(sale.time)}</td>
                </tr>
            `).join('');

            const subtotal = data.sales.reduce((sum, sale) => sum + Number(sale.amount || 0), 0);
            document.getElementById('todaysSalesModalSubtotal').textContent =
                '+₱' + subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            todaysSalesModalTableWrap.style.display = '';
        } catch (error) {
            todaysSalesModalLoading.style.display = 'none';
            todaysSalesModalEmpty.textContent = 'Could not load today\'s sales. Please try again.';
            todaysSalesModalEmpty.style.display = '';
        }
    }

    todaysSalesCard.addEventListener('click', openTodaysSalesModal);
    todaysSalesCard.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openTodaysSalesModal();
        }
    });
    </script>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>

</html>

