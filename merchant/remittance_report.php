<?php
declare(strict_types=1);

require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';

// Printable sales remittance report — a self-declared record that stall staff
// handed over a counted cash total. Any merchant account on the same stall
// (owner or staff) can view/reprint it; it is not an admin-approved document.
gjc_require_role(['merchant']);
gjc_ensure_sales_remittances_schema($db);

$currentUser = gjc_current_user($db);
$ownerMerchId = gjc_merchant_owner_id($db, (int) $currentUser['id']);

$reference = trim((string) ($_GET['ref'] ?? ''));
if ($reference === '') {
    http_response_code(400);
    exit('Remittance not specified.');
}

$stmt = $db->prepare(
    "SELECT sr.*, u.first_name, u.last_name
       FROM sales_remittances sr
       LEFT JOIN users u ON u.userID = sr.staff_user_id
      WHERE sr.reference_no = ? AND sr.merchant_wallet_id = (SELECT id FROM merchant_wallets WHERE user_id = ?)
      LIMIT 1"
);
$stmt->execute([$reference, $ownerMerchId]);
$remit = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$remit) {
    http_response_code(404);
    exit('Remittance report not found.');
}

$stallStmt = $db->prepare("SELECT stall_name, stall_id FROM merchant WHERE userID = ? LIMIT 1");
$stallStmt->execute([$ownerMerchId]);
$stall = $stallStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stallName = $stall['stall_name'] ?? $currentUser['name'];

$staffName = trim(($remit['first_name'] ?? '') . ' ' . ($remit['last_name'] ?? ''));
if ($staffName === '') {
    $staffName = 'Merchant staff';
}

$earningTypes = [CirculationEngine::TXN_PAYMENT, CirculationEngine::TXN_VOUCHER_PAYMENT];
$placeholders = implode(', ', array_fill(0, count($earningTypes), '?'));
$staffSale = gjc_merchant_staff_sale_sql($db);
$rowsStmt = $db->prepare(
    "SELECT reference_no, transaction_type, amount, notes, created_at, initiated_by
       FROM transactions t
      WHERE merchant_wallet_id = ?
        AND transaction_type IN ({$placeholders})
        AND created_at > ? AND created_at <= ?
        AND t.status = 'completed' AND {$staffSale}
      ORDER BY created_at ASC"
);
$rowsStmt->execute(array_merge([$remit['merchant_wallet_id']], $earningTypes, [$remit['period_start'], $remit['period_end']]));
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

// Rung up by — a wallet can be shared by several staff logins across a shift,
// but one remittance bundles all of their pending sales together. Break the
// total back down per staff so the report shows who actually made each sale.
$byStaff = [];
foreach ($rows as $r) {
    $staffId = (int) $r['initiated_by'];
    if (!isset($byStaff[$staffId])) {
        $byStaff[$staffId] = ['name' => gjc_user_label_cached($db, $staffId), 'amount' => 0.0, 'count' => 0];
    }
    $byStaff[$staffId]['amount'] += (float) $r['amount'];
    $byStaff[$staffId]['count']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Remittance Report | GenPay</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #1a1a1a; margin: 0; padding: 24px; }
        .sheet { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .sub { color: #555; font-size: 13px; margin: 0 0 20px; }
        .meta { display: flex; flex-wrap: wrap; gap: 24px 32px; margin-bottom: 18px; font-size: 13px; }
        .meta span { display: block; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 7px 8px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #f3f3f3; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        td.num, th.num { text-align: right; }
        .totals { margin-top: 16px; width: 280px; margin-left: auto; font-size: 14px; }
        .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
        .totals .due { border-top: 2px solid #1a1a1a; font-weight: 700; margin-top: 4px; padding-top: 8px; }
        .note { margin-top: 24px; font-size: 12px; color: #555; }
        .print-actions { text-align: right; margin-bottom: 12px; }
        .print-actions button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
        @media print { .print-actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="print-actions"><button type="button" onclick="window.print()">Print</button></div>

        <h1>Sales Remittance Report</h1>
        <p class="sub">General de Jesus College &mdash; GenPay Merchant Services</p>

        <div class="meta">
            <div><span>Stall</span><strong><?= htmlspecialchars($stallName, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div><span>Reference</span><strong><?= gjc_e($remit['reference_no']) ?></strong></div>
            <div><span>Remitted By</span><strong><?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div><span>Period</span><strong><?= gjc_e(gjc_datetime($remit['period_start'])) ?> &ndash; <?= gjc_e(gjc_datetime($remit['period_end'])) ?></strong></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Items</th>
                    <th>Rung Up By</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                <tr><td colspan="5" style="text-align:center;color:#777;">No sales recorded in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <?php $itemsBought = preg_replace('/^POS Sale:\s*/i', '', trim((string) $r['notes'])); ?>
                <tr>
                    <td><?= gjc_e(gjc_datetime($r['created_at'])) ?></td>
                    <td><?= gjc_e($r['reference_no']) ?></td>
                    <td><?= $itemsBought !== '' ? htmlspecialchars($itemsBought, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></td>
                    <td><?= htmlspecialchars($byStaff[(int) $r['initiated_by']]['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="num"><?= gjc_money((float) $r['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($byStaff) > 1): ?>
        <table style="margin-top:18px">
            <thead>
                <tr>
                    <th>By Staff</th>
                    <th class="num">Transactions</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byStaff as $staff): ?>
                <tr>
                    <td><?= htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="num"><?= (int) $staff['count'] ?></td>
                    <td class="num"><?= gjc_money($staff['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="totals">
            <div><span>Transactions</span><span><?= (int) $remit['txn_count'] ?></span></div>
            <div class="due"><span>Total Remitted</span><span><?= gjc_money((float) $remit['amount']) ?></span></div>
        </div>

        <p class="note">
            This report confirms the sales total counted and handed over by stall staff for the period above.
            It is a self-declared record, not an admin-approved encashment.
        </p>
    </div>
</body>
</html>
