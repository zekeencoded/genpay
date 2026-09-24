<?php
// ============================================================
//  admin/api/maintenance_fees.php
//  JSON API for the Admin Wallet's maintenance-fee ledger. Like the Fee Waiver
//  Credit this is a non-wallet tuition-assessment line: it never touches
//  CirculationEngine, student_wallets, or system_settings.
//
//  Actions:
//    collect_student   accrued -> collected, every accrued row for one student,
//                      under one receipt (maintenance_fee_collections)
//    collect_all       the same for every student with accrued fees — one
//                      receipt per student, all in one transaction
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json; charset=UTF-8');
gjc_require_role(['finance']);
gjc_ensure_audit_table($db);
gjc_ensure_maintenance_fees_schema($db);

$action    = trim((string) ($_POST['action'] ?? ''));
$adminId   = gjc_user_id();
$adminRole = gjc_current_role();

function mf_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

/** Audit one collection receipt (called after its transaction committed). */
function mf_log_collection(PDO $db, int $adminId, string $adminRole, int $studentUserId, array $receipt): void
{
    logAudit(
        $db, $adminId, $adminRole, 'MAINTENANCE_FEE_COLLECTED', 'maintenance_fees',
        ['student_user_id' => $studentUserId, 'status' => 'accrued'],
        [
            'student_user_id' => $studentUserId,
            'status'          => 'collected',
            'receipt_no'      => $receipt['receipt_no'],
            'entries'         => $receipt['entries'],
            'total'           => $receipt['total'],
        ],
        null,
        ['target_id' => $receipt['receipt_no']]
    );
}

try {
    switch ($action) {

        case 'collect_student': {
            $studentUserId = (int) ($_POST['student_user_id'] ?? 0);
            if ($studentUserId <= 0) {
                mf_json(['success' => false, 'message' => 'Student not found.']);
            }

            $db->beginTransaction();
            $receipt = gjc_collect_maintenance_fees($db, $studentUserId, $adminId);
            if ($receipt === null) {
                $db->rollBack();
                mf_json(['success' => false, 'message' => 'This student has no uncollected maintenance fees.']);
            }
            $db->commit();

            mf_log_collection($db, $adminId, $adminRole, $studentUserId, $receipt);

            mf_json([
                'success' => true,
                'receipt_no' => $receipt['receipt_no'],
                'message' => sprintf(
                    '%d fee%s totalling %s marked as collected. Receipt %s.',
                    $receipt['entries'], $receipt['entries'] === 1 ? '' : 's',
                    gjc_money_plain($receipt['total']), $receipt['receipt_no']
                ),
            ]);
        }

        // One receipt per student, all created in one transaction: either every
        // accrued fee is settled or none is.
        case 'collect_all': {
            $studentIds = $db->query(
                "SELECT DISTINCT student_user_id FROM maintenance_fees WHERE status = 'accrued'"
            )->fetchAll(PDO::FETCH_COLUMN);

            $receipts = [];
            $db->beginTransaction();
            foreach ($studentIds as $sid) {
                $receipt = gjc_collect_maintenance_fees($db, (int) $sid, $adminId);
                if ($receipt !== null) {
                    $receipts[(int) $sid] = $receipt;
                }
            }
            if (!$receipts) {
                $db->rollBack();
                mf_json(['success' => false, 'message' => 'There are no uncollected maintenance fees.']);
            }
            $db->commit();

            $entries = 0;
            $total = 0.0;
            foreach ($receipts as $sid => $receipt) {
                mf_log_collection($db, $adminId, $adminRole, $sid, $receipt);
                $entries += $receipt['entries'];
                $total += $receipt['total'];
            }

            mf_json([
                'success' => true,
                'message' => sprintf(
                    '%d fee%s totalling %s collected across %d student%s — %d receipt%s issued.',
                    $entries, $entries === 1 ? '' : 's', gjc_money_plain($total),
                    count($receipts), count($receipts) === 1 ? '' : 's',
                    count($receipts), count($receipts) === 1 ? '' : 's'
                ),
            ]);
        }

        default:
            mf_json(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[admin/api/maintenance_fees.php] ' . $e->getMessage());
    mf_json(['success' => false, 'message' => 'A server error occurred.']);
}
