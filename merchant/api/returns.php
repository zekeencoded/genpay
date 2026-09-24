<?php
// ============================================================
//  merchant/api/returns.php
//  Post-payment Return/Exchange pipeline (Rule 5b). Never edits or deletes
//  the original transaction row — only flips its status to 'reversed' and
//  records the actual money movement as a brand-new 'refund' ledger entry,
//  then logs the reason into systemic_audit_trail.
// ============================================================
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json');
gjc_require_role(['merchant']);
gjc_ensure_transaction_refund_type($db);
gjc_ensure_maintenance_fees_schema($db);

$action = trim((string) ($_POST['action'] ?? ''));
$merchantUserId = gjc_user_id();
$ownerMerchId = gjc_merchant_owner_id($db, $merchantUserId);

try {
    if ($action !== 'issue_return') {
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }

    $transactionId = (int) ($_POST['transaction_id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if (!$transactionId || $reason === '') {
        echo json_encode(['success' => false, 'message' => 'A transaction and reason are required.']);
        exit;
    }

    $merchantWallet = gjc_merchant_wallet($db, $ownerMerchId);
    if ($merchantWallet['id'] <= 0) {
        echo json_encode(['success' => false, 'message' => 'Merchant wallet not found.']);
        exit;
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "SELECT * FROM transactions WHERE id = ? AND merchant_wallet_id = ? FOR UPDATE"
        );
        $stmt->execute([$transactionId, $merchantWallet['id']]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$original) {
            throw new RuntimeException('Transaction not found for this merchant.');
        }
        if ($original['status'] !== 'completed') {
            throw new RuntimeException('Only completed transactions can be returned.');
        }
        if ($original['transaction_type'] === 'voucher_payment') {
            throw new RuntimeException('Visitor voucher payments are non-refundable by policy.');
        }
        if ($original['transaction_type'] !== 'payment') {
            throw new RuntimeException('This transaction type cannot be returned.');
        }
        if (!$original['student_wallet_id']) {
            throw new RuntimeException('No student wallet is linked to this transaction.');
        }

        $amount = round((float) $original['amount'], 2);

        $debitMerchant = $db->prepare(
            "UPDATE merchant_wallets SET balance = balance - ? WHERE id = ? AND balance >= ?"
        );
        $debitMerchant->execute([$amount, $merchantWallet['id'], $amount]);
        if ($debitMerchant->rowCount() === 0) {
            throw new RuntimeException('Insufficient merchant wallet balance to process this return.');
        }

        $db->prepare(
            "UPDATE student_wallets SET balance = balance + ? WHERE id = ?"
        )->execute([$amount, (int) $original['student_wallet_id']]);

        $vaultBefore = (float) $db->query(
            "SELECT cashier_vault_points FROM system_settings WHERE id = 1"
        )->fetchColumn();

        $totalCirc = (float) $db->query(
            "SELECT (cashier_vault_points
                    + (SELECT COALESCE(SUM(balance),0) FROM student_wallets)
                    + (SELECT COALESCE(SUM(balance),0) FROM merchant_wallets)
                    + (SELECT COALESCE(SUM(remaining_balance),0) FROM vouchers WHERE status='active'))
               FROM system_settings WHERE id = 1"
        )->fetchColumn();

        $refundRefNo = gjc_reference('RTN');
        $noteLine = "Return for {$original['reference_no']}: {$reason}" . ($notes !== '' ? " — {$notes}" : '');

        $db->prepare(
            "INSERT INTO transactions
                (reference_no, transaction_type, initiated_by, student_wallet_id, merchant_wallet_id,
                 amount, vault_before, vault_after, total_in_circulation, status, notes, school_year_id)
             VALUES (?, 'refund', ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)"
        )->execute([
            $refundRefNo,
            $merchantUserId,
            (int) $original['student_wallet_id'],
            $merchantWallet['id'],
            $amount,
            $vaultBefore,
            $vaultBefore,
            $totalCirc,
            $noteLine,
            gjc_active_school_year_id($db),
        ]);

        // The original sale record is never edited or deleted — only its status changes.
        $db->prepare(
            "UPDATE transactions SET status = 'reversed' WHERE id = ?"
        )->execute([$transactionId]);

        // The sale is undone, so its still-accrued maintenance fee is too.
        gjc_void_maintenance_fee($db, (string) $original['reference_no']);

        $db->commit();

        logAudit(
            $db,
            $merchantUserId,
            gjc_current_role(),
            'TRANSACTION',
            'transactions',
            [
                'reference_no' => $original['reference_no'],
                'status' => 'completed',
                'amount' => $amount,
            ],
            [
                'reference_no' => $original['reference_no'],
                'status' => 'reversed',
                'refund_reference' => $refundRefNo,
                'reason' => $reason,
                'notes' => $notes,
                'amount' => $amount,
            ],
            null,
            ['target_id' => $refundRefNo, 'reason' => $reason]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Return completed.',
            'reference' => $refundRefNo,
        ]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'merchant/api/returns', 'Something went wrong. Please try again.')]);
    }
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'merchant/api/returns', 'Server error. Please try again.')]);
}
