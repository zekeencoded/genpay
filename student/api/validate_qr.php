<?php
// ============================================================
//  student/api/validate_qr.php
//  Resolves a scanned QR token or a typed short code into the
//  payment details the student must confirm (merchant, item,
//  amount) — only while the order is still pending and unexpired.
//  The QR payload itself is never trusted for display; this
//  lookup is the single source of truth before Pay Now.
// ============================================================
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';

header('Content-Type: application/json');

try {
    if (!gjc_user_id() || gjc_current_role() !== 'student') {
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'unauthorized', 'message' => 'Your session expired. Please log in again.']);
        exit;
    }

    if (!gjc_csrf_verify()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'code' => 'csrf', 'message' => 'Security check failed. Please refresh the page and try again.']);
        exit;
    }

    gjc_ensure_merchant_qr_orders_schema($db);

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $input = trim((string) ($payload['code'] ?? $payload['token'] ?? ''));

    // A QR token is 32 hex chars; anything else is treated as a typed short
    // code (dashes/spaces stripped, case-folded to the POS display alphabet).
    $token = '';
    $shortCode = '';
    if (preg_match('/^[0-9a-f]{32,64}$/i', $input)) {
        $token = strtolower($input);
    } else {
        $shortCode = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $input));
    }

    if ($token === '' && ($shortCode === '' || strlen($shortCode) < 6 || strlen($shortCode) > 12)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'code' => 'invalid_code', 'message' => 'That code does not look right. Check it and try again.']);
        exit;
    }

    $stmt = $db->prepare(
        "SELECT o.id, o.token,
                (SELECT mw.user_id FROM merchant_wallets mw WHERE mw.id = o.merchant_wallet_id) AS merchant_user_id,
                o.description, o.amount, o.status, o.expires_at
           FROM merchant_qr_orders o
          WHERE o.token = ?
             OR (o.short_code IS NOT NULL AND o.short_code = ?)
          LIMIT 1"
    );
    $stmt->execute([$token, $shortCode]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'code' => 'invalid_code', 'message' => 'Invalid or unknown payment code. Ask the merchant to generate a new QR.']);
        exit;
    }

    if ($order['status'] === 'paid') {
        http_response_code(422);
        echo json_encode(['success' => false, 'code' => 'already_paid', 'message' => 'This payment QR has already been paid.']);
        exit;
    }

    if ($order['status'] !== 'pending') {
        http_response_code(422);
        echo json_encode(['success' => false, 'code' => 'expired', 'message' => 'This payment QR is no longer valid. Ask the merchant to generate a new one.']);
        exit;
    }

    if (strtotime((string) $order['expires_at']) < time()) {
        $db->prepare("UPDATE merchant_qr_orders SET status = 'expired' WHERE id = ? AND status = 'pending'")
            ->execute([(int) $order['id']]);
        http_response_code(422);
        echo json_encode(['success' => false, 'code' => 'expired', 'message' => 'This payment QR has expired. Ask the merchant to generate a new one.']);
        exit;
    }

    $lines = gjc_order_item_lines($db, 'qr', (int) $order['id']);
    if (!$lines) {
        throw new RuntimeException('This POS bill has no recorded items.');
    }

    $merchantName = gjc_merchant_display_name($db, (int) $order['merchant_user_id']) ?: 'Merchant';

    echo json_encode([
        'success' => true,
        'token' => (string) $order['token'],
        'merchant' => $merchantName,
        'description' => (string) ($order['description'] ?? ''),
        'lines' => $lines,
        'amount' => round((float) $order['amount'], 2),
        'expires_at' => (string) $order['expires_at'],
        'seconds_left' => max(0, strtotime((string) $order['expires_at']) - time()),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'server_error', 'message' => 'A server error occurred while checking the code.']);
}
