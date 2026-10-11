<?php
// ============================================================
//  parent/api/checkout.php
//  Pays the parent's pending Shop Cart order (see parent/api/cart.php's
//  submit_order) against the merchant's static Wallet QR (merchant/settings.php).
//  The parent-side twin of student/api/checkout.php.
//  The QR only identifies the merchant — the order and its locked-in price
//  always come from the cart_orders row the parent already submitted,
//  never from the client.
// ============================================================
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json');
gjc_require_role(['parent']);

if (!gjc_csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'csrf', 'message' => 'Security check failed. Please refresh the page and try again.']);
    exit;
}

gjc_ensure_cart_orders_schema($db);
gjc_ensure_order_items_schema($db);
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

    $currentUser  = gjc_current_user($db);
    $parentUserId = (int) $currentUser['id'];
    $parentId     = gjc_parent_id_for_user($db, $parentUserId);

    $db->beginTransaction();
    try {
        $orderStmt = $db->prepare(
            "SELECT o.*,
                    (SELECT mw.user_id FROM merchant_wallets mw WHERE mw.id = o.merchant_wallet_id) AS merchant_user_id
               FROM cart_orders o
              WHERE o.parent_wallet_id IN (
                    SELECT pw.id FROM parent_wallets pw JOIN parents p ON p.id = pw.parent_id
                     WHERE p.user_id = ?)
                AND o.status = 'pending'
              ORDER BY o.created_at DESC
              LIMIT 1
              FOR UPDATE"
        );
        $orderStmt->execute([$parentUserId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new RuntimeException('You don\'t have an order awaiting payment.');
        }
        $scannedOrderReference = trim((string) ($_POST['order_reference'] ?? ''));
        if ($scannedOrderReference !== '' && $scannedOrderReference !== $order['reference_no']) {
            throw new RuntimeException('This payment QR belongs to a different order. Ask the merchant to open your order\'s QR.');
        }
        if ((int) $order['merchant_user_id'] !== $scannedMerchantUserId) {
            throw new RuntimeException('This Wallet QR belongs to a different stall than your pending order.');
        }

        if (gjc_merchant_sales_blocked($db, (int) $order['merchant_user_id'])) {
            throw new RuntimeException('This stall is temporarily suspended and cannot accept payments right now. Please cancel this order.');
        }

        $lines = gjc_order_item_lines($db, 'cart', (int) $order['id']);
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

        $parentWallet = $parentId ? gjc_parent_wallet($db, $parentId) : ['id' => 0];
        if ((int) $parentWallet['id'] <= 0) {
            throw new RuntimeException('Parent wallet not found.');
        }

        $total = round((float) $order['amount'], 2);

        // No graduated / frozen / daily-limit checks here, deliberately: those
        // are controls a parent imposes on a STUDENT wallet
        // (student_wallets.is_frozen, daily_spend_limit). A parent's own wallet
        // has no supervisor above it, so the student version's guard block has
        // no parent-side equivalent.

        $debitStmt = $db->prepare(
            "UPDATE parent_wallets SET balance = balance - ? WHERE id = ? AND balance >= ?"
        );
        $debitStmt->execute([$total, (int) $parentWallet['id'], $total]);
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
                    + (SELECT COALESCE(SUM(balance),0) FROM parent_wallets)
                    + (SELECT COALESCE(SUM(remaining_balance),0) FROM vouchers WHERE status='active'))
               FROM system_settings WHERE id = 1"
        )->fetchColumn();

        // Reuse the order's own reference so the submitted order and the
        // resulting payment are visibly the same transaction to everyone.
        $refNo = $order['reference_no'];

        $db->prepare(
            "INSERT INTO transactions
                (reference_no, transaction_type, initiated_by, parent_wallet_id, merchant_wallet_id,
                 amount, vault_before, vault_after, total_in_circulation, status, notes, school_year_id)
             VALUES (?, 'payment', ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)"
        )->execute([
            $refNo,
            $parentUserId,
            (int) $parentWallet['id'],
            (int) $order['merchant_wallet_id'],
            $total,
            $vaultBefore,
            $vaultBefore,
            $totalCirc,
            $order['description'],
            gjc_active_school_year_id($db),
        ]);

        gjc_store_transaction_items($db, (int) $db->lastInsertId(), $lines);

        $db->prepare(
            "UPDATE cart_orders SET status = 'paid', paid_at = NOW(), paid_ref = ? WHERE id = ?"
        )->execute([$refNo, (int) $order['id']]);

        $db->commit();

        logAudit(
            $db,
            $parentUserId,
            gjc_current_role(),
            'TRANSACTION',
            'transactions',
            null,
            [
                'reference_no' => $refNo,
                'transaction_type' => 'payment',
                'amount' => $total,
                'parent_wallet_id' => (int) $parentWallet['id'],
                'merchant_wallet_id' => (int) $order['merchant_wallet_id'],
                'items' => $lines,
                'status' => 'completed',
            ],
            null,
            ['target_id' => $refNo]
        );

        gjc_notify(
            $db,
            $parentUserId,
            'payment',
            'Payment Successful',
            sprintf('You paid %s at %s.', gjc_money_plain($total), gjc_user_label($db, (int) $order['merchant_user_id'])),
            'cart-shopping',
            PARENT_URL . '/activity.php'
        );

        gjc_notify(
            $db,
            (int) $order['merchant_user_id'],
            'sale',
            'Payment Received',
            sprintf('%s paid %s at your stall.', gjc_user_label($db, $parentUserId), gjc_money_plain($total)),
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
        echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'parent/api/checkout', 'Something went wrong. Please try again.')]);
    }
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'parent/api/checkout', 'Server error. Please try again.')]);
}
