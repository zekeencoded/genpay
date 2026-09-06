<?php
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json');
gjc_require_role(['student']);

if (!gjc_csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'csrf', 'message' => 'Security check failed. Please refresh the page and try again.']);
    exit;
}

gjc_ensure_parent_schema($db);

$action        = trim((string) ($_POST['action'] ?? ''));
$currentUserId = gjc_user_id();
$DAILY_LIMIT   = gjc_setting($db, 'transfer_daily_limit');
$MIN_TRANSFER  = gjc_setting($db, 'transfer_min_amount');

try {
    // ── LOOKUP: find student by student ID ───────────────────────────────
    if ($action === 'lookup') {
        $studentIdInput = trim((string) ($_POST['student_id'] ?? ''));
        if (!$studentIdInput) {
            echo json_encode(['success' => false, 'message' => 'Student ID required.']);
            exit;
        }

        $stmt = $db->prepare(
            "SELECT u.userID, u.first_name, u.last_name
               FROM users u
               JOIN student_info si ON si.userID = u.userID
              WHERE si.studentID = ? AND u.roleID = 1
              LIMIT 1"
        );
        $stmt->execute([$studentIdInput]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$found) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        if ((int) $found['userID'] === $currentUserId) {
            echo json_encode(['success' => false, 'message' => 'You cannot look up yourself.']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'name'    => trim($found['first_name'] . ' ' . $found['last_name']),
            'user_id' => (int) $found['userID'],
        ]);
        exit;
    }

    // ── TRANSFER ─────────────────────────────────────────────────────────
    if ($action === 'transfer') {
        $recipientStudentId = trim((string) ($_POST['recipient_student_id'] ?? ''));
        $amount             = round((float) ($_POST['amount'] ?? 0), 2);
        $message            = trim((string) ($_POST['message'] ?? ''));

        // --- Input validation ---
        if (!$recipientStudentId || $amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Recipient ID and valid amount are required.']);
            exit;
        }

        if ($amount < $MIN_TRANSFER) {
            echo json_encode(['success' => false, 'message' => 'Minimum transfer amount is ' . gjc_money($MIN_TRANSFER) . '.']);
            exit;
        }

        // --- Find recipient ---
        $recipStmt = $db->prepare(
            "SELECT u.userID, u.first_name, u.last_name
               FROM users u
               JOIN student_info si ON si.userID = u.userID
              WHERE si.studentID = ? AND u.roleID = 1
              LIMIT 1"
        );
        $recipStmt->execute([$recipientStudentId]);
        $recipient = $recipStmt->fetch(PDO::FETCH_ASSOC);

        if (!$recipient) {
            echo json_encode(['success' => false, 'message' => 'Recipient student not found. Check the Student ID and try again.']);
            exit;
        }

        $recipientUserId = (int) $recipient['userID'];
        if ($recipientUserId === $currentUserId) {
            echo json_encode(['success' => false, 'message' => 'You cannot transfer tokens to yourself.']);
            exit;
        }

        // The sender's own suspension already ended their session; this is the
        // other half — money must not land in a suspended account either.
        $recipientBlocked = gjc_funds_in_block_reason($db, $recipientUserId);
        if ($recipientBlocked !== null) {
            echo json_encode(['success' => false, 'message' => $recipientBlocked]);
            exit;
        }

        // --- Daily limit check ---
        $dailySent = gjc_p2p_daily_sent($db, $currentUserId);
        if ($dailySent + $amount > $DAILY_LIMIT) {
            $remaining = max(0, $DAILY_LIMIT - $dailySent);
            echo json_encode([
                'success' => false,
                'message' => sprintf('Daily transfer limit exceeded. Remaining today: %s', gjc_money($remaining)),
            ]);
            exit;
        }

        // --- Get sender wallet (for balance check BEFORE transaction) ---
        $senderWallet = gjc_student_wallet($db, $currentUserId);
        if ($senderWallet['id'] === 0) {
            echo json_encode(['success' => false, 'message' => 'Sender wallet not found.']);
            exit;
        }
        if ($senderWallet['balance'] < $amount) {
            echo json_encode(['success' => false, 'message' => 'Insufficient wallet balance.']);
            exit;
        }

        $walletState = gjc_student_wallet_state($db, $currentUserId);
        if ($walletState === 'frozen_exit') {
            echo json_encode(['success' => false, 'message' => 'Account locked: this account has graduated or transferred out.']);
            exit;
        }
        if ($walletState === 'frozen_unenrolled') {
            echo json_encode(['success' => false, 'message' => 'You are not enrolled for the current school year. Your balance is safe and stays in your wallet — contact the registrar to be re-enrolled.']);
            exit;
        }

        // --- Parent wallet controls check on sender ---
        $wcStmt = $db->prepare("SELECT is_frozen, daily_spend_limit FROM student_wallets WHERE id = ?");
        $wcStmt->execute([$senderWallet['id']]);
        $wc = $wcStmt->fetch(PDO::FETCH_ASSOC);
        if ($wc && (int) $wc['is_frozen'] === 1) {
            echo json_encode(['success' => false, 'message' => 'Your wallet is frozen by a parent or guardian.']);
            exit;
        }
        if ($wc && (float) $wc['daily_spend_limit'] > 0) {
            $spentStmt = $db->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM transactions
                  WHERE student_wallet_id = ? AND transaction_type = 'p2p_transfer'
                    AND DATE(created_at) = CURDATE() AND status = 'completed'"
            );
            $spentStmt->execute([$senderWallet['id']]);
            $todaySpent = (float) $spentStmt->fetchColumn();
            if ($todaySpent + $amount > (float) $wc['daily_spend_limit']) {
                echo json_encode(['success' => false, 'message' => 'Daily spending limit of ₱' . number_format($wc['daily_spend_limit'], 2) . ' has been reached.']);
                exit;
            }
        }

        // --- Get recipient wallet ---
        $recipientWallet = gjc_student_wallet($db, $recipientUserId);
        if ($recipientWallet['id'] === 0) {
            echo json_encode(['success' => false, 'message' => 'Recipient wallet not found.']);
            exit;
        }

        $refNo = 'P2P-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $recipientName = trim($recipient['first_name'] . ' ' . $recipient['last_name']);

        // --- BEGIN TRANSACTION ---
        $db->beginTransaction();
        try {
            // 1. Debit sender
            $debitStmt = $db->prepare(
                "UPDATE student_wallets
                    SET balance = balance - ?
                  WHERE id = ? AND balance >= ?"
            );
            $debitStmt->execute([$amount, $senderWallet['id'], $amount]);
            if ($debitStmt->rowCount() === 0) {
                throw new \RuntimeException('Insufficient balance or concurrent modification detected.');
            }

            // 2. Credit recipient
            $db->prepare(
                "UPDATE student_wallets SET balance = balance + ? WHERE id = ?"
            )->execute([$amount, $recipientWallet['id']]);

            // 3. Snapshot vault (unchanged for P2P)
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

            // 4. Log to transactions table (debit side)
            $db->prepare(
                "INSERT INTO transactions
                    (reference_no, transaction_type, initiated_by, student_wallet_id, amount,
                     vault_before, vault_after, total_in_circulation, status, notes, school_year_id)
                 VALUES (?, 'p2p_transfer', ?, ?, ?, ?, ?, ?, 'completed', ?, ?)"
            )->execute([
                $refNo, $currentUserId, $senderWallet['id'], $amount,
                $vaultBefore, $vaultBefore, $totalCirc,
                'P2P Transfer to ' . $recipientName . ($message ? ' — ' . $message : ''),
                gjc_active_school_year_id($db),
            ]);

            // 5. Log p2p_transfers record
            $db->prepare(
                "INSERT INTO p2p_transfers
                    (reference_no, from_wallet_id, to_wallet_id, from_user_id, to_user_id, amount, message, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')"
            )->execute([
                $refNo,
                $senderWallet['id'],
                $recipientWallet['id'],
                $currentUserId,
                $recipientUserId,
                $amount,
                $message ?: null,
            ]);

            // --- COMMIT ---
            $db->commit();
            gjc_check_parent_balance_alert($db, $senderWallet['id']);
            logAudit(
                $db,
                $currentUserId,
                gjc_current_role(),
                'TRANSACTION',
                'e_wallet_transactions',
                null,
                [
                    'reference_no' => $refNo,
                    'transaction_type' => 'p2p_transfer',
                    'amount' => $amount,
                    'from_user_id' => $currentUserId,
                    'to_user_id' => $recipientUserId,
                    'from_wallet_id' => $senderWallet['id'],
                    'to_wallet_id' => $recipientWallet['id'],
                    'status' => 'completed',
                ]
            );

            $senderName = gjc_user_label($db, $currentUserId);
            gjc_notify(
                $db,
                $currentUserId,
                'transfer_out',
                'GenCoin Sent',
                sprintf('You sent %s to %s.', gjc_money_plain($amount), $recipientName),
                'paper-plane',
                STUDENT_URL . '/history.php'
            );
            gjc_notify(
                $db,
                $recipientUserId,
                'transfer_in',
                'GenCoin Received',
                sprintf('%s sent you %s (%s GC).', $senderName, gjc_money_plain($amount), number_format($amount / 10, 1)),
                'arrow-down',
                STUDENT_URL . '/history.php'
            );

            echo json_encode([
                'success'   => true,
                'reference' => $refNo,
                'message'   => sprintf(
                    'Sent %s (%s GenCoins) to %s.',
                    gjc_money($amount),
                    number_format($amount / 10, 1),
                    $recipientName
                ),
            ]);
        } catch (\Throwable $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Transfer failed: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
