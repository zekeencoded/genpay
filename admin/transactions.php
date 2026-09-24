<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'type' => trim((string) ($_GET['type'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
];

$transactions = gjc_fetch_admin_transactions($db, $filters, 150);
$stats = gjc_admin_transaction_counts($db);
$typeOptions = gjc_transaction_type_options();
$statusOptions = gjc_transaction_status_options();

$hasFilters = $filters['search'] !== '' || $filters['type'] !== '' || $filters['status'] !== '';
$currentUser = gjc_current_user($db);

$currentPage = 'transactions';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>Transactions | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/transactions.css?v=12">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
</head>

<body class="gp-theme">

    <div class="admin-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

        <main class="admin-main transactions-page">

            <header class="topbar">
                <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>

                <div>
                    <h1>Transactions</h1>
                    <p>Monitor payments, top-ups, encashments, refunds, and wallet movement.</p>
                </div>

                <div class="admin-user">
                    <span><?= htmlspecialchars($currentUser['name'] ?? 'Finance', ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="avatar">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                </div>
            </header>

            <section class="transaction-stats-grid mb-4">

                <div class="transaction-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <span>Total Transactions</span>
                    <h2><?php echo (int) $stats['total_transactions']; ?></h2>
                    <p>All wallet activities</p>
                </div>

                <div class="transaction-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <span>Today's Volume</span>
                    <h2><?php echo gjc_money($stats['todays_volume']); ?></h2>
                    <p>Successful transactions today</p>
                </div>

                <div class="transaction-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                    <span>Pending Transactions</span>
                    <h2><?php echo (int) $stats['pending_transactions']; ?></h2>
                    <p>Needs review</p>
                </div>

                <div class="transaction-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <span>Completed Today</span>
                    <h2><?php echo (int) $stats['completed_today']; ?></h2>
                    <p>Successful activities</p>
                </div>

            </section>

            <section class="transactions-command-panel mb-4">

                <div class="transactions-panel-header">
                    <div>
                        <h3>Transaction Filters</h3>
                        <p>Search and filter wallet activity records.</p>
                    </div>

                    <a href="<?= ADMIN_URL ?>/export_transactions.php?<?= http_build_query($filters); ?>" class="export-btn">
                        Export
                    </a>
                </div>

                <form class="transactions-filter-grid" method="GET" action="<?= ADMIN_URL ?>/transactions.php">

                    <div class="premium-field search-field">
                        <label>Search Transaction</label>
                        <input type="text" name="search" placeholder="Reference, sender, receiver, or amount"
                            value="<?php echo gjc_e($filters['search']); ?>">
                    </div>

                    <div class="premium-field">
                        <label>Type</label>
                        <select name="type">
                            <?php foreach ($typeOptions as $value => $label): ?>
                            <option value="<?php echo gjc_e($value); ?>"
                                <?php echo $filters['type'] === $value ? 'selected' : ''; ?>>
                                <?php echo gjc_e($label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="premium-field">
                        <label>Status</label>
                        <select name="status">
                            <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?php echo gjc_e($value); ?>"
                                <?php echo $filters['status'] === $value ? 'selected' : ''; ?>>
                                <?php echo gjc_e($label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="filter-btn">
                        Filter
                    </button>

                    <?php if ($hasFilters): ?>
                    <a href="<?= ADMIN_URL ?>/transactions.php" class="btn btn-link">Clear filters</a>
                    <?php endif; ?>

                </form>

            </section>

            <section class="transactions-table-panel">

                <div class="transactions-table-header">
                    <div>
                        <h3>All Transactions</h3>
                        <p>
                            <?= $hasFilters ? 'Matching' : 'The' ?> most recent
                            <?= count($transactions) ?> wallet movement<?= count($transactions) === 1 ? '' : 's' ?>.
                            Use the filters above to narrow this down, or Export for the full range.
                        </p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table transactions-table align-middle js-datatable" id="transactionsTable" data-page-length="10"
                           data-hide-filter="true"
                           data-empty-message="<?= $hasFilters
                               ? 'No transactions match these filters. Clear them above to see everything.'
                               : 'No wallet activity has been recorded yet.' ?>">
                        <thead>
                            <tr>
                                <th data-card="title">Reference</th>
                                <th>Type</th>
                                <th data-card="amount">Amount</th>
                                <th data-card="hide">Sender</th>
                                <th data-card="hide">Receiver</th>
                                <th>Status</th>
                                <th data-card="hide">Time</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">No transactions matched the current filters.</td>
                            </tr>
                            <?php endif; ?>

                            <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td class="reference-text"><?php echo gjc_e($transaction['ref']); ?></td>

                                <td>
                                    <span class="type-pill <?php echo gjc_e($transaction['type_slug']); ?>">
                                        <?php echo gjc_e($transaction['type_label']); ?>
                                    </span>
                                </td>

                                <td class="transaction-amount"><?php echo gjc_money($transaction['amount']); ?></td>

                                <td>
                                    <div class="party-cell">
                                        <div class="party-avatar">
                                            <?php echo gjc_e(strtoupper(substr($transaction['sender'], 0, 1))); ?>
                                        </div>
                                        <strong><?php echo gjc_e($transaction['sender']); ?></strong>
                                    </div>
                                </td>

                                <td><?php echo gjc_e($transaction['receiver']); ?></td>

                                <td>
                                    <span class="transaction-status <?php echo gjc_e($transaction['status_slug']); ?>">
                                        <?php echo gjc_e($transaction['status_label']); ?>
                                    </span>
                                </td>

                                <td><?php echo gjc_e($transaction['time_label']); ?></td>

                                <td>
                                    <!-- The row id is signed and scoped to its source, so the
                                         address bar can't be edited into another record; the
                                         reference number is no longer a lookup key. -->
                                    <a href="<?= ADMIN_URL ?>/view_transaction.php?source=<?php echo gjc_e($transaction['source']); ?>&token=<?php echo urlencode(gjc_make_view_token((int) $transaction['id'], 'admin_txn_' . $transaction['source'])); ?>"
                                        class="details-btn">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>
                </div>

            </section>

        </main>

    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
    <?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>

    <script>
    function toggleSidebar() {
        document.getElementById("sidebar").classList.toggle("collapsed");
    }
    </script>

</body>

</html>
