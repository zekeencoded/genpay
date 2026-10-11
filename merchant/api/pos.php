<?php
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';
require_once __DIR__ . '/../../connection/CirculationEngine.php';

header('Content-Type: application/json');
gjc_require_role(['merchant']);
gjc_ensure_merchant_qr_orders_schema($db);
gjc_ensure_cart_orders_schema($db);
gjc_ensure_order_items_schema($db);
gjc_ensure_parent_schema($db);
gjc_ensure_sales_remittances_schema($db);

$action         = trim((string) ($_POST['action'] ?? ''));
$merchantUserId = gjc_user_id();
$ownerMerchId   = gjc_merchant_owner_id($db, $merchantUserId);

try {
    if ($action === 'create_qr_order') {
        if (!gjc_csrf_verify()) {
            echo json_encode(['success' => false, 'message' => 'Security check failed. Refresh the page and try again.']);
            exit;
        }

        $totalAmt = round((float) ($_POST['total'] ?? 0), 2);
        $itemsJson = (string) ($_POST['items'] ?? '[]');
        $items = json_decode($itemsJson, true);
        $requestKey = (string) ($_POST['request_key'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $requestKey)) {
            throw new RuntimeException('Refresh the POS page and generate the QR again.');
        }

        if ($totalAmt <= 0 || !is_array($items) || empty($items)) {
            echo json_encode(['success' => false, 'message' => 'Invalid QR order details.']);
            exit;
        }

        $merchantWallet = gjc_merchant_wallet($db, $ownerMerchId);
        if ($merchantWallet['id'] === 0) {
            echo json_encode(['success' => false, 'message' => 'Merchant wallet not found.']);
            exit;
        }

        $validatedItems = [];
        $serverTotal = 0.0;
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? 0);
            $qty = (int) ($item['qty'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid item in QR order.']);
                exit;
            }

            $stmt = $db->prepare(
                "SELECT id, product_name, price, stock_qty
                   FROM merchant_inventory
                  WHERE id = ?
                    AND merchant_user_id = ?
                    AND is_available = 1
                    AND is_restricted = 0
                  LIMIT 1"
            );
            $stmt->execute([$itemId, $ownerMerchId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                echo json_encode(['success' => false, 'message' => "Item #{$itemId} is unavailable."]);
                exit;
            }
            if ((int) $product['stock_qty'] < $qty) {
                echo json_encode(['success' => false, 'message' => "Insufficient stock for {$product['product_name']}."]);
                exit;
            }

            $price = (float) $product['price'];
            $serverTotal += $price * $qty;
            $validatedItems[] = [
                'id' => (int) $product['id'],
                'name' => (string) $product['product_name'],
                'qty' => $qty,
                'price' => round($price, 2),
            ];
        }

        $serverTotal = round($serverTotal, 2);
        if (abs($serverTotal - $totalAmt) > 0.01) {
            echo json_encode(['success' => false, 'message' => 'Cart total changed. Please regenerate the QR.']);
            exit;
        }

        $description = implode(', ', array_map(
            fn($item) => "{$item['qty']}x {$item['name']}",
            $validatedItems
        ));
        $token = bin2hex(random_bytes(16));
        $shortCode = gjc_generate_order_short_code($db);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        $legacyItemsJson = gjc_order_has_legacy_items_json($db, 'qr');

        // PHP holds the session lock for this request, so a concurrent retry
        // cannot insert another ticket for the same register draft.
        $requests = $_SESSION['pos_qr_requests'] ?? [];
        $existingId = (int) ($requests[$requestKey] ?? 0);
        $existing = null;
        if ($existingId) {
            $stmt = $db->prepare('SELECT id, token, short_code, status, expires_at
                FROM merchant_qr_orders WHERE id = ? AND merchant_wallet_id = ?');
            $stmt->execute([$existingId, (int) $merchantWallet['id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing && $existing['status'] === 'paid') {
                throw new RuntimeException('This order is already paid. Clear the cart to start the next order.');
            }
            if ($existing && $existing['status'] === 'pending' && strtotime($existing['expires_at']) > time()) {
                if (gjc_order_item_lines($db, 'qr', $existingId) !== $validatedItems) {
                    throw new RuntimeException('This cart changed. Clear the cart and generate a new QR.');
                }
                $orderId = $existingId;
                $token = $existing['token'];
                $shortCode = $existing['short_code'];
                $expiresAt = $existing['expires_at'];
            } else {
                $existing = null;
            }
        }

        if (!$existing) {
            $db->beginTransaction();
            try {
                $legacyUserIds = gjc_has_legacy_wallet_user_ids($db, 'merchant_qr_orders');
                $db->prepare(
                    "INSERT INTO merchant_qr_orders
                        (token, short_code" . ($legacyUserIds ? ", merchant_user_id" : "") . ", merchant_wallet_id, created_by, description"
                        . ($legacyItemsJson ? ", items_json" : "") . ", amount, expires_at)
                     VALUES (?, ?, " . ($legacyUserIds ? "?, " : "") . "?, ?, ?, " . ($legacyItemsJson ? "?, " : "") . "?, ?)"
                )->execute([
                    $token,
                    $shortCode,
                    ...($legacyUserIds ? [$ownerMerchId] : []),
                    (int) $merchantWallet['id'],
                    $merchantUserId,
                    $description,
                    ...($legacyItemsJson
                        ? [json_encode($validatedItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
                        : []),
                    $serverTotal,
                    $expiresAt,
                ]);
                $orderId = (int) $db->lastInsertId();
                gjc_store_order_items($db, 'qr', $orderId, $validatedItems);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            $requests[$requestKey] = $orderId;
            $_SESSION['pos_qr_requests'] = array_slice($requests, -100, null, true);
        }

        // Token-only payload: the student side resolves merchant/item/amount
        // server-side (student/api/validate_qr.php), so the QR itself leaks
        // nothing and amounts can never be tampered with client-side.
        $qrPayload = [
            'type' => 'payment',
            'token' => $token,
        ];

        echo json_encode([
            'success' => true,
            'order_id' => $orderId,
            'qr_payload' => json_encode($qrPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'short_code' => substr($shortCode, 0, 4) . '-' . substr($shortCode, 4),
            'summary' => 'Total: ' . gjc_gc_amount($serverTotal) . ' GC (PHP ' . number_format($serverTotal, 2) . ') - ' . count($validatedItems) . ' item type(s)',
            'expires_at' => $expiresAt,
        ]);
        exit;
    }

    // ── PAYMENT STATUS (polled by the POS QR panel every few seconds) ───────────
    if ($action === 'check_order_status') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid order id.']);
            exit;
        }

        $stmt = $db->prepare(
            "SELECT id, status, paid_ref, paid_at, expires_at
               FROM merchant_qr_orders
              WHERE id = ?
                AND merchant_wallet_id IN (SELECT id FROM merchant_wallets WHERE user_id = ?)
              LIMIT 1"
        );
        $stmt->execute([$orderId, $ownerMerchId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found.']);
            exit;
        }

        if ($order['status'] === 'pending' && strtotime((string) $order['expires_at']) < time()) {
            $db->prepare("UPDATE merchant_qr_orders SET status = 'expired' WHERE id = ? AND status = 'pending'")
                ->execute([$orderId]);
            $order['status'] = 'expired';
        }

        echo json_encode([
            'success' => true,
            'status' => (string) $order['status'],
            'paid_ref' => $order['paid_ref'],
            'paid_at' => $order['paid_at'],
        ]);
        exit;
    }

    // ── LIVE ORDER QUEUE ─────────────────────────────────────────────────────────
    if ($action === 'list_queue') {
        $db->prepare(
            "UPDATE merchant_qr_orders SET status = 'expired'
              WHERE merchant_wallet_id IN (SELECT id FROM merchant_wallets WHERE user_id = ?)
                AND status = 'pending' AND expires_at < NOW()"
        )->execute([$ownerMerchId]);

        $posStmt = $db->prepare(
            "(SELECT id, description, amount, status, created_at
               FROM merchant_qr_orders
              WHERE merchant_wallet_id IN (SELECT id FROM merchant_wallets WHERE user_id = ?)
                AND status = 'pending')
             UNION ALL
             (SELECT id, description, amount, status, created_at
                FROM merchant_qr_orders
               WHERE merchant_wallet_id IN (SELECT id FROM merchant_wallets WHERE user_id = ?)
                 AND status = 'paid' AND created_at >= CURDATE()
               ORDER BY created_at DESC, id DESC
               LIMIT 15)"
        );
        $posStmt->execute([$ownerMerchId, $ownerMerchId]);
        $orders = array_map(function ($row) {
            return [
                'uid' => 'pos-' . $row['id'],
                'id' => (int) $row['id'],
                'source' => 'pos',
                'description' => $row['description'],
                'amount' => (float) $row['amount'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
            ];
        }, $posStmt->fetchAll(PDO::FETCH_ASSOC));

        // Orders the student already submitted from the Shop Cart but hasn't
        // paid for yet — visible the moment they tap "Submit Order", before
        // any money moves. Excludes 'paid' rows since those are represented
        // below by their resulting transactions row instead (avoids duplicates).
        $pendingCartStmt = $db->prepare(
            "SELECT id, description, amount, status, created_at
               FROM cart_orders
              WHERE merchant_wallet_id IN (SELECT id FROM merchant_wallets WHERE user_id = ?)
                AND status = 'pending'
              ORDER BY created_at DESC, id DESC"
        );
        $pendingCartStmt->execute([$ownerMerchId]);
        foreach ($pendingCartStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orders[] = [
                'uid' => 'cartpending-' . $row['id'],
                'id' => (int) $row['id'],
                'source' => 'cart_pending',
                'description' => $row['description'],
                'amount' => (float) $row['amount'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
            ];
        }

        $merchantWallet = gjc_merchant_wallet($db, $ownerMerchId);
        if ($merchantWallet['id'] > 0) {
            $cartStmt = $db->prepare(
                "SELECT id, notes, amount, created_at
                   FROM transactions
                  WHERE merchant_wallet_id = ?
                    AND transaction_type = 'payment'
                    AND status = 'completed'
                    AND reference_no LIKE 'CART-%'
                    AND created_at >= CURDATE()
                  ORDER BY created_at DESC, id DESC
                  LIMIT 15"
            );
            $cartStmt->execute([$merchantWallet['id']]);
            foreach ($cartStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $orders[] = [
                    'uid' => 'cart-' . $row['id'],
                    'id' => (int) $row['id'],
                    'source' => 'cart',
                    'description' => $row['notes'],
                    'amount' => (float) $row['amount'],
                    'status' => 'paid',
                    'created_at' => $row['created_at'],
                ];
            }
        }

        usort($orders, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        // Recent history must never push unpaid tickets out of the live queue.
        $doneCount = 0;
        $orders = array_values(array_filter($orders, static function ($order) use (&$doneCount) {
            return $order['status'] === 'pending' || ($order['status'] === 'paid' && ++$doneCount <= 15);
        }));
        foreach ($orders as &$order) {
            $order['stage'] = match ($order['status']) {
                'pending' => 'awaiting_payment',
                'paid' => 'served',
                default => $order['status'],
            };
            $order['queue_no'] = $order['id'];
        }
        unset($order);
        $orders = array_reverse($orders);

        echo json_encode(['success' => true, 'orders' => $orders]);
        exit;
    }

    // ── LIVE SALES SUMMARY (Today's Sales / Total Earned) ───────────────────────
    if ($action === 'get_sales_summary') {
        $merchantWallet = gjc_merchant_wallet($db, $ownerMerchId);
        $totalEarned = 0.0;

        $unremitted = gjc_merchant_unremitted_sales($db, $merchantWallet['id']);
        $remittedToday = gjc_merchant_remitted_sales_today($db, $merchantWallet['id']);

        if ($merchantWallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
            $earningTypes = [CirculationEngine::TXN_PAYMENT, CirculationEngine::TXN_VOUCHER_PAYMENT];
            $placeholders = implode(', ', array_fill(0, count($earningTypes), '?'));

            $totalStmt = $db->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM transactions
                  WHERE merchant_wallet_id = ?
                    AND transaction_type IN ({$placeholders})"
            );
            $totalStmt->execute(array_merge([$merchantWallet['id']], $earningTypes));
            $totalEarned = (float) $totalStmt->fetchColumn();
        }

        $lastRemit = null;
        $lastRemitStmt = $db->prepare(
            "SELECT amount, reference_no, period_end FROM sales_remittances
              WHERE merchant_wallet_id = ? AND DATE(period_end) = CURDATE()
              ORDER BY period_end DESC LIMIT 1"
        );
        $lastRemitStmt->execute([$merchantWallet['id']]);
        if ($row = $lastRemitStmt->fetch(PDO::FETCH_ASSOC)) {
            $lastRemit = [
                'amount' => (float) $row['amount'],
                'reference_no' => $row['reference_no'],
                'time' => date('h:i A', strtotime($row['period_end'])),
            ];
        }

        echo json_encode([
            'success' => true,
            'todays_sales' => $remittedToday['amount'],
            'pending_amount' => $unremitted['amount'],
            'total_earned' => $totalEarned,
            'last_remit' => $lastRemit,
        ]);
        exit;
    }

    // ── TODAY'S SALES (FULL LIST, ALREADY REMITTED TODAY) ───────────────────────
    if ($action === 'get_today_sales') {
        $merchantWallet = gjc_merchant_wallet($db, $ownerMerchId);
        $sales = [];

        $confirmed = gjc_merchant_confirmed_sale_sql($db);

        if ($merchantWallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
            $earningTypes = [CirculationEngine::TXN_PAYMENT, CirculationEngine::TXN_VOUCHER_PAYMENT];
            $placeholders = implode(', ', array_fill(0, count($earningTypes), '?'));

            $stmt = $db->prepare(
                "SELECT reference_no, transaction_type, amount, notes, created_at
                   FROM transactions t
                  WHERE merchant_wallet_id = ?
                    AND transaction_type IN ({$placeholders})
                    AND status = 'completed' AND DATE(created_at) = CURDATE()
                    AND {$confirmed}
                  ORDER BY created_at DESC"
            );
            $stmt->execute(array_merge(
                [$merchantWallet['id']],
                $earningTypes
            ));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                // 'notes' holds the item summary ("2x Rice Meal, 1x Iced Tea") for both
                // POS sales ("POS Sale: " prefixed) and cart/QR checkouts (unprefixed).
                $itemsBought = preg_replace('/^POS Sale:\s*/i', '', trim((string) $row['notes']));

                $sales[] = [
                    'reference_no' => $row['reference_no'],
                    'description'  => ucwords(str_replace('_', ' ', $row['transaction_type'])),
                    'items'        => $itemsBought !== '' ? $itemsBought : '—',
                    'amount'       => (float) $row['amount'],
                    'time'         => date('M d, h:i A', strtotime($row['created_at'])),
                ];
            }
        }

        echo json_encode(['success' => true, 'sales' => $sales]);
        exit;
    }

    // ── REMIT SALES (self-declared, no approval) ─────────────────────────────────
    if ($action === 'remit_sales') {
        if (!gjc_is_merchant_staff()) {
            throw new RuntimeException('Merchant Admin sales are credited automatically; only staff remit sales.');
        }
        if (!gjc_csrf_verify()) {
            throw new RuntimeException('Security check failed. Refresh the page and try again.');
        }
        $merchantWallet = gjc_merchant_wallet($db, $ownerMerchId);
        if ($merchantWallet['id'] === 0) {
            echo json_encode(['success' => false, 'message' => 'Merchant wallet not found.']);
            exit;
        }

        $unremitted = gjc_merchant_unremitted_sales($db, $merchantWallet['id']);
        if ($unremitted['amount'] <= 0) {
            echo json_encode(['success' => false, 'message' => 'No new sales to remit yet.']);
            exit;
        }

        $periodEnd = date('Y-m-d H:i:s');
        $reference = gjc_reference('RMT');

        $db->prepare(
            "INSERT INTO sales_remittances
                (merchant_wallet_id, merchant_user_id, staff_user_id, reference_no, amount, txn_count, period_start, period_end)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $merchantWallet['id'],
            $ownerMerchId,
            $merchantUserId,
            $reference,
            $unremitted['amount'],
            $unremitted['count'],
            $unremitted['since'],
            $periodEnd,
        ]);

        logAudit(
            $db,
            $merchantUserId,
            gjc_current_role(),
            'TRANSACTION',
            'sales_remittances',
            null,
            [
                'reference_no' => $reference,
                'amount' => $unremitted['amount'],
                'txn_count' => $unremitted['count'],
                'merchant_wallet_id' => $merchantWallet['id'],
                'period_start' => $unremitted['since'],
                'period_end' => $periodEnd,
            ],
            null,
            ['target_id' => $reference]
        );

        echo json_encode([
            'success' => true,
            'reference_no' => $reference,
            'amount' => $unremitted['amount'],
            'txn_count' => $unremitted['count'],
            'time' => date('h:i A', strtotime($periodEnd)),
            'report_url' => MERCHANT_URL . '/remittance_report.php?ref=' . urlencode($reference),
        ]);
        exit;
    }

    // ── VIEW FULL ORDER DETAILS ──────────────────────────────────────────────────
    if ($action === 'view_order') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $source = trim((string) ($_POST['source'] ?? ''));
        if (!$orderId || !$source) {
            echo json_encode(['success' => false, 'message' => 'Invalid order.']);
            exit;
        }

        if ($source === 'pos') {
            $stmt = $db->prepare(
                "SELECT mqo.*, u.first_name, u.last_name
                   FROM merchant_qr_orders mqo
                   LEFT JOIN users u ON u.userID = mqo.paid_by
                  WHERE mqo.id = ?
                    AND mqo.merchant_wallet_id IN (SELECT id FROM merchant_wallets WHERE user_id = ?)"
            );
            $stmt->execute([$orderId, $ownerMerchId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'Order not found.']);
                exit;
            }

            $items = gjc_order_item_lines($db, 'qr', (int) $row['id']);
            $studentName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

            if ($row['status'] === 'pending' && strtotime((string) $row['expires_at']) <= time()) {
                $db->prepare("UPDATE merchant_qr_orders SET status = 'expired' WHERE id = ? AND status = 'pending'")
                    ->execute([$orderId]);
                $row['status'] = 'expired';
            }

            echo json_encode([
                'success' => true,
                'reference' => $row['token'],
                'description' => $row['description'],
                'items' => is_array($items) ? $items : [],
                'amount' => (float) $row['amount'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'paid_at' => $row['paid_at'],
                'qr_payload' => $row['status'] === 'pending'
                    ? json_encode(['type' => 'payment', 'token' => $row['token']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'short_code' => $row['status'] === 'pending'
                    ? substr((string) $row['short_code'], 0, 4) . '-' . substr((string) $row['short_code'], 4)
                    : null,
                'expires_at' => $row['expires_at'],
                'student_name' => $studentName !== '' ? $studentName : null,
            ]);
            exit;
        }

        if ($source === 'cart_pending') {
            $stmt = $db->prepare(
                // Resolve either buyer through the wallet owner, including parent orders.
                "SELECT co.*, u.first_name, u.last_name
                   FROM cart_orders co
                   LEFT JOIN student_wallets sw ON sw.id = co.student_wallet_id
                   LEFT JOIN parent_wallets pw ON pw.id = co.parent_wallet_id
                   LEFT JOIN parents p ON p.id = pw.parent_id
                   LEFT JOIN users u ON u.userID = COALESCE(sw.user_id, p.user_id)
                  WHERE co.id = ?
                    AND co.merchant_wallet_id IN (SELECT id FROM merchant_wallets WHERE user_id = ?)"
            );
            $stmt->execute([$orderId, $ownerMerchId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'Order not found.']);
                exit;
            }

            $items = gjc_order_item_lines($db, 'cart', (int) $row['id']);
            $studentName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

            // Bind the queue QR to this pre-order so a stale image cannot
            // pay a later pending order at the same shop.
            $walletQrUrl = null;
            if ($row['status'] === 'pending') {
                $merchantWallet = gjc_merchant_wallet($db, $ownerMerchId);
                $stallStmt = $db->prepare("SELECT stall_name FROM merchant WHERE userID = ? LIMIT 1");
                $stallStmt->execute([$ownerMerchId]);
                $stallName = (string) ($stallStmt->fetchColumn() ?: '');
                if ($stallName === '') {
                    $stallName = (string) (gjc_current_user($db)['name'] ?? '');
                }
                $walletQrPayload = json_encode([
                    'type' => 'merchant_wallet',
                    'order_reference' => $row['reference_no'],
                    'merchant_wallet_id' => $merchantWallet['id'],
                    'merchant_user_id' => $ownerMerchId,
                    'merchant' => $stallName,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $walletQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&ecc=H&margin=12&data='
                    . rawurlencode($walletQrPayload);
            }

            echo json_encode([
                'success' => true,
                'reference' => $row['reference_no'],
                'description' => $row['description'],
                'items' => is_array($items) ? $items : [],
                'amount' => (float) $row['amount'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'paid_at' => $row['paid_at'],
                'wallet_qr_url' => $walletQrUrl,
                'student_name' => $studentName !== ''
                    ? $studentName
                    : (($row['buyer_role'] ?? 'student') === 'parent' ? 'Parent' : 'Student'),
            ]);
            exit;
        }

        if ($source === 'cart') {
            $merchantWallet = gjc_merchant_wallet($db, $ownerMerchId);
            $stmt = $db->prepare(
                "SELECT t.*, u.first_name, u.last_name
                   FROM transactions t
                   LEFT JOIN student_wallets sw ON sw.id = t.student_wallet_id
                   LEFT JOIN users u ON u.userID = sw.user_id
                  WHERE t.id = ? AND t.merchant_wallet_id = ?"
            );
            $stmt->execute([$orderId, $merchantWallet['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'Order not found.']);
                exit;
            }

            $studentName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $items = gjc_transaction_line_items($db, (string) $row['reference_no']);

            echo json_encode([
                'success' => true,
                'reference' => $row['reference_no'],
                'description' => $row['notes'],
                'items' => is_array($items) ? $items : [],
                'amount' => (float) $row['amount'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'paid_at' => $row['created_at'],
                'student_name' => $studentName !== '' ? $studentName : 'Student',
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown order source.']);
        exit;
    }

    // ── VOID / CANCEL A PENDING ORDER ───────────────────────────────────────────
    if ($action === 'void_order') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $source = trim((string) ($_POST['source'] ?? 'pos'));
        if (!$orderId) {
            echo json_encode(['success' => false, 'message' => 'Invalid order.']);
            exit;
        }

        $table = $source === 'cart_pending' ? 'cart_orders' : 'merchant_qr_orders';

        // Named columns, not SELECT *. merchant_qr_orders.token is a live payment
        // authorisation, and the whole row used to be handed to logAudit() — which
        // wrote the token into the audit log in cleartext for any finance account
        // to read. Both tables carry these seven; only the human-facing reference
        // differs between them.
        $auditColumns = $table === 'cart_orders'
            ? 'id, reference_no AS reference, (SELECT mw.user_id FROM merchant_wallets mw WHERE mw.id = cart_orders.merchant_wallet_id) AS merchant_user_id, merchant_wallet_id, amount, status, description, created_at'
            : 'id, short_code AS reference, (SELECT mw.user_id FROM merchant_wallets mw WHERE mw.id = merchant_qr_orders.merchant_wallet_id) AS merchant_user_id, merchant_wallet_id, amount, status, description, created_at';

        $own = $db->prepare(
            "SELECT {$auditColumns} FROM {$table} WHERE id = ?
              AND merchant_wallet_id IN (SELECT id FROM merchant_wallets WHERE user_id = ?)
              AND status = 'pending'"
        );
        $own->execute([$orderId, $ownerMerchId]);
        $order = $own->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found or already settled.']);
            exit;
        }

        $db->prepare("UPDATE {$table} SET status = 'voided' WHERE id = ?")->execute([$orderId]);
        logAudit(
            $db,
            $merchantUserId,
            gjc_current_role(),
            'TRANSACTION',
            $table,
            $order,
            ['status' => 'voided'],
            null,
            ['target_id' => $orderId]
        );

        echo json_encode(['success' => true, 'message' => 'Order voided.']);
        exit;
    }

    // ── LOOKUP STUDENT ──────────────────────────────────────────────────────────
    if ($action === 'lookup_student') {
        $studentIdInput = trim((string) ($_POST['student_id'] ?? ''));
        if (!$studentIdInput) {
            echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
            exit;
        }

        // Support both student_info.studentID and users.userID search
        $stmt = $db->prepare(
            "SELECT u.userID, u.first_name, u.last_name, sw.id AS wallet_id, sw.balance
               FROM users u
               JOIN student_info si ON si.userID = u.userID
               JOIN student_wallets sw ON sw.user_id = u.userID
              WHERE si.studentID = ? AND u.roleID = 1
              LIMIT 1"
        );
        $stmt->execute([$studentIdInput]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$found) {
            echo json_encode(['success' => false, 'message' => 'Student not found. Check the Student ID.']);
            exit;
        }

        echo json_encode([
            'success'   => true,
            'name'      => trim($found['first_name'] . ' ' . $found['last_name']),
            'user_id'   => (int) $found['userID'],
            'wallet_id' => (int) $found['wallet_id'],
            'balance'   => (float) $found['balance'],
        ]);
        exit;
    }

    // ── PROCESS POS TRANSACTION ─────────────────────────────────────────────────
    if ($action === 'process_pos') {
        $walletId  = (int)   ($_POST['wallet_id'] ?? 0);
        $totalAmt  = round((float) ($_POST['total'] ?? 0), 2);
        $itemsJson = (string) ($_POST['items'] ?? '[]');
        $items     = json_decode($itemsJson, true);

        if (!$walletId || $totalAmt <= 0 || empty($items)) {
            echo json_encode(['success' => false, 'message' => 'Invalid transaction data.']);
            exit;
        }

        // Validate each item belongs to this merchant's inventory
        foreach ($items as &$item) {
            $item['id']  = (int)   ($item['id']  ?? 0);
            $item['qty'] = (int)   ($item['qty'] ?? 0);
            $item['price'] = (float) ($item['price'] ?? 0);

            if (!$item['id'] || $item['qty'] <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid item in order.']);
                exit;
            }

            // Verify item is still available and has enough stock
            $invCheck = $db->prepare(
                "SELECT id, product_name, stock_qty, price FROM merchant_inventory
                  WHERE id = ? AND merchant_user_id = ? AND is_available = 1 AND is_restricted = 0"
            );
            $invCheck->execute([$item['id'], $ownerMerchId]);
            $invItem = $invCheck->fetch(PDO::FETCH_ASSOC);

            if (!$invItem) {
                echo json_encode(['success' => false, 'message' => "Item #{$item['id']} is unavailable or not in your inventory."]);
                exit;
            }
            if ($invItem['stock_qty'] < $item['qty']) {
                echo json_encode(['success' => false, 'message' => "Insufficient stock for '{$invItem['product_name']}'. Available: {$invItem['stock_qty']}."]);
                exit;
            }
            $item['name'] = $invItem['product_name'];
        }
        unset($item);

        // Get merchant wallet
        $merchantWallet = gjc_merchant_wallet($db, $ownerMerchId);
        if ($merchantWallet['id'] === 0) {
            echo json_encode(['success' => false, 'message' => 'Merchant wallet not found.']);
            exit;
        }

        $refNo = gjc_reference('POS');

        // ── Parent wallet controls ──────────────────────────────────────────────
        $wcStmt = $db->prepare("SELECT is_frozen, daily_spend_limit FROM student_wallets WHERE id = ?");
        $wcStmt->execute([$walletId]);
        $wc = $wcStmt->fetch(PDO::FETCH_ASSOC);
        if ($wc && (int) $wc['is_frozen'] === 1) {
            echo json_encode(['success' => false, 'message' => 'This wallet is frozen by a parent or guardian.']);
            exit;
        }
        if ($wc && (float) $wc['daily_spend_limit'] > 0) {
            $spentStmt = $db->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM transactions
                  WHERE student_wallet_id = ? AND transaction_type = 'payment'
                    AND DATE(created_at) = CURDATE() AND status = 'completed'"
            );
            $spentStmt->execute([$walletId]);
            $todaySpent = (float) $spentStmt->fetchColumn();
            if ($todaySpent + $totalAmt > (float) $wc['daily_spend_limit']) {
                echo json_encode(['success' => false, 'message' => 'Daily spending limit of ₱' . number_format($wc['daily_spend_limit'], 2) . ' has been reached.']);
                exit;
            }
        }

        // DDL must run before the transaction opens (it would implicitly commit it).
        gjc_ensure_maintenance_fees_schema($db);

        // ── BEGIN TRANSACTION ───────────────────────────────────────────────────
        $db->beginTransaction();
        try {
            // 1. Debit student wallet
            $debitStmt = $db->prepare(
                "UPDATE student_wallets SET balance = balance - ? WHERE id = ? AND balance >= ?"
            );
            $debitStmt->execute([$totalAmt, $walletId, $totalAmt]);
            if ($debitStmt->rowCount() === 0) {
                throw new \RuntimeException('Insufficient balance or concurrent modification.');
            }

            // 2. Credit merchant wallet
            $db->prepare(
                "UPDATE merchant_wallets SET balance = balance + ? WHERE id = ?"
            )->execute([$totalAmt, $merchantWallet['id']]);

            // 3. Deduct stock for each item
            foreach ($items as $item) {
                $db->prepare(
                    "UPDATE merchant_inventory SET stock_qty = stock_qty - ?
                      WHERE id = ? AND merchant_user_id = ? AND stock_qty >= ?"
                )->execute([$item['qty'], $item['id'], $ownerMerchId, $item['qty']]);
            }

            // 4. Snapshot vault
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

            // 5. Log to transactions ledger
            $itemsSummary = implode(', ', array_map(function ($i) {
                return "{$i['qty']}x {$i['name']}";
            }, $items));
            $db->prepare(
                "INSERT INTO transactions
                    (reference_no, transaction_type, initiated_by, student_wallet_id, merchant_wallet_id,
                     amount, vault_before, vault_after, total_in_circulation, status, notes, school_year_id)
                 VALUES (?, 'payment', ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)"
            )->execute([
                $refNo,
                $merchantUserId,
                $walletId,
                $merchantWallet['id'],
                $totalAmt,
                $vaultBefore,
                $vaultBefore, // vault unchanged in a payment
                $totalCirc,
                'POS Sale: ' . $itemsSummary,
                gjc_active_school_year_id($db),
            ]);

            gjc_store_transaction_items($db, (int) $db->lastInsertId(), $items);

            // 6. Maintenance fee — billed to tuition, never taken from the wallet.
            $feePayerStmt = $db->prepare("SELECT user_id FROM student_wallets WHERE id = ?");
            $feePayerStmt->execute([$walletId]);
            gjc_record_maintenance_fee($db, (int) $feePayerStmt->fetchColumn(), $refNo, 'payment', $totalAmt);

            $db->commit();
            gjc_check_parent_balance_alert($db, $walletId);
            logAudit(
                $db,
                $merchantUserId,
                gjc_current_role(),
                'TRANSACTION',
                'transactions',
                null,
                [
                    'reference_no' => $refNo,
                    'transaction_type' => 'payment',
                    'amount' => $totalAmt,
                    'student_wallet_id' => $walletId,
                    'merchant_wallet_id' => $merchantWallet['id'],
                    'items' => $items,
                    'status' => 'completed',
                ],
                null,
                ['target_id' => $refNo]
            );

            gjc_notify_wallet(
                $db,
                $walletId,
                'payment',
                'Payment Successful',
                sprintf('You paid %s at %s.', gjc_money_plain($totalAmt), gjc_user_label($db, $ownerMerchId)),
                'cart-shopping',
                STUDENT_URL . '/history.php'
            );

            $payerUserIdStmt = $db->prepare("SELECT user_id FROM student_wallets WHERE id = ?");
            $payerUserIdStmt->execute([$walletId]);
            $payerUserId = (int) $payerUserIdStmt->fetchColumn();
            gjc_notify(
                $db,
                $ownerMerchId,
                'sale',
                'Payment Received',
                sprintf('%s paid %s at your stall.', $payerUserId ? gjc_user_label($db, $payerUserId) : 'A student', gjc_money_plain($totalAmt)),
                'cart-shopping',
                MERCHANT_URL . '/history.php'
            );

            echo json_encode([
                'success'   => true,
                'reference' => $refNo,
                'message'   => 'Payment processed successfully.',
                'total'     => $totalAmt,
            ]);
        } catch (\Throwable $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'merchant/api/pos', 'Transaction failed. Please try again.')]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'merchant/api/pos', 'Server error. Please try again.')]);
}
