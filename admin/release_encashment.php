<?php
session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';
require_once __DIR__ . '/../connection/audit_logger.php';

header('Content-Type: application/json');

$sessionUserId = gjc_user_id();
$sessionRole = gjc_current_role();
$allowedRoles = ['finance']; // gjc_current_role() only ever returns finance for staff
if (!$sessionUserId || !in_array($sessionRole, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$encashmentId = filter_input(INPUT_POST, 'encashment_id', FILTER_VALIDATE_INT);
$merchantWalletId = filter_input(INPUT_POST, 'merchant_wallet_id', FILTER_VALIDATE_INT);
$amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);

if (!$encashmentId || !$merchantWalletId || !$amount || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input parameters.']);
    exit;
}

try {
    $requestStmt = $db->prepare(
        "SELECT id, merchant_wallet_id, amount, status
           FROM encashment_requests
          WHERE id = ?
          LIMIT 1"
    );
    $requestStmt->execute([$encashmentId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Encashment request not found.']);
        exit;
    }

    if (($request['status'] ?? '') !== 'pending') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This encashment request has already been processed.']);
        exit;
    }

    $requestWalletId = (int) ($request['merchant_wallet_id'] ?? 0);
    $requestAmount = (float) ($request['amount'] ?? 0);
    if ($requestWalletId !== $merchantWalletId || abs($requestAmount - (float) $amount) > 0.01) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Encashment request data no longer matches the current record. Refresh and try again.']);
        exit;
    }

    $engine = new CirculationEngine($db);
    $result = $engine->merchantSettle($requestWalletId, $requestAmount, $sessionUserId);

    $update = $db->prepare(
        "UPDATE encashment_requests
            SET status = 'released',
                released_by = ?,
                released_at = NOW()
          WHERE id = ?
            AND status = 'pending'"
    );
    $update->execute([$sessionUserId, $encashmentId]);

    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Encashment release state changed before completion. Please refresh and verify the record.');
    }

    logAudit(
        $db,
        $sessionUserId,
        $sessionRole,
        'TRANSACTION',
        'encashment_requests',
        ['id' => $encashmentId, 'status' => 'pending'],
        [
            'id' => $encashmentId,
            'status' => 'released',
            'released_by' => $sessionUserId,
            'merchant_wallet_id' => $requestWalletId,
            'amount' => $requestAmount,
            'reference_no' => $result['reference'],
        ],
        null,
        ['target_id' => $encashmentId]
    );

    $merchOwnerStmt = $db->prepare("SELECT user_id FROM merchant_wallets WHERE id = ?");
    $merchOwnerStmt->execute([$requestWalletId]);
    $merchOwnerUserId = (int) $merchOwnerStmt->fetchColumn();
    if ($merchOwnerUserId > 0) {
        gjc_notify(
            $db,
            $merchOwnerUserId,
            'encashment',
            'Encashment Approved',
            gjc_money_plain($requestAmount) . ' has been released to you by the cashier.',
            'money-bill-wave',
            MERCHANT_URL . '/encash.php'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => gjc_money_plain($requestAmount) . ' settled. Points returned to vault.',
        'reference' => $result['reference'],
        'vault_after' => $result['vault_after'],
    ]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'admin/release_encashment')]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
