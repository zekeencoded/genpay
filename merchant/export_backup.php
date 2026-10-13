<?php
// Full-stall backup for the merchant admin: one ZIP of CSVs holding the stall's
// transactions, inventory, staff, encashments, lease/rent and audit trail — a
// copy that survives the system going down. Owner only; staff never see it.
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/MerchantTenantDirectory.php';
require_once __DIR__ . '/../connection/audit_logger.php';

gjc_require_role(['merchant']);
if (gjc_is_merchant_staff()) {
    http_response_code(403);
    exit('Only the merchant admin can download the stall backup.');
}
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZIP support is not enabled on this server.');
}

gjc_ensure_audit_table($db);

$currentUser  = gjc_current_user($db);
$ownerMerchId = gjc_merchant_owner_id($db, (int) $currentUser['id']);
$wallet       = gjc_merchant_wallet($db, $ownerMerchId);

$stallStmt = $db->prepare("SELECT stall_id, stall_name FROM merchant WHERE userID = ? LIMIT 1");
$stallStmt->execute([$ownerMerchId]);
$stall = $stallStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stallId = (string) ($stall['stall_id'] ?? '');

/** Text cells that start with = + - @ would run as formulas when the CSV opens in Excel. */
function backup_cell($value)
{
    if (!is_string($value) || $value === '') {
        return $value;
    }
    return in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
}

function backup_fetch(PDO $db, string $table, string $sql, array $params): array
{
    if (!gjc_table_exists($db, $table)) {
        return [];
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_NUM);
}

/** Same CSV columns and ordering, with a calculated Next Due cell. */
function backup_lease_rows(PDO $db, int $merchantUserId): array
{
    if (!gjc_table_exists($db, 'merchant_leases')) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT id, stall_name, monthly_rent, deposit_amount, lease_start, lease_end, status, contract_notes
           FROM merchant_leases WHERE merchant_user_id = ? ORDER BY lease_start ASC"
    );
    $stmt->execute([$merchantUserId]);
    $leases = (new MerchantTenantDirectory($db))->withDerivedDueDates($stmt->fetchAll(PDO::FETCH_ASSOC));
    return array_map(static fn(array $lease): array => [
        $lease['stall_name'], $lease['monthly_rent'], $lease['deposit_amount'],
        $lease['lease_start'], $lease['lease_end'], $lease['next_due_date'],
        $lease['status'], $lease['contract_notes'],
    ], $leases);
}
$staffIdsStmt = $db->prepare("SELECT userID FROM users WHERE merchant_owner_id = ?");
$staffIdsStmt->execute([$ownerMerchId]);
$actorIds = array_map('intval', array_merge([$ownerMerchId], $staffIdsStmt->fetchAll(PDO::FETCH_COLUMN)));
$actorPlaceholders = implode(',', array_fill(0, count($actorIds), '?'));

$auditSql = "SELECT timestamp, user_id, user_role, sub_role, action_type, stall_id, affected_table, target_id,
                    outcome, reason, ip_address
               FROM systemic_audit_trail
              WHERE user_id IN ({$actorPlaceholders})";
$auditParams = $actorIds;
if ($stallId !== '') {
    $auditSql .= " OR stall_id = ?";
    $auditParams[] = $stallId;
}
$auditSql .= " ORDER BY log_id ASC";

$files = [
    'transactions.csv' => [
        ['Reference', 'Type', 'Amount', 'Status', 'Notes', 'Date & Time'],
        backup_fetch($db, 'transactions',
            "SELECT reference_no, transaction_type, amount, status, notes, created_at
               FROM transactions WHERE merchant_wallet_id = ? ORDER BY created_at ASC, id ASC",
            [(int) $wallet['id']]),
    ],
    'inventory.csv' => [
        ['SKU', 'Product', 'Description', 'Category', 'Unit', 'Price', 'Stock', 'Min Stock Alert', 'Available', 'Restricted', 'Restriction Note', 'Created', 'Updated'],
        backup_fetch($db, 'merchant_inventory',
            "SELECT sku, product_name, description, category, unit, price, stock_qty, min_stock_alert,
                    is_available, is_restricted, restriction_note, created_at, updated_at
               FROM merchant_inventory WHERE merchant_user_id = ? ORDER BY product_name ASC",
            [$ownerMerchId]),
    ],
    'staff.csv' => [
        ['First Name', 'Last Name', 'Email', 'Contact Number', 'Position', 'Status', 'Created'],
        backup_fetch($db, 'users',
            "SELECT first_name, last_name, email, contact_number, position, status, created_at
               FROM users WHERE merchant_owner_id = ? AND roleID = 6 ORDER BY last_name ASC",
            [$ownerMerchId]),
    ],
    'encashments.csv' => [
        ['Reference', 'Amount', 'Method', 'Status', 'Requested', 'Released', 'Rejected'],
        backup_fetch($db, 'encashment_requests',
            "SELECT reference_no, amount, method, status, created_at, released_at, rejected_at
               FROM encashment_requests WHERE merchant_wallet_id = (SELECT id FROM merchant_wallets WHERE user_id = ?) ORDER BY created_at ASC",
            [$ownerMerchId]),
    ],
    'leases.csv' => [
        ['Stall', 'Monthly Rent', 'Deposit', 'Lease Start', 'Lease End', 'Next Due', 'Status', 'Notes'],
        backup_lease_rows($db, $ownerMerchId),
    ],
    'rent_payments.csv' => [
        ['Reference', 'Amount Paid', 'Period Covered', 'Payment Date', 'Method', 'Notes'],
        backup_fetch($db, 'merchant_rent_payments',
            "SELECT p.reference_no, p.amount_paid, p.period_covered, p.payment_date, p.payment_method, p.notes
               FROM merchant_rent_payments p
               JOIN merchant_leases l ON l.id = p.lease_id
              WHERE l.merchant_user_id = ? ORDER BY p.payment_date ASC",
            [$ownerMerchId]),
    ],
    'audit_trail.csv' => [
        ['Timestamp', 'User ID', 'Role', 'Sub Role', 'Action', 'Stall', 'Table', 'Target', 'Outcome', 'Reason', 'IP Address'],
        backup_fetch($db, 'systemic_audit_trail', $auditSql, $auditParams),
    ],
];

$stamp = date('Ymd-His');
$zipName = 'stall-backup-' . preg_replace('/[^A-Za-z0-9_-]/', '', $stallId ?: (string) $ownerMerchId) . '-' . $stamp . '.zip';
$tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gjcbak_' . bin2hex(random_bytes(8)) . '.zip';

$counts = [];
try {
    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to build the backup archive.');
    }

    $manifest = fopen('php://temp', 'w+');
    fputcsv($manifest, ['File', 'Rows']);
    foreach ($files as $name => [$header, $rows]) {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, array_map('backup_cell', $row));
        }
        rewind($handle);
        $zip->addFromString($name, stream_get_contents($handle));
        fclose($handle);
        $counts[$name] = count($rows);
        fputcsv($manifest, [$name, count($rows)]);
    }
    fputcsv($manifest, []);
    fputcsv($manifest, ['Stall', ($stall['stall_name'] ?? '') . ' (' . $stallId . ')']);
    fputcsv($manifest, ['Generated', date('Y-m-d H:i:s')]);
    fputcsv($manifest, ['Generated by', $currentUser['name']]);
    rewind($manifest);
    $zip->addFromString('manifest.csv', stream_get_contents($manifest));
    fclose($manifest);
    $zip->close();

    logAudit(
        $db, gjc_user_id(), gjc_current_role(), 'DATA_EXPORT', 'merchant_backup', null,
        ['format' => 'zip', 'encrypted' => false, 'files' => $counts],
        $stallId !== '' ? $stallId : null,
        ['target_id' => $zipName, 'reason' => 'Full stall backup downloaded by the merchant admin.']
    );

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpPath));
    readfile($tmpPath);
} finally {
    if (is_file($tmpPath)) {
        unlink($tmpPath);
    }
}
