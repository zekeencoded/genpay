<?php
session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';
require_once __DIR__ . '/../connection/audit_logger.php';

header('Content-Type: application/json');

$sessionUserId = gjc_user_id();
$sessionRole   = gjc_current_role();
$allowedRoles  = ['finance']; // gjc_current_role() only ever returns finance for staff
if (!$sessionUserId || !in_array($sessionRole, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// Must run before any transaction starts — this is DDL, and MySQL implicitly
// commits an open transaction the instant it sees a DDL statement.
gjc_ensure_transaction_types($db, ['student_withdraw']);

$withdrawalId    = filter_input(INPUT_POST, 'withdrawal_id', FILTER_VALIDATE_INT);
$studentWalletId = filter_input(INPUT_POST, 'student_wallet_id', FILTER_VALIDATE_INT);
$amount          = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);

if (!$withdrawalId || !$studentWalletId || !$amount || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input parameters.']);
    exit;
}

try {
    $requestStmt = $db->prepare(
        "SELECT id, student_wallet_id, amount, status
           FROM withdrawal_requests
          WHERE id = ?
          LIMIT 1"
    );
    $requestStmt->execute([$withdrawalId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Withdrawal request not found.']);
        exit;
    }

    if (($request['status'] ?? '') !== 'pending') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This withdrawal request has already been processed.']);
        exit;
    }

    $requestWalletId = (int) ($request['student_wallet_id'] ?? 0);
    $requestAmount   = (float) ($request['amount'] ?? 0);
    if ($requestWalletId !== $studentWalletId || abs($requestAmount - (float) $amount) > 0.01) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Withdrawal request data no longer matches the current record. Refresh and try again.']);
        exit;
    }

    $engine = new CirculationEngine($db);
    $result = $engine->studentSettle($requestWalletId, $requestAmount, $sessionUserId);

    $update = $db->prepare(
        "UPDATE withdrawal_requests
            SET status = 'released',
                released_by = ?,
                released_at = NOW()
          WHERE id = ?
            AND status = 'pending'"
    );
    $update->execute([$sessionUserId, $withdrawalId]);

    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Withdrawal release state changed before completion. Please refresh and verify the record.');
    }

    logAudit(
        $db,
        $sessionUserId,
        $sessionRole,
        'TRANSACTION',
        'withdrawal_requests',
        ['id' => $withdrawalId, 'status' => 'pending'],
        [
            'id' => $withdrawalId,
            'status' => 'released',
            'released_by' => $sessionUserId,
            'student_wallet_id' => $requestWalletId,
            'amount' => $requestAmount,
            'reference_no' => $result['reference'],
        ],
        null,
        ['target_id' => $withdrawalId]
    );

    gjc_notify_wallet(
        $db,
        $requestWalletId,
        'withdraw',
        'Withdrawal Released',
        gjc_money_plain($requestAmount) . ' cash withdrawal has been released by the cashier.',
        'money-bill-wave',
        STUDENT_URL . '/history.php'
    );

    echo json_encode([
        'success'     => true,
        'message'     => 'PHP ' . number_format($requestAmount, 2) . ' released. Points returned to vault.',
        'reference'   => $result['reference'],
        'vault_after' => $result['vault_after'],
    ]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
