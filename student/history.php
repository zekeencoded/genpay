<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['student']);
gjc_enforce_student_lock($db);
gjc_ensure_school_year_schema($db);

$currentUser = gjc_current_user($db);
$wallet = gjc_student_wallet($db, $currentUser['id']);
$studentName = $currentUser['name'];

$currentBalance = (float) $wallet['balance'];
$totalReceived = 0.0;
$totalSpent = 0.0;
$transactions = [];

$schoolYears = $db->query("SELECT id, school_year_name FROM school_years ORDER BY school_year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$selectedSchoolYearId = (int) ($_GET['school_year'] ?? 0);
if ($selectedSchoolYearId > 0 && !in_array($selectedSchoolYearId, array_column($schoolYears, 'id'), true)) {
    $selectedSchoolYearId = 0;
}

if ($wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
    $syFilter = $selectedSchoolYearId > 0 ? ' AND school_year_id = ?' : '';
    $syParams = $selectedSchoolYearId > 0 ? [$wallet['id'], $selectedSchoolYearId] : [$wallet['id']];

    // Aggregates run over the whole ledger (not the capped fetch below),
    // so the totals stay correct even past 200 lifetime transactions.
    $spentStmt = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM transactions
          WHERE student_wallet_id = ? AND transaction_type IN ('payment', 'voucher_payment'){$syFilter}"
    );
    $spentStmt->execute($syParams);
    $totalSpent = (float) $spentStmt->fetchColumn();

    $receivedStmt = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM transactions
          WHERE student_wallet_id = ? AND transaction_type IN ('cash_in', 'topup', 'refund'){$syFilter}"
    );
    $receivedStmt->execute($syParams);
    $totalReceived = (float) $receivedStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT id, reference_no, transaction_type, amount, status, notes, created_at
           FROM transactions
          WHERE student_wallet_id = ?{$syFilter}
          ORDER BY created_at DESC, id DESC
          LIMIT 200"
    );
    $stmt->execute($syParams);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = (string) ($row['transaction_type'] ?? '');
        $amount = (float) ($row['amount'] ?? 0);

        $transactions[] = [
            // id + source feed the signed token on the View link below;
            // view_transaction.php re-checks ownership before rendering.
            'id' => (int) ($row['id'] ?? 0),
            'source' => 'ledger',
            'ref' => (string) ($row['reference_no'] ?: 'N/A'),
            'desc' => trim((string) ($row['notes'] ?? '')) ?: gjc_transaction_type_label($type),
            'type' => gjc_transaction_type_label($type),
            'amount' => $amount,
            'status' => gjc_transaction_status_label((string) ($row['status'] ?? 'completed')),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'date' => !empty($row['created_at']) ? date('M d, Y h:i A', strtotime((string) $row['created_at'])) : 'N/A',
        ];
    }
}

if ($selectedSchoolYearId === 0 && gjc_table_exists($db, 'topup_requests')) {
    $stmt = $db->prepare(
        "SELECT id, reference_no, amount, payment_method, status, created_at
           FROM topup_requests
          WHERE user_id = ? AND status <> 'approved'
          ORDER BY created_at DESC, id DESC
          LIMIT 100"
    );
    $stmt->execute([$currentUser['id']]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $transactions[] = [
            'id' => (int) ($row['id'] ?? 0),
            'source' => 'topup_request',
            'ref' => (string) ($row['reference_no'] ?: 'TOPUP-REQ'),
            'desc' => 'Payment method: ' . (string) ($row['payment_method'] ?? 'Cash at Cashier'),
            'type' => 'Top-up Request',
            'amount' => (float) ($row['amount'] ?? 0),
            'status' => gjc_transaction_status_label((string) ($row['status'] ?? 'pending')),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'date' => !empty($row['created_at']) ? date('M d, Y h:i A', strtotime((string) $row['created_at'])) : 'N/A',
        ];
    }
}

usort($transactions, function (array $a, array $b): int {
    return strcmp($b['created_at'], $a['created_at']);
});

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'history';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_student.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'Transaction History';
            $topbarSubtitle = 'Track all your wallet activity and payments.';
            $topbarShowBell = true;
            require __DIR__ . '/../includes/partials/topbar_student.php';
            ?>

            <div class="sd-content">

                <!-- Wallet totals -->
                <section class="sd-stats">
                    <div class="sd-stat">
                        <div class="sd-stat-top">
                            <span>Current Balance</span>
                            <span class="sd-stat-icon is-txns"><i class="fa-solid fa-wallet"></i></span>
                        </div>
                        <h2 class="sd-num" id="historyBalanceValue"><?= gjc_gc_amount($currentBalance) ?> GC</h2>
                        <p id="historyBalancePhp">&#8776; &#8369;<?= number_format($currentBalance, 2) ?> in your wallet</p>
                    </div>
                    <div class="sd-stat">
                        <div class="sd-stat-top">
                            <span>Total Received</span>
                            <span class="sd-stat-icon is-spent"><i class="fa-solid fa-arrow-down"></i></span>
                        </div>
                        <h2 class="sd-num" id="historyReceivedValue"><?= gjc_gc_amount($totalReceived) ?> GC</h2>
                        <p id="historyReceivedPhp">&#8776; &#8369;<?= number_format($totalReceived, 2) ?> in top-ups and refunds</p>
                    </div>
                    <div class="sd-stat sd-stat--wide">
                        <div class="sd-stat-top">
                            <span>Total Spent</span>
                            <span class="sd-stat-icon is-amber"><i class="fa-solid fa-arrow-trend-up"></i></span>
                        </div>
                        <h2 class="sd-num" id="historySpentValue"><?= gjc_gc_amount($totalSpent) ?> GC</h2>
                        <p id="historySpentPhp">&#8776; &#8369;<?= number_format($totalSpent, 2) ?> in payments made</p>
                    </div>
                </section>

                <!-- Full ledger -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>All Transactions</h3>
                            <p>Complete list of your wallet activity.</p>
                        </div>
                        <span class="sd-count"><?= count($transactions) ?> Records</span>
                    </div>

                    <?php if ($schoolYears): ?>
                    <form method="GET" style="margin-bottom:14px">
                        <select name="school_year" class="form-select form-select-sm" style="max-width:220px"
                                onchange="this.form.submit()">
                            <option value="0"<?= $selectedSchoolYearId === 0 ? ' selected' : '' ?>>All School Years</option>
                            <?php foreach ($schoolYears as $sy): ?>
                            <option value="<?= (int) $sy['id'] ?>"<?= $selectedSchoolYearId === (int) $sy['id'] ? ' selected' : '' ?>>
                                <?= $e($sy['school_year_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>

                    <?php if (empty($transactions)): ?>
                    <div class="sd-empty">
                        <i class="fa-regular fa-folder-open"></i>
                        No transactions yet. Start using your wallet to see activity here.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table sd-table align-middle js-datatable" id="studentHistoryTable" data-page-length="10">
                            <thead>
                                <tr>
                                    <th data-card="title">Reference</th>
                                    <th data-card="hide">Description</th>
                                    <th data-card="hide">Type</th>
                                    <th data-card="amount">Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td class="sd-cell-mono"><?= $e($t['ref']) ?></td>
                                    <td><?= $e($t['desc']) ?></td>
                                    <td><span class="sd-type-pill"><?= $e($t['type']) ?></span></td>
                                    <td><?= gjc_gc_price((float) $t['amount']) ?></td>
                                    <td><span class="sd-status-pill is-<?= $e(gjc_transaction_status_slug($t['status'])) ?>"><?= $e($t['status']) ?></span></td>
                                    <td><?= $e($t['date']) ?></td>
                                    <td class="text-end">
                                        <a class="student-view-btn"
                                            href="<?= STUDENT_URL ?>/view_transaction.php?source=<?= $e($t['source']) ?>&token=<?= urlencode(gjc_make_view_token((int) $t['id'], 'student_' . $t['source'])) ?>">
                                            <i class="fa-regular fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_student.php'; ?>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>
    <script>
    // ── Live wallet stats — balance/received/spent stay current without a
    // manual reload. The transaction table itself is left to a normal reload
    // since it's a DataTables instance; swapping its rows via raw innerHTML
    // would desync DataTables' internal state (pagination/search/sorting). ──
    const historyBalanceValue = document.getElementById("historyBalanceValue");
    const historyReceivedValue = document.getElementById("historyReceivedValue");
    const historySpentValue = document.getElementById("historySpentValue");

    const sdMoney = n => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const PESOS_PER_GC = <?= GJC_PESOS_PER_GC ?>;
    // Smart GC formatting: whole numbers stay whole ("2"), otherwise up to 2 decimals.
    const sdGc = pesos => (+((pesos / PESOS_PER_GC).toFixed(2))).toLocaleString(undefined, { maximumFractionDigits: 2 });

    async function refreshHistoryStats() {
        try {
            const fd = new FormData();
            fd.append("action", "get_wallet_stats");
            const res = await fetch("<?= STUDENT_URL ?>/api/wallet.php", { method: "POST", body: fd });
            const data = await res.json();
            if (!data.success) return;

            historyBalanceValue.textContent = sdGc(data.balance) + " GC";
            document.getElementById("historyBalancePhp").textContent = "≈ ₱" + sdMoney(data.balance) + " in your wallet";
            historyReceivedValue.textContent = sdGc(data.total_received) + " GC";
            document.getElementById("historyReceivedPhp").textContent = "≈ ₱" + sdMoney(data.total_received) + " in top-ups and refunds";
            historySpentValue.textContent = sdGc(data.total_spent) + " GC";
            document.getElementById("historySpentPhp").textContent = "≈ ₱" + sdMoney(data.total_spent) + " in payments made";
        } catch (error) {
            // Keep showing the last known values on a transient network error.
        }
    }

    setInterval(refreshHistoryStats, 5000);
    </script>

</body>

</html>
