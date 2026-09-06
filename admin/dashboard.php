<?php
require_once __DIR__ . "/../connection/config.php";
require_once __DIR__ . "/../connection/pdo.php";
require_once __DIR__ . "/../connection/app.php";
require_once __DIR__ . "/../connection/MerchantTenantDirectory.php";

gjc_require_role(['finance']);

$dashboard = gjc_admin_dashboard_data($db);
$demographics = $dashboard["user_demographics"];
$recentTransactions = $dashboard["recent_transactions"];
$transactionChart = $dashboard["transaction_chart"];
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
              WHERE a.action_type IN ('MENU_MUTATION', 'USER_ACCOUNT', 'PRODUCT_RESTRICTION')
                AND (v.last_viewed_at IS NULL OR a.timestamp > v.last_viewed_at)
              GROUP BY m.merchantID",
        )->fetchAll(PDO::FETCH_KEY_PAIR),
    );
}

$currentPage = "dashboard";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>Admin Dashboard | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=35">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
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
                    <h1>Admin Dashboard</h1>
                    <p>Monitor wallet activity, top-ups, encashments, and system users.</p>
                </div>

                <div class="admin-user">
                    <span>Admin</span>
                    <div class="avatar">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                </div>
            </header>

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
                                <h3>7-Day Transaction Volume</h3>
                                <p>Daily wallet transaction performance</p>
                            </div>
                        </div>

                        <div class="chart-box">
                            <canvas id="transactionChart"></canvas>
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

                            <a href="<?= ADMIN_URL ?>/topups.php">
                                <span>Process Top-ups</span>
                                <b>&rsaquo;</b>
                            </a>

                            <a href="<?= ADMIN_URL ?>/encashments.php">
                                <span>Encashments</span>
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
                    <table class="table premium-table align-middle js-datatable" id="dashboardTransactionsTable" data-page-length="10">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Sender</th>
                                <th>Receiver</th>
                                <th>Status</th>
                                <th>Time</th>
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
    window.dashboardTransactionChart = <?php echo json_encode(
        $transactionChart,
        JSON_UNESCAPED_SLASHES,
    ); ?>;
    </script>
    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
    <script src="<?= JS_URL ?>/dashboard_chart.js?v=2"></script>

    <script>
        function toggleSidebar() {
            document.getElementById("sidebar").classList.toggle("collapsed");
        }
    </script>

</body>

</html>
