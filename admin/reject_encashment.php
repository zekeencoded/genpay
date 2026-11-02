<?php
session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';

header('Content-Type: application/json');

$sessionUserId = gjc_user_id();
$sessionRole = gjc_current_role();
if (!$sessionUserId || $sessionRole !== 'finance') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
gjc_csrf_require_json();

$encashmentId = filter_input(INPUT_POST, 'encashment_id', FILTER_VALIDATE_INT);
if (!$encashmentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input parameters.']);
    exit;
}

try {
    // No funds move on reject: the merchant balance is only debited on release.
    $update = $db->prepare(
        "UPDATE encashment_requests
            SET status = 'rejected',
                rejected_by = ?,
                rejected_at = NOW()
          WHERE id = ?
            AND status = 'pending'"
    );
    $update->execute([$sessionUserId, $encashmentId]);

    if ($update->rowCount() !== 1) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This encashment request has already been processed.']);
        exit;
    }

    $stmt = $db->prepare(
        "SELECT e.amount, mw.user_id
           FROM encashment_requests e
           JOIN merchant_wallets mw ON mw.id = e.merchant_wallet_id
          WHERE e.id = ?"
    );
    $stmt->execute([$encashmentId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['amount' => 0, 'user_id' => 0];

    logAudit(
        $db,
        $sessionUserId,
        $sessionRole,
        'TRANSACTION',
        'encashment_requests',
        ['id' => $encashmentId, 'status' => 'pending'],
        ['id' => $encashmentId, 'status' => 'rejected', 'rejected_by' => $sessionUserId],
        null,
        ['target_id' => $encashmentId]
    );

    if ((int) $request['user_id'] > 0) {
        gjc_notify(
            $db,
            (int) $request['user_id'],
            'encashment',
            'Encashment Rejected',
            'Your encashment request for ' . gjc_money_plain((float) $request['amount']) . ' was rejected by the cashier.',
            'ban',
            MERCHANT_URL . '/encash.php'
        );
    }

    echo json_encode(['success' => true, 'message' => 'Encashment request rejected.']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'admin/reject_encashment')]);
}
