<?php
session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
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

$topupId = filter_input(INPUT_POST, 'topup_id', FILTER_VALIDATE_INT);

if (!$topupId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid top-up request.']);
    exit;
}

try {
    gjc_ensure_operational_tables($db);

    $stmt = $db->prepare(
        "UPDATE topup_requests
            SET status = 'rejected',
                rejected_by = ?,
                rejected_at = NOW()
          WHERE id = ? AND status = 'pending'"
    );
    $stmt->execute([$sessionUserId, $topupId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Top-up request is no longer pending.']);
        exit;
    }

    logAudit(
        $db,
        $sessionUserId,
        $sessionRole,
        'TRANSACTION',
        'topup_requests',
        ['id' => $topupId, 'status' => 'pending'],
        ['id' => $topupId, 'status' => 'rejected', 'rejected_by' => $sessionUserId],
        null,
        ['target_id' => $topupId]
    );

    echo json_encode(['success' => true, 'message' => 'Top-up request rejected.']);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[reject_topup] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
