<?php
require_once __DIR__ . "/../connection/config.php";
require_once __DIR__ . "/../connection/pdo.php";
require_once __DIR__ . "/../connection/app.php";
require_once __DIR__ . "/../connection/MerchantTenantDirectory.php";

gjc_require_role(['finance']);

$dashboard = gjc_admin_dashboard_data($db);
$demographics = $dashboard["user_demographics"];
$recentTransactions = $dashboard["recent_transactions"];
$moneyFlow = $dashboard["money_flow"];
// The Merchant/Tenant Directory is open to every finance account, matching who
// can open a stall's own page (admin/merchant_details.php).
$tenantCards = (new MerchantTenantDirectory($db))->directoryCards();

// Notification badge per tenant card: audited MANAGEMENT actions by the
// merchant (owner or their staff) — products, staff, profile, banned-item
// attempts — newer than the shared last-viewed stamp. Routine sales are
// deliberately excluded so the count stays meaningful. Opening a card's
// detail view stamps merchant_card_views and zeroes the badge for everyone.
$tenantNotifCounts = [];
if (!empty($tenantCards)) {
    gjc_ensure_merchant_card_views_schema($db);
    $staffJoin = in_array("merchant_owner_id", gjc_table_columns($db, "users"), true)
        ? "act.userID = m.userID OR act.merchant_owner_id = m.userID"
        : "act.userID = m.userID";
    $tenantNotifCounts = array_map(
        "intval",
        $db->query(
            "SELECT m.merchantID, COUNT(a.log_id) AS unread
               FROM merchant m
               JOIN users act ON {$staffJoin}
               JOIN systemic_audit_trail a ON a.user_id = act.userID
               LEFT JOIN merchant_card_views v ON v.merchant_id = m.merchantID
              WHERE a.action_type IN ('MENU_MUTATION', 'USER_ACCOUNT', 'MERCHANT_PROFILE_UPDATE', 'PRODUCT_RESTRICTION')
                AND (v.last_viewed_at IS NULL OR a.timestamp > v.last_viewed_at)
              GROUP BY m.merchantID",
        )->fetchAll(PDO::FETCH_KEY_PAIR),
    );
}

$queues = gjc_finance_queue_counts($db);
$queueTotal = array_sum($queues);
$currentUser = gjc_current_user($db);

$currentPage = "dashboard";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>Finance Dashboard | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="gp-theme">

    <div class="admin-layout">

        <?php require __DIR__ . "/../includes/partials/sidebar_admin.php"; ?>

        <main class="admin-main">

            <header class="topbar">
                <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>

                <div>
                    <h1>Finance Dashboard</h1>
                    <p>Start with anything waiting below, then review activity across the system.</p>
                </div>

                <div class="admin-user">
                    <span><?= htmlspecialchars($currentUser['name'] ?? 'Finance', ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="avatar">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                </div>
            </header>

            <section class="gp-attention mb-4" aria-labelledby="attentionHeading">
                <div class="gp-attention-head">
                    <h2 id="attentionHeading">Needs your attention</h2>
                    <?php if ($queueTotal > 0): ?>
                        <span class="gp-attention-total"><?= (int) $queueTotal ?> item<?= $queueTotal === 1 ? '' : 's' ?> waiting</span>
                    <?php endif; ?>
                </div>

                <?php
                // Each tile links to the exact queue, not just the page, so one
                // click lands on the rows to clear. A tile at zero stays visible
                // and greys out: a queue that vanishes when empty teaches the
                // user it might be hiding work somewhere.
                $attention = [
                    [
                        'label' => 'Encashments to release',
                        'count' => $queues['encashments'],
                        'icon'  => 'fa-money-check-dollar',
                        'href'  => ADMIN_URL . '/cash_management.php#cash-out',
                        'empty' => 'No merchants waiting',
                    ],
                    [
                        'label' => 'Stall applications',
                        'count' => $queues['stall_applications'],
                        'icon'  => 'fa-store',
                        'href'  => ADMIN_URL . '/stall_applications.php',
                        'empty' => 'Nothing to verify',
                    ],
                ];
                ?>

                <div class="gp-attention-grid">
                    <?php foreach ($attention as $item): ?>
                        <a class="gp-attention-card<?= $item['count'] > 0 ? ' is-active' : ' is-clear' ?>"
                           href="<?= $item['href'] ?>">
                            <span class="gp-attention-icon"><i class="fa-solid <?= $item['icon'] ?>"></i></span>
                            <span class="gp-attention-body">
                                <strong><?= $item['count'] > 0 ? (int) $item['count'] : '—' ?></strong>
                                <span class="gp-attention-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="gp-attention-sub"><?= $item['count'] > 0
                                    ? 'Open the queue'
                                    : htmlspecialchars($item['empty'], ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="premium-panel mb-4" id="tenantDirectoryPanel">
                <div class="panel-header d-flex justify-content-between align-items-center">
                    <div>
                        <h3>Merchant/Tenant Directory</h3>
                        <p>Institutional lease, rent obligation, and inventory compliance overview.</p>
                    </div>
                    <a href="<?= ADMIN_URL ?>/leases.php" class="view-btn">Manage Leases &amp; Rent</a>
                </div>

                <?php if (empty($tenantCards)): ?>
                    <div class="text-center text-muted py-5">No merchant stalls are registered yet.</div>
                <?php else: ?>
                    <div class="row g-3" id="tenantDirectoryGrid">
                        <?php foreach ($tenantCards as $stall): ?>
                            <?php
                            $status = strtolower(
                                (string) $stall["operational_status"],
                            );
                            $leaseState = (string) ($stall["lease_state"] ?? "none");
                            $statusClass = in_array($status, ["active"], true)
                                ? "bg-success"
                                : (in_array(
                                    $status,
                                    ["suspended", "inactive"],
                                    true,
                                )
                                    ? "bg-danger"
                                    : "bg-warning text-dark");
                            // Mirrors the rent standing on admin/leases.php.
                            $leaseClass = match ($leaseState) {
                                "overdue" => "bg-danger",
                                "settled", "ahead" => "bg-success",
                                "pending" => "bg-warning text-dark",
                                default => "bg-secondary",
                            };
                            ?>
                            <div class="col-12 col-md-6 col-xl-4">
                                <a class="tenant-card"
                                    href="<?= ADMIN_URL ?>/merchant_details.php?token=<?= rawurlencode(
                                        gjc_make_view_token((int) $stall["merchant_id"], "merchant"),
                                    ) ?>"
                                    aria-label="Open stall detail view for <?= htmlspecialchars(
                                        $stall["stall_name"],
                                        ENT_QUOTES,
                                        "UTF-8",
                                    ) ?>">
                                    <?php $notif = $tenantNotifCounts[(int) $stall["merchant_id"]] ?? 0;
                                    if ($notif > 0): ?>
                                    <span class="tenant-card-notif"
                                        title="<?= (int) $notif ?> merchant action<?= $notif === 1 ? "" : "s" ?> since last check"><?= $notif > 99 ? "99+" : (int) $notif ?></span>
                                    <?php endif; ?>
                                    <span class="tenant-card-kicker">Stall #<?= (int) $stall[
                                        "merchant_id"
                                    ] ?></span>
                                    <strong><?= htmlspecialchars(
                                        $stall["stall_name"],
                                        ENT_QUOTES,
                                        "UTF-8",
                                    ) ?></strong>
                                    <span class="tenant-proprietor"><?= htmlspecialchars(
                                        $stall["proprietor_name"],
                                        ENT_QUOTES,
                                        "UTF-8",
                                    ) ?></span>
                                    <span class="tenant-badges">
                                        <span class="badge <?= $statusClass ?>"><?= htmlspecialchars(
    ucwords(str_replace("_", " ", $stall["operational_status"])),
    ENT_QUOTES,
    "UTF-8",
) ?></span>
                                        <span class="badge <?= $leaseClass ?>"><?= htmlspecialchars(
    $stall["lease_status"],
    ENT_QUOTES,
    "UTF-8",
) ?></span>
                                    </span>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="section-title mb-3">
                <h4 style="font-size: 18px; font-weight: 800; color: var(--emerald-950); margin: 0;">User Demographics</h4>
            </div>
            <section class="row g-3 mb-5">

                <div class="col-12 col-sm-6 col-xl-3">
                    <a href="<?= ADMIN_URL ?>/users.php?exclude_admin=1" class="mini-metric-card">
                        <div class="mini-icon-wrap">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div class="mini-metric-info">
                            <span>Total Users</span>
                            <h3><?php echo (int) $demographics[
                                "total_users"
                            ]; ?></h3>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <a href="<?= ADMIN_URL ?>/users.php?role=student" class="mini-metric-card">
                        <div class="mini-icon-wrap">
                            <i class="fa-solid fa-user-graduate"></i>
                        </div>
                        <div class="mini-metric-info">
                            <span>Active Students</span>
                            <h3><?php echo (int) $demographics[
                                "active_students"
                            ]; ?></h3>
                        </div>
                    </a>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <a href="<?= ADMIN_URL ?>/users.php?role=merchant" class="mini-metric-card">
                        <div class="mini-icon-wrap">
                            <i class="fa-solid fa-store"></i>
                        </div>
                        <div class="mini-metric-info">
                            <span>Active Merchants</span>
                            <h3><?php echo (int) $demographics[
                                "active_merchants"
                            ]; ?></h3>
                        </div>
                    </a>
                </div>

            </section>

            <section class="row g-4 mb-4">

                <div class="col-12 col-xl-8">
                    <div class="premium-panel">
                        <div class="panel-header">
                            <div>
                                <h3>Money Flow &middot; Last 7 Days</h3>
                                <p>Loaded into wallets, spent at stalls, and cashed out of the system</p>
                            </div>
                        </div>

                        <div class="chart-box">
                            <canvas id="moneyFlowChart" role="img" aria-label="Daily money loaded, spent and cashed out over the last 7 days"></canvas>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-4">
                    <div class="premium-panel h-100">
                        <div class="panel-header">
                            <div>
                                <h3>Quick Actions</h3>
                                <p>Frequently used admin tools</p>
                            </div>
                        </div>

                        <div class="quick-actions">
                            <a href="<?= ADMIN_URL ?>/users.php">
                                <span>Manage Users</span>
                                <b>&rsaquo;</b>
                            </a>

                            <a href="<?= ADMIN_URL ?>/cash_management.php">
                                <span>Cash Management</span>
                                <b>&rsaquo;</b>
                            </a>

                            <a href="<?= ADMIN_URL ?>/transactions.php">
                                <span>All Transactions</span>
                                <b>&rsaquo;</b>
                            </a>

                            <a href="<?= ADMIN_URL ?>/economy.php">
                                <span>System Economy</span>
                                <b>&rsaquo;</b>
                            </a>
                        </div>
                    </div>
                </div>

            </section>

            <section class="premium-panel">
                <div class="panel-header d-flex justify-content-between align-items-center">
                    <div>
                        <h3>Recent / Latest Transactions</h3>
                        <p>Latest wallet activity across the system</p>
                    </div>

                    <a href="<?= ADMIN_URL ?>/transactions.php" class="view-btn">View All</a>
                </div>

                <div class="table-responsive">
                    <table class="table premium-table align-middle js-datatable" id="dashboardTransactionsTable" data-page-length="10"
                           data-empty-message="No wallet activity has been recorded yet.">
                        <thead>
                            <tr>
                                <th data-card="title">Reference</th>
                                <th>Type</th>
                                <th data-card="amount">Amount</th>
                                <th data-card="hide">Sender</th>
                                <th data-card="hide">Receiver</th>
                                <th>Status</th>
                                <th data-card="hide">Time</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($recentTransactions)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No transaction history is available yet.</td>
                            </tr>
                            <?php endif; ?>

                            <?php foreach (
                                $recentTransactions
                                as $transaction
                            ): ?>
                            <tr>
                                <td><?php echo gjc_e(
                                    $transaction["ref"],
                                ); ?></td>
                                <td><?php echo gjc_e(
                                    $transaction["type_label"],
                                ); ?></td>
                                <td><?php echo gjc_money(
                                    $transaction["amount"],
                                ); ?></td>
                                <td><?php echo gjc_e(
                                    $transaction["sender"],
                                ); ?></td>
                                <td><?php echo gjc_e(
                                    $transaction["receiver"],
                                ); ?></td>
                                <td>
                                    <span class="<?php echo gjc_transaction_is_success(
                                        $transaction["status"],
                                    )
                                        ? "badge-success"
                                        : "badge-warning"; ?>">
                                        <?php echo gjc_e(
                                            $transaction["status_label"],
                                        ); ?>
                                    </span>
                                </td>
                                <td><?php echo gjc_e(
                                    $transaction["time_label"],
                                ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </main>

    </div>

    <script>
    window.dashboardMoneyFlow = <?php echo json_encode(
        $moneyFlow,
        JSON_UNESCAPED_SLASHES,
    ); ?>;
    </script>
    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
    <?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>
    <script src="<?= JS_URL ?>/dashboard_chart.js?v=4"></script>

    <script>
        function toggleSidebar() {
            document.getElementById("sidebar").classList.toggle("collapsed");
        }
    </script>

</body>

</html>
