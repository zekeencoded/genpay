<?php
// ============================================================
//  student/api/cart.php
//  Session-backed shopping cart for the Student-as-Scanner flow.
//  Each scanned item is looked up fresh against merchant_inventory
//  on every request — the session only ever stores { item_id => qty }
//  so price/stock/restriction can never go stale inside the cart.
// ============================================================
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json');
gjc_require_role(['student']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') gjc_csrf_require_json();
gjc_ensure_inventory_sku_index($db);
gjc_ensure_cart_orders_schema($db);
gjc_ensure_order_items_schema($db);

$action = trim((string) ($_POST['action'] ?? ''));

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = ['merchant_user_id' => null, 'items' => []];
}

try {
    switch ($action) {
        case 'get_cart': {
            echo json_encode(['success' => true] + gjc_cart_snapshot($db));
            break;
        }

        case 'add_item': {
            $code = trim((string) ($_POST['code'] ?? ''));
            if ($code === '') {
                echo json_encode(['success' => false, 'message' => 'No barcode detected. Try scanning again.']);
                exit;
            }

            $lockedMerchantId = $_SESSION['cart']['merchant_user_id'] ?? null;

            if ($lockedMerchantId) {
                $stmt = $db->prepare(
                    "SELECT * FROM merchant_inventory WHERE LOWER(sku) = LOWER(?) AND merchant_user_id = ? LIMIT 1"
                );
                $stmt->execute([$code, $lockedMerchantId]);
            } else {
                $stmt = $db->prepare(
                    "SELECT * FROM merchant_inventory WHERE LOWER(sku) = LOWER(?) LIMIT 1"
                );
                $stmt->execute([$code]);
            }
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                $message = $lockedMerchantId
                    ? 'This item is not sold by the stall already in your cart. Clear your cart to start a new order at a different stall.'
                    : 'No product matches this barcode.';
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }

            if (gjc_merchant_sales_blocked($db, (int) $item['merchant_user_id'])) {
                echo json_encode(['success' => false, 'message' => 'This stall is temporarily suspended and cannot take orders right now.']);
                exit;
            }

            if ((int) $item['is_restricted'] === 1) {
                echo json_encode([
                    'success' => false,
                    'message' => $item['restriction_note']
                        ? "Blocked: {$item['restriction_note']}"
                        : "\"{$item['product_name']}\" violates campus health guidelines and cannot be purchased.",
                    'blocked' => true,
                ]);
                exit;
            }

            if ((int) $item['is_available'] !== 1) {
                echo json_encode(['success' => false, 'message' => "\"{$item['product_name']}\" is currently unavailable."]);
                exit;
            }

            $itemId = (int) $item['id'];
            $currentQty = (int) ($_SESSION['cart']['items'][$itemId] ?? 0);
            if ($currentQty + 1 > (int) $item['stock_qty']) {
                echo json_encode(['success' => false, 'message' => "Only {$item['stock_qty']} of \"{$item['product_name']}\" left in stock."]);
                exit;
            }

            if (!$lockedMerchantId) {
                $_SESSION['cart']['merchant_user_id'] = (int) $item['merchant_user_id'];
            }
            $_SESSION['cart']['items'][$itemId] = $currentQty + 1;

            echo json_encode(['success' => true, 'message' => "Added \"{$item['product_name']}\" to cart."] + gjc_cart_snapshot($db));
            break;
        }

        case 'update_qty': {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $qty = (int) ($_POST['qty'] ?? 0);

            if (!$itemId || !isset($_SESSION['cart']['items'][$itemId])) {
                echo json_encode(['success' => false, 'message' => 'Item not found in cart.']);
                exit;
            }

            if ($qty <= 0) {
                unset($_SESSION['cart']['items'][$itemId]);
            } else {
                $_SESSION['cart']['items'][$itemId] = $qty;
            }

            echo json_encode(['success' => true] + gjc_cart_snapshot($db));
            break;
        }

        case 'remove_item': {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            unset($_SESSION['cart']['items'][$itemId]);
            echo json_encode(['success' => true] + gjc_cart_snapshot($db));
            break;
        }

        case 'clear_cart': {
            $_SESSION['cart'] = ['merchant_user_id' => null, 'items' => []];
            echo json_encode(['success' => true] + gjc_cart_snapshot($db));
            break;
        }

        // ── SUBMIT ORDER (kiosk-style) ───────────────────────────────────────
        // Locks the cart into a pending order the merchant can see right away.
        // No money moves yet — that happens later when the student scans the
        // merchant's static Wallet QR at the counter (see checkout.php pay_order).
        case 'submit_order': {
            $studentUserId = gjc_user_id();

            $walletState = gjc_student_wallet_state($db, $studentUserId);
            if ($walletState === 'frozen_exit') {
                echo json_encode(['success' => false, 'message' => 'Account locked: this account has graduated or transferred out.']);
                exit;
            }
            if ($walletState === 'frozen_unenrolled') {
                echo json_encode(['success' => false, 'message' => 'You are not enrolled for the current school year. Your balance is safe and stays in your wallet — contact the registrar to be re-enrolled.']);
                exit;
            }

            $existingStmt = $db->prepare(
                "SELECT id FROM cart_orders
                  WHERE student_wallet_id IN (SELECT id FROM student_wallets WHERE user_id = ?)
                    AND status = 'pending' LIMIT 1"
            );
            $existingStmt->execute([$studentUserId]);
            if ($existingStmt->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'You already have an order awaiting payment. Pay or cancel it first.']);
                exit;
            }

            $snapshot = gjc_cart_snapshot($db);
            if (!empty($snapshot['dropped'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Some items in your cart changed. Please review your cart and try again.',
                ] + $snapshot);
                exit;
            }
            if (empty($snapshot['lines']) || $snapshot['total'] <= 0) {
                echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
                exit;
            }

            $studentWallet = gjc_student_wallet($db, $studentUserId);
            if ($studentWallet['id'] <= 0) {
                echo json_encode(['success' => false, 'message' => 'Student wallet not found.']);
                exit;
            }

            $merchantUserId = (int) $snapshot['merchant_user_id'];
            $merchantWallet = gjc_merchant_wallet($db, $merchantUserId);
            if ($merchantWallet['id'] <= 0) {
                echo json_encode(['success' => false, 'message' => 'Merchant wallet not found.']);
                exit;
            }

            $itemsSummary = implode(', ', array_map(
                fn($line) => "{$line['qty']}x {$line['name']}",
                $snapshot['lines']
            ));
            $refNo = gjc_reference('CART');
            $legacyItemsJson = gjc_order_has_legacy_items_json($db, 'cart');

            $db->beginTransaction();
            try {
                $legacyUserIds = gjc_has_legacy_wallet_user_ids($db, 'cart_orders');
                $db->prepare(
                    "INSERT INTO cart_orders
                        (reference_no" . ($legacyUserIds ? ", student_user_id" : "") . ", student_wallet_id"
                        . ($legacyUserIds ? ", merchant_user_id" : "") . ", merchant_wallet_id,
                         description" . ($legacyItemsJson ? ", items_json" : "") . ", amount, status)
                     VALUES (?, " . ($legacyUserIds ? "?, " : "") . "?, " . ($legacyUserIds ? "?, " : "") . "?, ?, " . ($legacyItemsJson ? "?, " : "") . "?, 'pending')"
                )->execute([
                    $refNo,
                    ...($legacyUserIds ? [$studentUserId] : []),
                    $studentWallet['id'],
                    ...($legacyUserIds ? [$merchantUserId] : []),
                    $merchantWallet['id'],
                    $itemsSummary,
                    ...($legacyItemsJson
                        ? [json_encode($snapshot['lines'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
                        : []),
                    $snapshot['total'],
                ]);
                $orderId = (int) $db->lastInsertId();
                gjc_store_order_items($db, 'cart', $orderId, $snapshot['lines']);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            $_SESSION['cart'] = ['merchant_user_id' => null, 'items' => []];

            echo json_encode([
                'success' => true,
                'message' => "Order submitted! Go to the counter and scan the shop's Wallet QR to pay.",
                'order' => [
                    'reference' => $refNo,
                    'merchant_user_id' => $merchantUserId,
                    'merchant_label' => $snapshot['merchant_label'],
                    'lines' => $snapshot['lines'],
                    'total' => $snapshot['total'],
                    'status' => 'pending',
                ],
            ]);
            break;
        }

        case 'get_pending_order': {
            $stmt = $db->prepare(
                "SELECT o.id, o.reference_no,
                        (SELECT mw.user_id FROM merchant_wallets mw WHERE mw.id = o.merchant_wallet_id) AS merchant_user_id,
                        o.amount, o.status
                   FROM cart_orders o
                  WHERE o.student_wallet_id IN (SELECT id FROM student_wallets WHERE user_id = ?)
                    AND o.status = 'pending'
                  ORDER BY o.created_at DESC
                  LIMIT 1"
            );
            $stmt->execute([gjc_user_id()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode(['success' => true, 'order' => null]);
                exit;
            }

            $lines = gjc_order_item_lines($db, 'cart', (int) $row['id']);
            echo json_encode([
                'success' => true,
                'order' => [
                    'reference' => $row['reference_no'],
                    'merchant_user_id' => (int) $row['merchant_user_id'],
                    'merchant_label' => gjc_merchant_display_name($db, (int) $row['merchant_user_id']),
                    'lines' => is_array($lines) ? $lines : [],
                    'total' => (float) $row['amount'],
                    'status' => $row['status'],
                ],
            ]);
            break;
        }

        case 'cancel_my_order': {
            $studentUserId = gjc_user_id();
            $stmt = $db->prepare(
                "SELECT * FROM cart_orders
                  WHERE student_wallet_id IN (SELECT id FROM student_wallets WHERE user_id = ?)
                    AND status = 'pending' ORDER BY created_at DESC LIMIT 1"
            );
            $stmt->execute([$studentUserId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                echo json_encode(['success' => false, 'message' => 'No order awaiting payment to cancel.']);
                exit;
            }

            $db->prepare("UPDATE cart_orders SET status = 'voided' WHERE id = ?")->execute([(int) $order['id']]);

            logAudit(
                $db,
                $studentUserId,
                gjc_current_role(),
                'TRANSACTION',
                'cart_orders',
                ['reference_no' => $order['reference_no'], 'status' => 'pending'],
                ['reference_no' => $order['reference_no'], 'status' => 'voided', 'cancelled_by' => 'student'],
                null,
                ['target_id' => $order['reference_no']]
            );

            echo json_encode(['success' => true, 'message' => 'Order cancelled.']);
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'student/api/cart', 'Server error. Please try again.')]);
}
