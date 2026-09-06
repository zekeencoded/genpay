<?php
// ============================================================
//  student/api/checkout.php
//  Pays the student's pending Shop Cart order (see student/api/cart.php's
//  submit_order) against the merchant's static Wallet QR (merchant/settings.php).
//  The QR only identifies the merchant — the order and its locked-in price
//  always come from the cart_orders row the student already submitted,
//  never from the client.
// ============================================================
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

gjc_ensure_cart_orders_schema($db);
gjc_ensure_parent_schema($db);

$action = trim((string) ($_POST['action'] ?? ''));

try {
    if ($action !== 'pay_order') {
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }

    $scannedMerchantUserId = (int) ($_POST['merchant_user_id'] ?? 0);
    if (!$scannedMerchantUserId) {
        echo json_encode(['success' => false, 'message' => 'Invalid Shop Wallet QR.']);
        exit;
    }

    $currentUser = gjc_current_user($db);
    $studentUserId = (int) $currentUser['id'];

    $db->beginTransaction();
    try {
        $orderStmt = $db->prepare(
            "SELECT * FROM cart_orders
              WHERE student_user_id = ? AND status = 'pending'
              ORDER BY created_at DESC
              LIMIT 1
              FOR UPDATE"
        );
        $orderStmt->execute([$studentUserId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new RuntimeException('You don\'t have an order awaiting payment.');
        }
        if ((int) $order['merchant_user_id'] !== $scannedMerchantUserId) {
            throw new RuntimeException('This Wallet QR belongs to a different stall than your pending order.');
        }

        if (gjc_merchant_sales_blocked($db, (int) $order['merchant_user_id'])) {
            throw new RuntimeException('This stall is temporarily suspended and cannot accept payments right now. Please cancel this order.');
        }

        $lines = json_decode((string) $order['items_json'], true);
        if (!is_array($lines) || empty($lines)) {
            throw new RuntimeException('This order has no item details recorded.');
        }

        // Re-validate every line against the live catalog — price was locked
        // at submission time, but availability/stock must still hold right now.
        foreach ($lines as $line) {
            $itemId = (int) ($line['id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);
            $checkStmt = $db->prepare(
                "SELECT product_name FROM merchant_inventory
                  WHERE id = ? AND merchant_user_id = ?
                    AND is_available = 1 AND is_restricted = 0 AND stock_qty >= ?"
            );
            $checkStmt->execute([$itemId, (int) $order['merchant_user_id'], $qty]);
            if (!$checkStmt->fetchColumn()) {
                throw new RuntimeException("\"{$line['name']}\" is no longer available in the quantity you ordered. Cancel this order and submit a new one.");
            }
        }

        $studentWallet = gjc_student_wallet($db, $studentUserId);
        if ($studentWallet['id'] <= 0) {
            throw new RuntimeException('Student wallet not found.');
        }

        $total = round((float) $order['amount'], 2);

        // Three-state gate: a graduate/transfer-out is terminal, an unenrolled
        // student keeps their balance and can be re-enrolled by the registrar.
        $walletState = gjc_student_wallet_state($db, $studentUserId);
        if ($walletState === 'frozen_exit') {
            throw new \RuntimeException('Account locked: this account has graduated or transferred out.');
        }
        if ($walletState === 'frozen_unenrolled') {
            throw new \RuntimeException('You are not enrolled for the current school year. Your balance is safe and stays in your wallet — contact the registrar to be re-enrolled.');
        }

        // ── Parent wallet controls ──────────────────────────────────────────
        $wcStmt = $db->prepare("SELECT is_frozen, daily_spend_limit FROM student_wallets WHERE id = ?");
        $wcStmt->execute([$studentWallet['id']]);
        $wc = $wcStmt->fetch(PDO::FETCH_ASSOC);
        if ($wc && (int) $wc['is_frozen'] === 1) {
            throw new \RuntimeException('This wallet is frozen by a parent or guardian.');
        }
        if ($wc && (float) $wc['daily_spend_limit'] > 0) {
            $spentStmt = $db->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM transactions
                  WHERE student_wallet_id = ? AND transaction_type IN ('payment','p2p_transfer')
                    AND DATE(created_at) = CURDATE() AND status = 'completed'"
            );
            $spentStmt->execute([$studentWallet['id']]);
            $todaySpent = (float) $spentStmt->fetchColumn();
            if ($todaySpent + $total > (float) $wc['daily_spend_limit']) {
                throw new \RuntimeException('Daily spending limit of ₱' . number_format((float) $wc['daily_spend_limit'], 2) . ' has been reached.');
            }
        }
        // ───────────────────────────────────────────────────────────────────

        $debitStmt = $db->prepare(
            "UPDATE student_wallets SET balance = balance - ? WHERE id = ? AND balance >= ?"
        );
        $debitStmt->execute([$total, $studentWallet['id'], $total]);
        if ($debitStmt->rowCount() === 0) {
            throw new RuntimeException('Insufficient wallet balance.');
        }

        $db->prepare(
            "UPDATE merchant_wallets SET balance = balance + ? WHERE id = ?"
        )->execute([$total, (int) $order['merchant_wallet_id']]);

        foreach ($lines as $line) {
            $stockStmt = $db->prepare(
                "UPDATE merchant_inventory
                    SET stock_qty = stock_qty - ?
                  WHERE id = ?
                    AND merchant_user_id = ?
                    AND stock_qty >= ?
                    AND is_available = 1
                    AND is_restricted = 0"
            );
            $stockStmt->execute([(int) $line['qty'], (int) $line['id'], (int) $order['merchant_user_id'], (int) $line['qty']]);
            if ($stockStmt->rowCount() === 0) {
                throw new RuntimeException("\"{$line['name']}\" is no longer available in the quantity you ordered. Payment was not completed.");
            }
        }

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

        // Reuse the order's own reference so the submitted order and the
        // resulting payment are visibly the same transaction to everyone.
        $refNo = $order['reference_no'];

        $db->prepare(
            "INSERT INTO transactions
                (reference_no, transaction_type, initiated_by, student_wallet_id, merchant_wallet_id,
                 amount, vault_before, vault_after, total_in_circulation, status, notes, school_year_id)
             VALUES (?, 'payment', ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)"
        )->execute([
            $refNo,
            $studentUserId,
            (int) $studentWallet['id'],
            (int) $order['merchant_wallet_id'],
            $total,
            $vaultBefore,
            $vaultBefore,
            $totalCirc,
            $order['description'],
            gjc_active_school_year_id($db),
        ]);

        $db->prepare(
            "UPDATE cart_orders SET status = 'paid', paid_at = NOW(), paid_ref = ? WHERE id = ?"
        )->execute([$refNo, (int) $order['id']]);

        $db->commit();
        gjc_check_parent_balance_alert($db, (int) $studentWallet['id']);

        logAudit(
            $db,
            $studentUserId,
            gjc_current_role(),
            'TRANSACTION',
            'e_wallet_transactions',
            null,
            [
                'reference_no' => $refNo,
                'transaction_type' => 'payment',
                'amount' => $total,
                'student_wallet_id' => (int) $studentWallet['id'],
                'merchant_wallet_id' => (int) $order['merchant_wallet_id'],
                'items' => $lines,
                'status' => 'completed',
            ]
        );

        gjc_notify(
            $db,
            $studentUserId,
            'payment',
            'Payment Successful',
            sprintf('You paid %s at %s.', gjc_money_plain($total), gjc_user_label($db, (int) $order['merchant_user_id'])),
            'cart-shopping',
            STUDENT_URL . '/history.php'
        );

        gjc_notify(
            $db,
            (int) $order['merchant_user_id'],
            'sale',
            'Payment Received',
            sprintf('%s paid %s at your stall.', gjc_user_label($db, $studentUserId), gjc_money_plain($total)),
            'cart-shopping',
            MERCHANT_URL . '/history.php'
        );

        echo json_encode([
            'success' => true,
            'message' => 'Payment completed.',
            'reference' => $refNo,
            'total' => $total,
        ]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
