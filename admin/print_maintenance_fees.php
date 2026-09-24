<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

// Printable maintenance-fee statement for one student, for the registrar to add
// to the tuition bill. Finance may print any student; a parent only their own
// linked student (same rule as print_fee_waiver.php).
gjc_require_role(['finance', 'parent']);
gjc_ensure_maintenance_fees_schema($db);

$role = gjc_current_role();
$studentUserId = (int) ($_GET['student_user_id'] ?? 0);
if ($studentUserId <= 0) {
    http_response_code(400);
    exit('Student not specified.');
}

if ($role === 'parent') {
    $linkStmt = $db->prepare(
        "SELECT 1 FROM parents p
           JOIN parent_student_links psl ON psl.parent_id = p.id
          WHERE p.user_id = ? AND psl.student_user_id = ?
          LIMIT 1"
    );
    $linkStmt->execute([gjc_user_id(), $studentUserId]);
    if (!$linkStmt->fetchColumn()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

$stmt = $db->prepare(
    "SELECT u.first_name, u.last_name, si.studentID
       FROM users u
       LEFT JOIN student_info si ON si.userID = u.userID
      WHERE u.userID = ? AND u.roleID = 1
      LIMIT 1"
);
$stmt->execute([$studentUserId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(404);
    exit('Student not found.');
}
$studentName = trim($student['first_name'] . ' ' . $student['last_name']);

$rowsStmt = $db->prepare(
    "SELECT reference_no, source_type, txn_amount, fee_amount, status, created_at
       FROM maintenance_fees
      WHERE student_user_id = ? AND status <> 'voided'
      ORDER BY created_at ASC, id ASC"
);
$rowsStmt->execute([$studentUserId]);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$owed = 0.0;
$paid = 0.0;
foreach ($rows as $r) {
    if ($r['status'] === 'accrued') {
        $owed += (float) $r['fee_amount'];
    } else {
        $paid += (float) $r['fee_amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Fee Statement | GenPay</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #1a1a1a; margin: 0; padding: 24px; }
        .sheet { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .sub { color: #555; font-size: 13px; margin: 0 0 20px; }
        .meta { display: flex; gap: 32px; margin-bottom: 18px; font-size: 13px; }
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

        <h1>Maintenance Fee Statement</h1>
        <p class="sub">General de Jesus College &mdash; Office of Finance &amp; Accounting &middot; GenPay</p>

        <div class="meta">
            <div><span>Student</span><strong><?= gjc_e($studentName) ?></strong></div>
            <div><span>Student ID</span><strong><?= gjc_e($student['studentID'] ?: 'Not on file') ?></strong></div>
            <div><span>Generated</span><strong><?= gjc_e(date('M j, Y g:i A')) ?></strong></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Type</th>
                    <th class="num">Transaction</th>
                    <th class="num">Fee</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                <tr><td colspan="6" style="text-align:center;color:#777;">No maintenance fees recorded.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= gjc_e(gjc_date($r['created_at'])) ?></td>
                    <td><?= gjc_e($r['reference_no']) ?></td>
                    <td><?= $r['source_type'] === 'p2p_transfer' ? 'Transfer' : 'POS payment' ?></td>
                    <td class="num"><?= gjc_money((float) $r['txn_amount']) ?></td>
                    <td class="num"><?= gjc_money((float) $r['fee_amount']) ?></td>
                    <td><?= $r['status'] === 'accrued' ? 'Due' : 'Collected' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <div><span>Already collected</span><span><?= gjc_money($paid) ?></span></div>
            <div class="due"><span>Add to tuition</span><span><?= gjc_money($owed) ?></span></div>
        </div>

        <p class="note">
            Maintenance fees are a school-managed charge on GenPay transactions and are billed with tuition.
            They are not deducted from the student&rsquo;s GenCoin wallet.
        </p>
    </div>
</body>
</html>
