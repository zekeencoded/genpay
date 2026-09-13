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
    // A refused attempt to credit a wallet is worth at least as much to an
    // auditor as a successful one. This endpoint used to answer 403 silently.
    logAudit(
        $db,
        $sessionUserId,
        $sessionRole,
        'ACCESS_DENIED',
        'topup_requests',
        null,
        [
            'endpoint_action' => 'approve_topup',
            'actual_role'     => $sessionRole,
            'required_roles'  => $allowedRoles,
            'topup_id'        => filter_input(INPUT_POST, 'topup_id', FILTER_VALIDATE_INT) ?: null,
        ],
        null,
        [
            'target_id' => filter_input(INPUT_POST, 'topup_id', FILTER_VALIDATE_INT) ?: null,
            'outcome'   => 'denied',
            'reason'    => 'Top-up approval attempted without the finance role.',
        ]
    );
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}


$topupId         = filter_input(INPUT_POST, 'topup_id',         FILTER_VALIDATE_INT);
$studentWalletId = filter_input(INPUT_POST, 'student_wallet_id', FILTER_VALIDATE_INT);
$amount          = filter_input(INPUT_POST, 'amount',           FILTER_VALIDATE_FLOAT);

if (!$topupId || !$studentWalletId || !$amount || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input parameters.']);
    exit;
}


try {
    // Don't credit a suspended account — the owner can't spend it and the
    // top-up would have to be reversed by hand.
    $targetUserId = gjc_wallet_owner_user_id($db, 'student', $studentWalletId);
    $blocked = $targetUserId > 0 ? gjc_funds_in_block_reason($db, $targetUserId) : null;
    if ($blocked !== null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $blocked]);
        exit;
    }

    $engine = new CirculationEngine($db);
    $result = $engine->cashInWithFee($studentWalletId, $amount, 'finance', $sessionUserId);

    $db->prepare(
        "UPDATE topup_requests
            SET status          = 'approved',
                approved_by     = ?,
                approved_at     = NOW(),
                reference_no    = ?,
                top_up_source   = 'finance',
                fee_amount      = ?,
                credited_amount = ?
          WHERE id = ?"
    )->execute([
        $sessionUserId,
        $result['reference'],
        $result['fee_amount'],
        $result['credited_amount'],
        $topupId,
    ]);

    logAudit(
        $db,
        $sessionUserId,
        $sessionRole,
        'TRANSACTION',
        'topup_requests',
        ['id' => $topupId, 'status' => 'pending'],
        [
            'id'               => $topupId,
            'status'           => 'approved',
            'approved_by'      => $sessionUserId,
            'student_wallet_id'=> $studentWalletId,
            'cash_amount'      => $amount,
            'fee_amount'       => $result['fee_amount'],
            'credited_amount'  => $result['credited_amount'],
            'reference_no'     => $result['reference'],
        ],
        null,
        ['target_id' => $topupId]
    );

    gjc_notify_wallet(
        $db,
        $studentWalletId,
        'topup',
        'Top-Up Approved',
        gjc_money_plain($result['credited_amount']) . ' has been credited to your wallet.',
        'circle-plus',
        STUDENT_URL . '/history.php'
    );

    echo json_encode([
        'success'          => true,
        'message'          => "₱" . number_format($result['credited_amount'], 2) .
                              " credited — collect ₱" . number_format($result['total_collected'], 2) .
                              " (" . CirculationEngine::ratePct(CirculationEngine::FEE_SYSTEM_RATE) .
                              " service fee: ₱" . number_format($result['fee_amount'], 2) . " on top).",
        'reference'        => $result['reference'],
        'credited_amount'  => $result['credited_amount'],
        'fee_amount'       => $result['fee_amount'],
        'total_collected'  => $result['total_collected'],
        'vault_remaining'  => $result['vault_after'],
    ]);

} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
