<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';

gjc_require_role(['finance']);
gjc_ensure_maintenance_fees_schema($db);
gjc_backfill_student_ids($db);

// Fees settle themselves per student (one receipt each) — no manual step needed.
foreach (gjc_auto_collect_maintenance_fees($db) as $sid => $receipt) {
    logAudit(
        $db, gjc_user_id(), gjc_current_role(), 'MAINTENANCE_FEE_COLLECTED', 'maintenance_fees',
        ['student_user_id' => $sid, 'status' => 'accrued'],
        ['student_user_id' => $sid, 'status' => 'collected', 'receipt_no' => $receipt['receipt_no'],
         'entries' => $receipt['entries'], 'total' => $receipt['total']],
        null,
        ['target_id' => $receipt['receipt_no'], 'reason' => 'Auto-collected.']
    );
}

$currentUser = gjc_current_user($db);
$currentPage = 'wallet';

$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'accrued', 'collected', 'voided'], true)) {
    $statusFilter = 'all';
}

$entrySql =
    "SELECT mf.id, mf.student_user_id, mf.reference_no, mf.source_type, mf.txn_amount,
            mf.fee_amount, mf.status, mf.created_at, mf.collected_at, si.studentID
       FROM maintenance_fees mf
       LEFT JOIN student_info si ON si.userID = mf.student_user_id"
    . ($statusFilter !== 'all' ? " WHERE mf.status = " . $db->quote($statusFilter) : '')
    . " ORDER BY mf.created_at DESC, mf.id DESC";

$sourceLabel = static fn(string $t): string => $t === 'p2p_transfer' ? 'Transfer' : 'POS payment';

// ── CSV export of the (filtered) entries ─────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $rows = $db->query($entrySql)->fetchAll(PDO::FETCH_ASSOC);

    logAudit(
        $db, gjc_user_id(), gjc_current_role(), 'DATA_EXPORT', 'maintenance_fees', null,
        ['format' => 'csv', 'status_filter' => $statusFilter, 'row_count' => count($rows)],
        null,
        ['target_id' => 'maintenance-fees-' . date('Ymd-His') . '.csv', 'reason' => count($rows) . ' maintenance fee row(s) exported to CSV.']
    );

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="maintenance-fees-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reference', 'Student', 'School ID', 'Source', 'Transaction Amount', 'Fee', 'Status', 'Created', 'Collected']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['reference_no'],
            gjc_user_label($db, (int) $r['student_user_id']),
            $r['studentID'] ?? '',
            $sourceLabel((string) $r['source_type']),
            number_format((float) $r['txn_amount'], 2, '.', ''),
            number_format((float) $r['fee_amount'], 2, '.', ''),
            ucfirst((string) $r['status']),
            $r['created_at'],
            $r['collected_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ── Headline figures ─────────────────────────────────────────────────────
$totals = $db->query(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'accrued'   THEN fee_amount END), 0) AS accrued,
        COALESCE(SUM(CASE WHEN status = 'collected' THEN fee_amount END), 0) AS collected,
        COALESCE(SUM(CASE WHEN status = 'accrued'   THEN 1 END), 0)          AS accrued_count,
        COALESCE(SUM(CASE WHEN status <> 'voided'   THEN 1 END), 0)          AS billable_count
       FROM maintenance_fees"
)->fetch(PDO::FETCH_ASSOC);
$accrued       = (float) $totals['accrued'];
$collected     = (float) $totals['collected'];
$accruedCount  = (int) $totals['accrued_count'];
$billableCount = (int) $totals['billable_count'];
$ratePct       = gjc_setting($db, 'maintenance_fee_rate_pct');

// ── Per-student balances (students who still owe something first) ────────
$byStudent = $db->query(
    "SELECT mf.student_user_id, si.studentID,
            SUM(CASE WHEN mf.status = 'accrued'   THEN mf.fee_amount ELSE 0 END) AS accrued,
            SUM(CASE WHEN mf.status = 'collected' THEN mf.fee_amount ELSE 0 END) AS collected,
            SUM(CASE WHEN mf.status <> 'voided'   THEN 1 ELSE 0 END)             AS txn_count
       FROM maintenance_fees mf
       LEFT JOIN student_info si ON si.userID = mf.student_user_id
      GROUP BY mf.student_user_id, si.studentID
     HAVING txn_count > 0
      ORDER BY accrued DESC, collected DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$entries = $db->query($entrySql . " LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>Admin Wallet | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/encashments.css?v=8">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
</head>

<body class="gp-theme">

    <div class="admin-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

        <main class="admin-main">

            <header class="topbar">
                <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>

                <div>
                    <h1>Admin Wallet</h1>
                    <p>Maintenance fees earned from student transactions, billed to tuition.</p>
                </div>

                <div class="admin-user">
                    <span><?= gjc_e($currentUser['name'] ?? 'Finance') ?></span>
                    <?php require __DIR__ . '/../includes/partials/avatar_admin.php'; ?>
                </div>
            </header>

            <section class="encash-stats-grid mb-4">

                <div class="encash-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                    <span>Accrued</span>
                    <h2><?= gjc_money($accrued) ?></h2>
                    <p><?= $accruedCount ?> fee<?= $accruedCount === 1 ? '' : 's' ?> not yet collected</p>
                </div>

                <div class="encash-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <span>Collected</span>
                    <h2><?= gjc_money($collected) ?></h2>
                    <p>Auto-collected per student</p>
                </div>

                <div class="encash-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <span>Fee Rate</span>
                    <h2><?= $ratePct > 0 ? gjc_fee_rate_label($ratePct) : 'Off' ?></h2>
                    <p><?= $billableCount ?> billable transaction<?= $billableCount === 1 ? '' : 's' ?> &middot;
                        <a href="<?= ADMIN_URL ?>/settings.php#maintenance">change</a></p>
                </div>

            </section>

            <section class="encash-panel mb-4" id="by-student">

                <div class="encash-panel-header">
                    <div>
                        <h3>Fees by Student</h3>
                        <p>What each student owes in maintenance fees. Fees from previous days are collected automatically, one receipt per student; today&rsquo;s stay open until tomorrow in case of a return.</p>
                    </div>
                    <?php if ($accruedCount > 0): ?>
                    <button type="button" class="release-btn" onclick="collectAll(this, <?= $accrued ?>, <?= $accruedCount ?>)">Mark all collected</button>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="table encash-table align-middle js-datatable" id="feesByStudentTable" data-page-length="10"
                           data-empty-message="No maintenance fees yet. They accrue whenever a student pays at a stall or sends a transfer.">
                        <thead>
                            <tr>
                                <th data-card="title">Student</th>
                                <th data-card="hide">School ID</th>
                                <th data-card="hide">Transactions</th>
                                <th data-card="amount">Owed</th>
                                <th>Collected</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byStudent as $s): ?>
                            <?php $name = gjc_user_label($db, (int) $s['student_user_id']); ?>
                            <tr>
                                <td>
                                    <div class="encash-user-cell">
                                        <div class="encash-avatar"><?= gjc_e(strtoupper(substr($name, 0, 1))) ?></div>
                                        <strong><?= gjc_e($name) ?></strong>
                                    </div>
                                </td>
                                <td><?= gjc_e($s['studentID'] ?: 'Not on file') ?></td>
                                <td><?= (int) $s['txn_count'] ?></td>
                                <td class="amount-text"><?= gjc_money((float) $s['accrued']) ?></td>
                                <td><?= gjc_money((float) $s['collected']) ?></td>
                                <td>
                                    <div class="encash-actions">
                                        <a class="details-btn" target="_blank"
                                           href="<?= ADMIN_URL ?>/print_maintenance_fees.php?student_user_id=<?= (int) $s['student_user_id'] ?>">Statement</a>
                                        <?php if ((float) $s['accrued'] > 0): ?>
                                        <button type="button" class="release-btn"
                                            onclick="collectStudent(this, <?= (int) $s['student_user_id'] ?>, <?= (float) $s['accrued'] ?>, <?= gjc_e(json_encode($name)) ?>)">Mark collected</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </section>

            <section class="encash-panel" id="entries">

                <div class="encash-panel-header">
                    <div>
                        <h3>Fee Entries</h3>
                        <p>Every fee, newest first<?= count($entries) >= 1000 ? ' (latest 1,000 shown &mdash; export for all)' : '' ?>. A fee is voided automatically when its sale is returned.</p>
                    </div>
                    <div class="encash-actions">
                        <form method="get" class="d-flex gap-2 align-items-center">
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by status">
                                <?php foreach (['all' => 'All', 'accrued' => 'Accrued', 'collected' => 'Collected', 'voided' => 'Voided'] as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <a class="history-link" href="?export=csv&amp;status=<?= gjc_e($statusFilter) ?>">Export CSV</a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table encash-table align-middle js-datatable" id="feeEntriesTable" data-page-length="10"
                           data-empty-message="No fee entries match this filter.">
                        <thead>
                            <tr>
                                <th data-card="title">Reference</th>
                                <th>Student</th>
                                <th data-card="hide">Source</th>
                                <th data-card="hide">Transaction</th>
                                <th data-card="amount">Fee</th>
                                <th>Status</th>
                                <th data-card="hide">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $r): ?>
                            <?php $pill = ['accrued' => 'processing', 'collected' => 'released', 'voided' => 'rejected'][$r['status']] ?? 'processing'; ?>
                            <tr>
                                <td><?= gjc_e($r['reference_no']) ?></td>
                                <td><?= gjc_e(gjc_user_label($db, (int) $r['student_user_id'])) ?></td>
                                <td><span class="method-pill"><?= gjc_e($sourceLabel((string) $r['source_type'])) ?></span></td>
                                <td><?= gjc_money((float) $r['txn_amount']) ?></td>
                                <td class="amount-text"><?= gjc_money((float) $r['fee_amount']) ?></td>
                                <td><span class="encash-status <?= $pill ?>"><?= gjc_e(ucfirst((string) $r['status'])) ?></span></td>
                                <td><?= gjc_e(gjc_datetime($r['created_at'])) ?></td>
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

    async function collectStudent(btn, studentUserId, amount, studentName) {
        const ok = await gpConfirm({
            title: 'Mark fees as collected?',
            body: 'Confirm the registrar has received these maintenance fees with the student’s tuition.',
            detail: [
                ['Student', studentName],
                ['Amount', gpMoney(amount)],
            ],
            note: 'This cannot be undone.',
            confirmLabel: 'Mark collected',
        });
        if (!ok) return;
        if (!gpBusy(btn, 'Saving…')) return;

        const result = await gpFetchJson('api/maintenance_fees.php', {
            action: 'collect_student',
            student_user_id: studentUserId,
        });

        if (result.success) {
            gpToast(result.message || 'Marked as collected.');
            setTimeout(() => window.location.reload(), 900);
            return;
        }
        gpToast(result.message || 'Could not mark these fees as collected.', false);
        gpBusyDone(btn);
    }

    async function collectAll(btn, amount, count) {
        const ok = await gpConfirm({
            title: 'Mark ALL fees as collected?',
            body: 'This flips every accrued fee for every student.',
            detail: [
                ['Fees', String(count)],
                ['Amount', gpMoney(amount)],
            ],
            note: 'This cannot be undone.',
            confirmLabel: 'Mark all collected',
        });
        if (!ok) return;
        if (!gpBusy(btn, 'Saving…')) return;

        const result = await gpFetchJson('api/maintenance_fees.php', { action: 'collect_all' });

        if (result.success) {
            gpToast(result.message || 'Marked as collected.');
            setTimeout(() => window.location.reload(), 900);
            return;
        }
        gpToast(result.message || 'Could not mark the fees as collected.', false);
        gpBusyDone(btn);
    }
    </script>

</body>

</html>
