<?php
// Shared authenticated POS QR checkout; included by the role-specific endpoints.
session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';
header('Content-Type: application/json');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !gjc_user_id() || gjc_current_role() !== $payerRole) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Your session expired. Please log in again.']);
        exit;
    }
    if (!gjc_csrf_verify()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security check failed. Refresh and try again.']);
        exit;
    }
    gjc_require_role([$payerRole]);
    if ($payerRole === 'student') {
        gjc_enforce_student_lock($db);
    }
    if (($_POST['action'] ?? '') !== 'pay_qr_order') {
        throw new RuntimeException('Unknown POS payment action.');
    }
    $token = trim((string) ($_POST['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
        throw new RuntimeException('Invalid POS payment QR.');
    }
    // All schema helpers run before the engine opens its transaction.
    gjc_ensure_merchant_qr_orders_schema($db);
    gjc_ensure_order_items_schema($db);
    gjc_ensure_parent_schema($db);
    gjc_ensure_maintenance_fees_schema($db);
    $result = (new CirculationEngine($db))->payPosQr($payerRole, gjc_user_id(), $token);
    if ($result['student_wallet_id'] !== null) {
        try { gjc_check_parent_balance_alert($db, $result['student_wallet_id']); }
        catch (Throwable $error) { error_log('[POS QR alert] ' . $error->getMessage()); }
    }
    echo json_encode(['success' => true, 'reference' => $result['reference'], 'amount' => $result['amount']]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => gjc_safe_error($error, 'POS QR checkout', 'Unable to complete POS payment. Please try again.')]);
}
