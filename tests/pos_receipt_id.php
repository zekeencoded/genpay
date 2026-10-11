<?php
// CLI regression: temporary tables isolate ledger and receipt writes; no wallets
// are changed and queued test audit entries are discarded before shutdown.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';

$db->exec('CREATE TEMPORARY TABLE transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference_no VARCHAR(50),
    transaction_type VARCHAR(50), initiated_by INT, student_wallet_id INT,
    merchant_wallet_id INT, voucher_id INT, amount DECIMAL(12,2),
    vault_before DECIMAL(12,2), vault_after DECIMAL(12,2), total_in_circulation DECIMAL(12,2),
    status VARCHAR(20), notes TEXT, school_year_id INT, parent_wallet_id INT
) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE transaction_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, transaction_id BIGINT UNSIGNED,
    item_name VARCHAR(150), quantity INT, unit_price DECIMAL(10,2),
    subtotal DECIMAL(10,2), line_no INT, UNIQUE KEY (transaction_id, line_no)
) ENGINE=InnoDB');
$db->exec('ALTER TABLE transactions AUTO_INCREMENT = 1000000000');
$userId = (int) $db->query('SELECT userID FROM users ORDER BY userID LIMIT 1')->fetchColumn();
if (!$userId) {
    throw new RuntimeException('An existing user is required for audit role lookup.');
}
$engine = new CirculationEngine($db);
$log = new ReflectionMethod($engine, 'logTransaction');
$lines = [['id' => 1, 'name' => 'Receipt regression item', 'qty' => 3, 'price' => 12.35]];

$db->beginTransaction();
try {
    foreach (['student', 'parent'] as $role) {
        $paymentId = null;
        $args = [
            'type' => CirculationEngine::TXN_PAYMENT,
            'initiatedBy' => $userId,
            'amount' => 37.05,
            'vaultBefore' => 0.0,
            'vaultAfter' => 0.0,
            'studentWalletId' => $role === 'student' ? 1 : null,
            'parentWalletId' => $role === 'parent' ? 1 : null,
            'transactionId' => &$paymentId,
        ];
        $reference = $log->invokeArgs($engine, $args);
        // Audit queries inside logTransaction reproduce the original ID reset.
        if ((int) $db->lastInsertId() === $paymentId) {
            throw new RuntimeException('Test did not reproduce the insert ID reset.');
        }
        $payment = $db->prepare('SELECT reference_no, amount FROM transactions WHERE id = ?');
        $payment->execute([$paymentId]);
        $row = $payment->fetch();
        if (!$row || $row['reference_no'] !== $reference || (float) $row['amount'] !== 37.05) {
            throw new RuntimeException('Captured ID does not identify the payment.');
        }
        gjc_store_transaction_items($db, $paymentId, $lines);
        gjc_store_transaction_items($db, $paymentId, $lines); // Idempotent receipt.
        $receipt = $db->prepare('SELECT COUNT(*), SUM(subtotal) FROM transaction_items WHERE transaction_id = ?');
        $receipt->execute([$paymentId]);
        $stored = $receipt->fetch(PDO::FETCH_NUM);
        if ((int) $stored[0] !== 1 || (float) $stored[1] !== 37.05) {
            throw new RuntimeException('Receipt items were not attached to the payment.');
        }
        $wrongLines = $lines;
        $wrongLines[0]['price'] = 12.36;
        try {
            gjc_store_transaction_items($db, $paymentId, $wrongLines);
            throw new LogicException('Mismatched receipt was accepted.');
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'Receipt items do not match the recorded payment amount.') {
                throw $error;
            }
        }
        echo "PASS: {$role} payment keeps its receipt ID after subsequent queries; amount guard remains active.\n";
    }
} finally {
    $db->rollBack();
    $queues = &gjc_audit_queue();
    unset($queues[spl_object_id($db)]);
}
