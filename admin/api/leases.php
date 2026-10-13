<?php
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';
require_once __DIR__ . '/../../connection/MerchantTenantDirectory.php';

header('Content-Type: application/json');
gjc_require_role(['finance']);

$action  = trim((string) ($_REQUEST['action'] ?? ''));
$adminId = gjc_user_id();
$adminRole = gjc_current_role();
$directory = new MerchantTenantDirectory($db);

// ── Ensure tables exist before any operation ──────────────────────────────
if (!gjc_table_exists($db, 'merchant_leases')) {
    gjc_lease_json_fail('Lease schema is missing. Restore the approved database schema before continuing.');
}

if (!gjc_table_exists($db, 'merchant_rent_payments')) {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS merchant_rent_payments (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lease_id        INT UNSIGNED NOT NULL,
            amount_paid     DECIMAL(12,2) NOT NULL,
            period_covered  VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM',
            payment_date    DATE         NOT NULL,
            payment_method  VARCHAR(20)  NOT NULL DEFAULT 'cash',
            received_by     INT UNSIGNED NULL,
            reference_no    VARCHAR(60)  NOT NULL,
            notes           TEXT         NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mrp_lease (lease_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function gjc_lease_json_fail(string $message): void
{
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/** The payload every write action returns, so the screen can re-render without a reload. */
function gjc_lease_state(MerchantTenantDirectory $directory, int $leaseId): array
{
    $lease = $directory->leaseById($leaseId);

    return $lease ? ['lease' => $lease, 'account' => $lease['account']] : [];
}

try {
    switch ($action) {

        /* ── LIST MERCHANTS (picker for new lease) ───────────────────────── */
        case 'list_merchants': {
            echo json_encode(['success' => true, 'merchants' => $directory->merchantsForPicker()]);
            break;
        }

        /* ── LEASE LEDGER (detail + paginated payment history) ──────────── */
        case 'get_ledger': {
            $leaseId = (int) ($_GET['lease_id'] ?? 0);
            if (!$leaseId) {
                gjc_lease_json_fail('Invalid lease ID.');
            }

            $lease = $directory->leaseById($leaseId);
            if (!$lease) {
                gjc_lease_json_fail('Lease record not found.');
            }

            $merchant = $db->prepare("SELECT u.first_name, u.last_name, u.email FROM users u WHERE u.userID = ? LIMIT 1");
            $merchant->execute([$lease['merchant_user_id']]);
            $merchantRow = $merchant->fetch(PDO::FETCH_ASSOC) ?: [];

            echo json_encode([
                'success' => true,
                'lease' => $lease,
                'merchant' => [
                    'name' => trim((string) (($merchantRow['first_name'] ?? '') . ' ' . ($merchantRow['last_name'] ?? ''))),
                    'email' => (string) ($merchantRow['email'] ?? ''),
                ],
                'payments' => $directory->pagedRentPayments(
                    $leaseId,
                    trim((string) ($_GET['from'] ?? '')),
                    trim((string) ($_GET['to'] ?? '')),
                    (int) ($_GET['page'] ?? 1),
                    (int) ($_GET['per_page'] ?? 10)
                ),
            ]);
            break;
        }

        /* ── CREATE LEASE ─────────────────────────────────────────────────── */
        case 'create_lease': {
            $merchantUserId = (int)    ($_POST['merchant_user_id'] ?? 0);
            // The stall comes from the stall registry (3NF: merchant_leases.stall_id).
            $stallId        = trim((string) ($_POST['stall_id']        ?? ''));
            $stallName      = trim((string) ($_POST['stall_name']      ?? ''));
            $monthlyRent    = (float)  ($_POST['monthly_rent']    ?? 0);
            $depositAmount  = (float)  ($_POST['deposit_amount']  ?? 0);
            $leaseStart     = trim((string) ($_POST['lease_start']     ?? ''));
            $leaseEnd       = trim((string) ($_POST['lease_end']       ?? ''));
            $status         = trim((string) ($_POST['status']          ?? 'active'));
            $notes          = trim((string) ($_POST['contract_notes']  ?? ''));

            // A brand-new contract can only start out running or waiting to run;
            // expired/terminated are end-states reached later, from the ledger.
            if (!in_array($status, ['pending', 'active'], true)) {
                $status = 'active';
            }

            if (!$merchantUserId || $stallId === '' || !$stallName || $monthlyRent <= 0 || !$leaseStart || !$leaseEnd) {
                gjc_lease_json_fail('All required fields must be filled correctly.');
            }
            $stallId = gjc_registry_stall_id($db, $stallId);
            if ($stallId === null) {
                gjc_lease_json_fail('Pick a stall from the stall registry.');
            }
            $legacyStallNumber = gjc_lease_has_stall_number($db);

            if (!strtotime($leaseStart) || !strtotime($leaseEnd)) {
                gjc_lease_json_fail('Invalid lease start or end date.');
            }

            if ($leaseEnd <= $leaseStart) {
                gjc_lease_json_fail('Lease end date must be after lease start date.');
            }

            $check = $db->prepare("SELECT userID FROM users WHERE userID = ? LIMIT 1");
            $check->execute([$merchantUserId]);
            if (!$check->fetch()) {
                gjc_lease_json_fail('Selected merchant was not found in the system.');
            }

            if (in_array('next_due_date', gjc_table_columns($db, 'merchant_leases'), true)) {
            $stmt = $db->prepare(
                "INSERT INTO merchant_leases
                    (merchant_user_id, stall_id" . ($legacyStallNumber ? ", stall_number" : "") . ", stall_name, monthly_rent, deposit_amount,
                     lease_start, lease_end, next_due_date, status, contract_notes, created_by)
                 VALUES (?, ?, " . ($legacyStallNumber ? "?, " : "") . "?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $merchantUserId,
                $stallId,
                ...($legacyStallNumber ? [$stallId] : []),
                $stallName,
                $monthlyRent,
                $depositAmount,
                $leaseStart,
                $leaseEnd,
                $leaseStart,
                $status,
                $notes ?: null,
                $adminId,
            ]);

            } else {
            $stmt = $db->prepare(
                "INSERT INTO merchant_leases
                    (merchant_user_id, stall_id" . ($legacyStallNumber ? ", stall_number" : "") . ", stall_name, monthly_rent, deposit_amount,
                     lease_start, lease_end, status, contract_notes, created_by)
                 VALUES (?, ?, " . ($legacyStallNumber ? "?, " : "") . "?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $merchantUserId,
                $stallId,
                ...($legacyStallNumber ? [$stallId] : []),
                $stallName,
                $monthlyRent,
                $depositAmount,
                $leaseStart,
                $leaseEnd,
                $status,
                $notes ?: null,
                $adminId,
            ]);

            }

            $newId = (int) $db->lastInsertId();
            $directory->syncNextDueDate($newId);

            logAudit($db, $adminId, $adminRole, 'STALL_UPDATE', 'merchant_leases', null, [
                'lease_id' => $newId, 'merchant_user_id' => $merchantUserId, 'stall_name' => $stallName,
                'monthly_rent' => $monthlyRent, 'status' => $status,
            ], $stallId, ['target_id' => $newId]);

            echo json_encode([
                'success' => true,
                'message' => 'Lease contract created. First rent charge falls on ' . date('M j, Y', strtotime($leaseStart)) . '.',
                'id'      => $newId,
            ]);
            break;
        }

        /* ── UPDATE LEASE ─────────────────────────────────────────────────── */
        case 'update_lease': {
            $leaseId        = (int)    ($_POST['lease_id']        ?? 0);
            $monthlyRent    = (float)  ($_POST['monthly_rent']    ?? 0);
            $depositAmount  = (float)  ($_POST['deposit_amount']  ?? 0);
            $leaseStart     = trim((string) ($_POST['lease_start']     ?? ''));
            $leaseEnd       = trim((string) ($_POST['lease_end']       ?? ''));
            $status         = trim((string) ($_POST['status']          ?? 'active'));
            $notes          = trim((string) ($_POST['contract_notes']  ?? ''));
            $stallId        = trim((string) ($_POST['stall_id']        ?? ''));
            $stallName      = trim((string) ($_POST['stall_name']      ?? ''));

            $allowedStatuses = ['pending', 'active', 'expired', 'terminated'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'active';
            }

            if (!$leaseId) {
                gjc_lease_json_fail('Invalid lease ID.');
            }

            if ($monthlyRent <= 0 || !$leaseStart || !$leaseEnd) {
                gjc_lease_json_fail('All required fields must be filled.');
            }

            if ($leaseEnd <= $leaseStart) {
                gjc_lease_json_fail('Lease end date must be after lease start date.');
            }

            // Named columns rather than SELECT *: this row goes into the audit
            // log as old_value, and a whole-row dump quietly logs whatever gets
            // added to the table later.
            $chk = $db->prepare(
                "SELECT id, stall_id" . (gjc_lease_has_stall_number($db) ? ", stall_number" : "") . ", stall_name, monthly_rent, deposit_amount,
                        lease_start, lease_end, status, contract_notes
                   FROM merchant_leases WHERE id = ? LIMIT 1"
            );
            $chk->execute([$leaseId]);
            $old = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                gjc_lease_json_fail('Lease record not found.');
            }

            if ($stallId === '') {
                $stallId = gjc_lease_stall_code($old);
            }
            $stallId = gjc_registry_stall_id($db, $stallId);
            if ($stallId === null) {
                gjc_lease_json_fail('Pick a stall from the stall registry.');
            }
            $legacyStallNumber = gjc_lease_has_stall_number($db);
            if (!$stallName) {
                $stallName = $old['stall_name'];
            }

            // next_due_date is not an input any more — it is recomputed from the
            // rent schedule below, so it can never drift out of step with the
            // payments that have actually been recorded.
            $stmt = $db->prepare(
                "UPDATE merchant_leases
                    SET stall_id         = ?," . ($legacyStallNumber ? "
                        stall_number     = ?," : "") . "
                        stall_name       = ?,
                        monthly_rent     = ?,
                        deposit_amount   = ?,
                        lease_start      = ?,
                        lease_end        = ?,
                        status           = ?,
                        contract_notes   = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $stallId,
                ...($legacyStallNumber ? [$stallId] : []),
                $stallName,
                $monthlyRent,
                $depositAmount,
                $leaseStart,
                $leaseEnd,
                $status,
                $notes ?: null,
                $leaseId,
            ]);

            $directory->syncNextDueDate($leaseId);

            logAudit($db, $adminId, $adminRole, 'STALL_UPDATE', 'merchant_leases', $old, [
                'lease_id' => $leaseId, 'monthly_rent' => $monthlyRent, 'status' => $status,
                'lease_start' => $leaseStart, 'lease_end' => $leaseEnd,
            ], $stallId, ['target_id' => $leaseId]);

            echo json_encode(['success' => true, 'message' => 'Contract updated.'] + gjc_lease_state($directory, $leaseId));
            break;
        }

        /* ── RECORD PAYMENT ───────────────────────────────────────────────── */
        case 'record_payment': {
            $leaseId    = (int)    ($_POST['lease_id']      ?? 0);
            $amountPaid = (float)  ($_POST['amount_paid']   ?? 0);
            $period     = trim((string) ($_POST['period_covered'] ?? ''));
            $payDate    = trim((string) ($_POST['payment_date']   ?? ''));
            $method     = trim((string) ($_POST['payment_method'] ?? 'cash'));
            $notes      = trim((string) ($_POST['notes']          ?? ''));
            $allowedMethods = ['cash', 'bank_transfer', 'check', 'other'];

            if (!$leaseId || $amountPaid <= 0 || !$period || !$payDate) {
                gjc_lease_json_fail('Missing required payment fields.');
            }

            if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
                gjc_lease_json_fail('Period covered must use YYYY-MM format.');
            }

            if (!in_array($method, $allowedMethods, true)) {
                $method = 'other';
            }

            $lease = $directory->leaseById($leaseId);
            if (!$lease) {
                gjc_lease_json_fail('Lease not found.');
            }

            if ($lease['status'] === 'pending') {
                gjc_lease_json_fail('This contract is still Pending, so no rent is being billed yet. Set it to Active first (Contract tab).');
            }

            // The period is the whole point of the record — refuse a month the
            // contract never bills, rather than filing it somewhere invisible.
            $inTerm  = array_filter($lease['schedule'], static fn (array $r): bool => $r['state'] !== 'off_contract');
            $termLabels = array_column($inTerm, 'period');
            if ($termLabels && !in_array($period, $termLabels, true)) {
                gjc_lease_json_fail(
                    'This lease only bills rent from ' . $termLabels[0] . ' to ' . end($termLabels) .
                    '. Change the period covered, or extend the contract dates first.'
                );
            }

            $db->beginTransaction();
            try {
                $refNo = $directory->recordRentPayment($leaseId, $amountPaid, $period, $payDate, $method, $notes, $adminId);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                error_log('[admin/api/leases.php] record_payment: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'The payment could not be saved. Check the period and amount, then try again — nothing was recorded.']);
                break;
            }

            // Due date follows the payments, not the other way round.
            $directory->syncNextDueDate($leaseId);

            logAudit($db, $adminId, $adminRole, 'TRANSACTION', 'merchant_rent_payments', null, [
                'lease_id' => $leaseId, 'amount_paid' => $amountPaid, 'period_covered' => $period,
                'payment_method' => $method, 'reference_no' => $refNo,
            ], $leaseId, ['target_id' => $refNo, 'reason' => $notes ?: null]);

            $state = gjc_lease_state($directory, $leaseId);
            $monthRow = null;
            foreach ($state['lease']['schedule'] ?? [] as $row) {
                if ($row['period'] === $period) {
                    $monthRow = $row;
                    break;
                }
            }

            // The tenant handed over cash and walked away with nothing; this is
            // their receipt. Best-effort — gjc_notify_rent_payment() swallows its
            // own failures so a notification problem cannot undo a collection.
            gjc_notify_rent_payment(
                $db,
                (int) $lease['merchant_user_id'],
                $amountPaid,
                $period,
                $refNo,
                (float) ($monthRow['shortfall'] ?? 0),
                $state['account']['next_due_date'] ?? null
            );

            $message = gjc_money_plain($amountPaid) . ' recorded for ' . date('F Y', strtotime($period . '-01')) . '.';
            if ($monthRow && $monthRow['shortfall'] > 0.005) {
                $message .= ' Still ' . gjc_money_plain($monthRow['shortfall']) . ' short for that month.';
            } elseif ($monthRow && $monthRow['overpaid'] > 0.005) {
                $message .= ' That is ' . gjc_money_plain($monthRow['overpaid']) . ' more than the month is charged — record advance rent on its own month instead if that was not intended.';
            }

            echo json_encode([
                'success' => true,
                'message' => $message . ' Reference ' . $refNo . '.',
                'ref'     => $refNo,
            ] + $state);
            break;
        }

        /* ── VOID PAYMENT (undo a mis-keyed entry) ────────────────────────── */
        case 'void_payment': {
            $leaseId   = (int) ($_POST['lease_id']   ?? 0);
            $paymentId = (int) ($_POST['payment_id'] ?? 0);

            if (!$leaseId || !$paymentId) {
                gjc_lease_json_fail('Invalid payment reference.');
            }

            $removed = $directory->voidRentPayment($paymentId, $leaseId);
            if (!$removed) {
                gjc_lease_json_fail('That payment no longer exists on this lease.');
            }

            $directory->syncNextDueDate($leaseId);

            $voidedLease = $directory->leaseById($leaseId);
            gjc_notify_rent_payment_voided(
                $db,
                (int) ($voidedLease['merchant_user_id'] ?? 0),
                (float) $removed['amount_paid'],
                (string) $removed['period_covered'],
                (string) $removed['reference_no']
            );

            logAudit($db, $adminId, $adminRole, 'TRANSACTION', 'merchant_rent_payments', [
                'payment_id' => $paymentId, 'lease_id' => $leaseId,
                'amount_paid' => $removed['amount_paid'], 'period_covered' => $removed['period_covered'],
                'payment_date' => $removed['payment_date'] ?? null,
                'payment_method' => $removed['payment_method'] ?? null,
                'reference_no' => $removed['reference_no'], 'status' => 'recorded',
            ], [
                'voided_payment_id' => $paymentId, 'lease_id' => $leaseId,
                'amount_paid' => $removed['amount_paid'], 'period_covered' => $removed['period_covered'],
                'reference_no' => $removed['reference_no'], 'status' => 'voided',
            ], $leaseId, ['target_id' => $removed['reference_no']]);

            echo json_encode([
                'success' => true,
                'message' => 'Removed ' . gjc_money_plain((float) $removed['amount_paid']) .
                             ' (' . $removed['reference_no'] . ') from this lease.',
            ] + gjc_lease_state($directory, $leaseId));
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
    }
} catch (\Throwable $e) {
    error_log('[admin/api/leases.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Nothing was changed.']);
}
