<?php
// ============================================================
//  student/api/fee_waiver_claim.php
//  Self-service claim: a student turns their own posted Fee Waiver
//  Credit into real GenCoin, minted straight into their wallet via
//  CirculationEngine::feeWaiverClaim() (see connection/app.php's
//  gjc_claim_fee_waiver_credit(), shared with the parent-side endpoint).
//  Final and one-way — there is no un-claim action.
// ============================================================
declare(strict_types=1);
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/CirculationEngine.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

gjc_require_role(['student']);

$studentUserId = gjc_user_id();

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($contentType, 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : array_merge($_GET, $_POST);

$action = strtolower(trim((string) ($body['action'] ?? '')));

if (!gjc_csrf_verify($body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security check failed. Please refresh the page and try again.']);
    exit;
}

try {
    switch ($action) {

        case 'claim': {
            $result = gjc_claim_fee_waiver_credit($db, $studentUserId, $studentUserId);

            echo json_encode([
                'success' => true,
                'message' => sprintf('%s added to your wallet.', gjc_money($result['amount'])),
                'reference' => $result['reference'],
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action: '{$action}'"]);
    }

} catch (\RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => gjc_safe_error($e, 'student/api/fee_waiver_claim')]);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[student/api/fee_waiver_claim.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
