<?php

// QR/cart orders, cart snapshots and order/transaction line items.
// Loaded by connection/app.php; defines functions and constants only.

function gjc_ensure_merchant_qr_orders_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS merchant_qr_orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL UNIQUE,
            short_code VARCHAR(12) NULL,
            merchant_wallet_id INT UNSIGNED NOT NULL,
            description TEXT NULL,
            amount DECIMAL(15,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            created_by INT NULL,
            paid_ref VARCHAR(40) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mqo_token (token),
            INDEX idx_mqo_status_expiry (status, expires_at),
            INDEX idx_mqo_merchant_wallet (merchant_wallet_id),
            UNIQUE INDEX uq_mqo_short_code (short_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );

    $adds = [
        "short_code" => "VARCHAR(12) NULL AFTER token",
        "description" => "TEXT NULL",
        "amount" => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
        "status" => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
        "expires_at" => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        "created_by" => "INT NULL",
        "paid_ref" => "VARCHAR(40) NULL",
        "created_at" => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];

    $columns = gjc_table_columns($db, "merchant_qr_orders");
    foreach ($adds as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $db->exec("ALTER TABLE merchant_qr_orders ADD COLUMN {$column} {$definition}");
        }
    }
    gjc_table_columns($db, 'merchant_qr_orders', true);

    $idxStmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'merchant_qr_orders'
            AND INDEX_NAME = 'uq_mqo_short_code'"
    );
    $idxStmt->execute();
    if ((int) $idxStmt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE merchant_qr_orders ADD UNIQUE INDEX uq_mqo_short_code (short_code)");
    }
}

/**
 * Cryptographically random short code for manual (typed) payment entry.
 * 8 chars from an unambiguous alphabet (no 0/O, 1/I/L), unique across
 * merchant_qr_orders. The 32-hex QR token is unrealistic to type, so the
 * POS shows this code beside the QR as the camera-less fallback; both
 * resolve to the same single-use order row.
 */
function gjc_generate_order_short_code(PDO $db): string
{
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $max = strlen($alphabet) - 1;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM merchant_qr_orders WHERE short_code = ?");
        $stmt->execute([$code]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $code;
        }
    }

    throw new RuntimeException('Could not allocate a unique payment code. Please try again.');
}

/**
 * Re-reads every line of the student's session cart against
 * merchant_inventory right now, drops any item that became
 * unavailable/restricted/out-of-stock since it was scanned (reporting why),
 * and returns a ready-to-render/ready-to-charge snapshot. Shared by the
 * cart UI (student/api/cart.php) and checkout (student/api/checkout.php) so
 * both always agree on what the cart actually contains.
 */
function gjc_cart_snapshot(PDO $db): array
{
    $cart = $_SESSION['cart'] ?? ['merchant_user_id' => null, 'items' => []];
    $itemIds = array_keys($cart['items'] ?? []);

    if (empty($itemIds)) {
        $_SESSION['cart'] = ['merchant_user_id' => null, 'items' => []];
        return [
            'merchant_user_id' => null,
            'merchant_label' => null,
            'lines' => [],
            'total' => 0.0,
            'dropped' => [],
        ];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $db->prepare(
        "SELECT id, sku, product_name, price, stock_qty, is_available, is_restricted, restriction_note, merchant_user_id
           FROM merchant_inventory
          WHERE id IN ({$placeholders})"
    );
    $stmt->execute($itemIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rowsById = [];
    foreach ($rows as $row) {
        $rowsById[(int) $row['id']] = $row;
    }

    $lines = [];
    $dropped = [];
    $total = 0.0;
    $merchantUserId = $cart['merchant_user_id'] ?? null;

    // A suspended stall can't sell at all — whether the suspension came from
    // restricted-product strikes or from finance suspending the owner's
    // account. Empty the cart with the reason so the student isn't left
    // holding an order they can never check out.
    if ($merchantUserId && gjc_merchant_sales_blocked($db, (int) $merchantUserId)) {
        $stallLabel = gjc_merchant_display_name($db, (int) $merchantUserId) ?: 'This stall';
        foreach ($cart['items'] as $itemId => $qty) {
            $row = $rowsById[(int) $itemId] ?? null;
            $dropped[] = [
                'name' => $row ? $row['product_name'] : "Item #{$itemId}",
                'reason' => "{$stallLabel} is temporarily suspended and cannot take orders.",
            ];
        }
        $_SESSION['cart'] = ['merchant_user_id' => null, 'items' => []];
        return [
            'merchant_user_id' => null,
            'merchant_label' => null,
            'lines' => [],
            'total' => 0.0,
            'dropped' => $dropped,
        ];
    }

    foreach ($cart['items'] as $itemId => $qty) {
        $row = $rowsById[(int) $itemId] ?? null;

        if (!$row) {
            $dropped[] = ['name' => "Item #{$itemId}", 'reason' => 'This product no longer exists.'];
            unset($_SESSION['cart']['items'][$itemId]);
            continue;
        }
        if ((int) $row['is_restricted'] === 1) {
            $dropped[] = ['name' => $row['product_name'], 'reason' => $row['restriction_note'] ?: 'This item now violates campus health guidelines.'];
            unset($_SESSION['cart']['items'][$itemId]);
            continue;
        }
        if ((int) $row['is_available'] !== 1) {
            $dropped[] = ['name' => $row['product_name'], 'reason' => 'The merchant marked this item unavailable.'];
            unset($_SESSION['cart']['items'][$itemId]);
            continue;
        }

        $qty = min((int) $qty, (int) $row['stock_qty']);
        if ($qty <= 0) {
            $dropped[] = ['name' => $row['product_name'], 'reason' => 'Out of stock.'];
            unset($_SESSION['cart']['items'][$itemId]);
            continue;
        }
        $_SESSION['cart']['items'][$itemId] = $qty;

        $lineTotal = round((float) $row['price'] * $qty, 2);
        $total += $lineTotal;
        $lines[] = [
            'id' => (int) $row['id'],
            'sku' => $row['sku'],
            'name' => $row['product_name'],
            'price' => round((float) $row['price'], 2),
            'qty' => $qty,
            'stock_qty' => (int) $row['stock_qty'],
            'line_total' => $lineTotal,
        ];
    }

    if (empty($_SESSION['cart']['items'])) {
        $_SESSION['cart']['merchant_user_id'] = null;
        $merchantUserId = null;
    }

    return [
        'merchant_user_id' => $merchantUserId,
        'merchant_label' => $merchantUserId ? gjc_merchant_display_name($db, $merchantUserId) : null,
        'lines' => $lines,
        'total' => round($total, 2),
        'dropped' => $dropped,
    ];
}

/**
 * Pending Shop Cart orders — a student submits a cart (no money moves yet),
 * the merchant sees it immediately in the Live Order Queue, and the student
 * later pays by scanning the merchant's static Wallet QR at the counter.
 */
function gjc_ensure_cart_orders_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS cart_orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reference_no VARCHAR(30) NOT NULL UNIQUE,
            student_wallet_id INT UNSIGNED NULL,
            merchant_wallet_id INT UNSIGNED NOT NULL,
            parent_wallet_id INT UNSIGNED NULL,
            description TEXT NULL,
            amount DECIMAL(15,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_ref VARCHAR(40) NULL,
            INDEX idx_cart_orders_student_wallet (student_wallet_id, status),
            INDEX idx_cart_orders_parent_wallet (parent_wallet_id, status),
            INDEX idx_cart_orders_merchant_wallet (merchant_wallet_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Parent shop-cart (2026-08-27). Additive only. The buyer is a parent exactly
    // when parent_wallet_id is set (3NF: no stored buyer_role).
    $cols = gjc_table_columns($db, 'cart_orders', true);

    if (!in_array('parent_wallet_id', $cols, true)) {
        $db->exec("ALTER TABLE cart_orders ADD COLUMN parent_wallet_id INT UNSIGNED NULL");
    }

    // A parent order has no student wallet, so that column must accept NULL.
    // Copied user IDs are retained only in pre-CONTRACT schemas.
    foreach (['student_wallet_id'] as $col) {
        try {
            $def = $db->query("SHOW COLUMNS FROM cart_orders LIKE " . $db->quote($col))->fetch(PDO::FETCH_ASSOC);
            if ($def && strtoupper((string) $def['Null']) === 'NO') {
                $db->exec("ALTER TABLE cart_orders MODIFY `$col` {$def['Type']} NULL");
            }
        } catch (\Throwable $ignored) {}
    }
}

function gjc_order_has_legacy_items_json(PDO $db, string $source): bool
{
    if (!in_array($source, ['cart', 'qr'], true)) {
        throw new InvalidArgumentException('Unknown order-item source.');
    }
    $table = $source === 'cart' ? 'cart_orders' : 'merchant_qr_orders';
    return in_array('items_json', gjc_table_columns($db, $table, true), true);
}

function gjc_ensure_order_items_schema(PDO $db): void
{
    static $ready = [];
    $key = spl_object_id($db);
    if (isset($ready[$key])) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Order-item schema must be initialized before a transaction.');
    }
    foreach (['cart_orders', 'merchant_qr_orders', 'merchant_inventory', 'transactions'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Order-item parent table is missing: ' . $table);
        }
    }
    $db->exec("CREATE TABLE IF NOT EXISTS cart_order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        cart_order_id INT UNSIGNED NOT NULL,
        line_no INT UNSIGNED NOT NULL,
        original_inventory_id INT UNSIGNED NOT NULL,
        merchant_inventory_id INT UNSIGNED NULL,
        item_name VARCHAR(150) NOT NULL,
        qty INT UNSIGNED NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        sku VARCHAR(50) NULL,
        stock_qty_snapshot INT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cart_item_line (cart_order_id, line_no),
        KEY idx_cart_item_inventory (merchant_inventory_id),
        CONSTRAINT fk_cart_item_order FOREIGN KEY (cart_order_id) REFERENCES cart_orders(id)
            ON DELETE CASCADE ON UPDATE RESTRICT,
        CONSTRAINT fk_cart_item_inventory FOREIGN KEY (merchant_inventory_id) REFERENCES merchant_inventory(id)
            ON DELETE SET NULL ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS qr_order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        merchant_qr_order_id INT UNSIGNED NOT NULL,
        merchant_inventory_id INT UNSIGNED NULL,
        item_name VARCHAR(150) NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id),
        KEY idx_qoi_order (merchant_qr_order_id),
        KEY idx_qoi_inventory (merchant_inventory_id),
        CONSTRAINT fk_qoi_inventory FOREIGN KEY (merchant_inventory_id) REFERENCES merchant_inventory(id) ON DELETE SET NULL,
        CONSTRAINT fk_qoi_order FOREIGN KEY (merchant_qr_order_id) REFERENCES merchant_qr_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS transaction_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_id BIGINT UNSIGNED NOT NULL,
        item_name VARCHAR(150) NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL,
        subtotal DECIMAL(10,2) GENERATED ALWAYS AS (ROUND(quantity * unit_price, 2)) STORED NOT NULL,
        PRIMARY KEY (id), KEY idx_txn_items_transaction (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    foreach (['qr_order_items' => ['original_inventory_id' => 'INT UNSIGNED NULL', 'line_no' => 'INT UNSIGNED NULL'],
              'transaction_items' => ['line_no' => 'INT UNSIGNED NULL']] as $table => $adds) {
        $columns = gjc_table_columns($db, $table, true);
        foreach ($adds as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }
    foreach (['qr_order_items' => ['uq_qr_item_line', 'merchant_qr_order_id'],
              'transaction_items' => ['uq_transaction_item_line', 'transaction_id']] as $table => [$index, $parent]) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        if (!(int) $stmt->fetchColumn()) {
            $db->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` (`{$parent}`, line_no)");
        }
    }
    $ready[$key] = true;
}

/** Validate recorded item facts without consulting today's prices. */
function gjc_validate_order_item_lines(array $lines): array
{
    if (!$lines || !array_is_list($lines)) {
        throw new RuntimeException('An order must have a nonempty list of items.');
    }
    foreach ($lines as &$line) {
        if (!is_array($line) || !isset($line['id'], $line['qty'], $line['name'], $line['price'])
            || !is_numeric($line['id']) || (float) $line['id'] !== (float) (int) $line['id'] || (int) $line['id'] <= 0
            || !is_numeric($line['qty']) || (float) $line['qty'] !== (float) (int) $line['qty'] || (int) $line['qty'] <= 0
            || !is_string($line['name']) || trim($line['name']) === '' || mb_strlen($line['name']) > 150
            || !is_numeric($line['price']) || !is_finite((float) $line['price'])
            || (float) $line['price'] < 0 || (float) $line['price'] > 99999999.99
            || abs((float) $line['price'] - round((float) $line['price'], 2)) > 0.000001) {
            throw new RuntimeException('Invalid recorded order item.');
        }
        $line['id'] = (int) $line['id'];
        $line['qty'] = (int) $line['qty'];
        $line['price'] = round((float) $line['price'], 2);
        $subtotal = round($line['qty'] * $line['price'], 2);
        if ($subtotal > 99999999.99 || (isset($line['line_total']) && abs((float) $line['line_total'] - $subtotal) > 0.000001)
            || (isset($line['sku']) && (!is_string($line['sku']) || mb_strlen($line['sku']) > 50))) {
            throw new RuntimeException('Invalid recorded item subtotal or SKU.');
        }
    }
    unset($line);
    return $lines;
}

function gjc_order_item_total(array $lines): float
{
    $cents = 0;
    foreach ($lines as $line) {
        $cents += (int) round((float) $line['price'] * 100) * (int) $line['qty'];
    }
    return $cents / 100;
}

function gjc_order_item_lines(PDO $db, string $source, int $orderId): array
{
    if (!in_array($source, ['cart', 'qr'], true)) {
        throw new InvalidArgumentException('Unknown order-item source.');
    }
    $table = $source === 'cart' ? 'cart_order_items' : 'qr_order_items';
    $parent = $source === 'cart' ? 'cart_order_id' : 'merchant_qr_order_id';
    $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE `{$parent}` = ? ORDER BY line_no, id");
    $stmt->execute([$orderId]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['line_no'] === null || (int) $row['line_no'] !== count($lines) + 1 || $row['original_inventory_id'] === null) {
            throw new RuntimeException('Order-item backfill is incomplete.');
        }
        $line = ['id' => (int) $row['original_inventory_id'], 'name' => $row['item_name'],
                 'qty' => (int) $row['qty'], 'price' => (float) $row['unit_price']];
        if ($source === 'cart') {
            $line['sku'] = $row['sku'];
            $line['stock_qty'] = $row['stock_qty_snapshot'] === null ? null : (int) $row['stock_qty_snapshot'];
            $line['line_total'] = round($line['qty'] * $line['price'], 2);
        }
        $lines[] = $line;
    }
    if ($lines) {
        $lines = gjc_validate_order_item_lines($lines);
        $orders = $source === 'cart' ? 'cart_orders' : 'merchant_qr_orders';
        $stmt = $db->prepare("SELECT amount FROM `{$orders}` WHERE id = ?");
        $stmt->execute([$orderId]);
        $amount = $stmt->fetchColumn();
        if ($amount === false || abs(gjc_order_item_total($lines) - (float) $amount) > 0.000001) {
            throw new RuntimeException('Official items do not match the recorded order amount.');
        }
    }
    return $lines;
}

/** Caller owns the order/child transaction; JSON is never read here. */
function gjc_store_order_items(PDO $db, string $source, int $orderId, array $lines): void
{
    if (!$db->inTransaction() || !in_array($source, ['cart', 'qr'], true)) {
        throw new RuntimeException('Order items require a valid source and an open transaction.');
    }
    $lines = gjc_validate_order_item_lines($lines);
    $orders = $source === 'cart' ? 'cart_orders' : 'merchant_qr_orders';
    $stmt = $db->prepare("SELECT
        (SELECT mw.user_id FROM merchant_wallets mw WHERE mw.id = o.merchant_wallet_id) AS merchant_user_id,
        o.amount FROM `{$orders}` o WHERE o.id = ? FOR UPDATE");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || abs(gjc_order_item_total($lines) - (float) $order['amount']) > 0.000001) {
        throw new RuntimeException('Order items do not match the recorded order amount.');
    }
    if (gjc_order_item_lines($db, $source, $orderId)) {
        throw new RuntimeException('Official order items already exist; refusing to overwrite them.');
    }
    foreach ($lines as $i => $line) {
        $inventory = $db->prepare('SELECT merchant_user_id FROM merchant_inventory WHERE id = ?');
        $inventory->execute([$line['id']]);
        $owner = $inventory->fetchColumn();
        if ($owner !== false && (int) $owner !== (int) $order['merchant_user_id']) {
            throw new RuntimeException('Order item belongs to a different merchant.');
        }
        $link = $owner === false ? null : $line['id'];
        if ($source === 'cart') {
            $db->prepare('INSERT INTO cart_order_items (cart_order_id, line_no, original_inventory_id, merchant_inventory_id, item_name, qty, unit_price, sku, stock_qty_snapshot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$orderId, $i + 1, $line['id'], $link, $line['name'], $line['qty'], $line['price'], $line['sku'] ?? null, $line['stock_qty'] ?? null]);
        } else {
            $db->prepare('INSERT INTO qr_order_items (merchant_qr_order_id, line_no, original_inventory_id, merchant_inventory_id, item_name, qty, unit_price) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$orderId, $i + 1, $line['id'], $link, $line['name'], $line['qty'], $line['price']]);
        }
    }
}

function gjc_store_transaction_items(PDO $db, int $transactionId, array $lines): void
{
    if (!$db->inTransaction()) {
        throw new RuntimeException('Receipt items require an open transaction.');
    }
    $lines = gjc_validate_order_item_lines($lines);
    $stmt = $db->prepare('SELECT amount FROM transactions WHERE id = ? FOR UPDATE');
    $stmt->execute([$transactionId]);
    $amount = $stmt->fetchColumn();
    if ($amount === false || abs(gjc_order_item_total($lines) - (float) $amount) > 0.000001) {
        throw new RuntimeException('Receipt items do not match the recorded payment amount.');
    }
    $stmt = $db->prepare('SELECT item_name, quantity, unit_price, subtotal, line_no FROM transaction_items WHERE transaction_id = ? ORDER BY line_no, id');
    $stmt->execute([$transactionId]);
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($existing) {
        if (count($existing) !== count($lines)) {
            throw new RuntimeException('Conflicting existing receipt items.');
        }
        foreach ($existing as $i => $row) {
            $line = $lines[$i];
            if ((int) $row['line_no'] !== $i + 1 || $row['item_name'] !== $line['name']
                || (int) $row['quantity'] !== $line['qty'] || (float) $row['unit_price'] !== $line['price']
                || (float) $row['subtotal'] !== round($line['qty'] * $line['price'], 2)) {
                throw new RuntimeException('Conflicting existing receipt items.');
            }
        }
        return;
    }
    // subtotal is derived (quantity x unit_price). Once it is a generated column
    // MySQL computes it; until then it is still written here.
    $writeSubtotal = !gjc_transaction_items_subtotal_is_generated($db);
    $insert = $db->prepare($writeSubtotal
        ? 'INSERT INTO transaction_items (transaction_id, line_no, item_name, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)'
        : 'INSERT INTO transaction_items (transaction_id, line_no, item_name, quantity, unit_price) VALUES (?, ?, ?, ?, ?)');
    foreach ($lines as $i => $line) {
        $insert->execute(array_merge(
            [$transactionId, $i + 1, $line['name'], $line['qty'], $line['price']],
            $writeSubtotal ? [round($line['qty'] * $line['price'], 2)] : []
        ));
    }
}

/**
 * True once transaction_items.subtotal is a generated column (3NF contract).
 * SHOW COLUMNS, not information_schema, so a TEMPORARY test table is honoured.
 */
function gjc_transaction_items_subtotal_is_generated(PDO $db): bool
{
    $col = $db->query("SHOW COLUMNS FROM transaction_items LIKE 'subtotal'")->fetch(PDO::FETCH_ASSOC);
    return $col !== false && stripos((string) ($col['Extra'] ?? $col['EXTRA'] ?? ''), 'GENERATED') !== false;
}


