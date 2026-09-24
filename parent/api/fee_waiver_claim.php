<?php
// ============================================================
//  parent/api/fee_waiver_claim.php
//  Self-service claim: a parent turns a linked student's posted Fee
//  Waiver Credit into real GenCoin, minted straight into the student's
//  wallet. Shares gjc_claim_fee_waiver_credit() with the student-side
//  endpoint (student/api/fee_waiver_claim.php) so the race-safety and
//  mint logic only lives in one place. Final and one-way.
// ============================================================
declare(strict_types=1);
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/CirculationEngine.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);

$parentUserId = gjc_user_id();
$parentId = gjc_parent_id_for_user($db, $parentUserId);
if (!$parentId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Parent record not found.']);
    exit;
}

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
            $studentUserId = (int) ($body['student_user_id'] ?? 0);
            if (!$studentUserId) {
                throw new \InvalidArgumentException('Select a student first.');
            }

            // Never trust the posted student id — re-verify the link every time.
            $linkChk = $db->prepare(
                "SELECT 1 FROM parent_student_links WHERE parent_id = ? AND student_user_id = ?"
            );
            $linkChk->execute([$parentId, $studentUserId]);
            if (!$linkChk->fetch()) {
                throw new \RuntimeException('You are not linked to this student.');
            }

            $result = gjc_claim_fee_waiver_credit($db, $studentUserId, $parentUserId);

            echo json_encode([
                'success' => true,
                'message' => sprintf('%s added to the student\'s wallet.', gjc_money($result['amount'])),
                'reference' => $result['reference'],
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action: '{$action}'"]);
    }

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => gjc_safe_error($e, 'parent/api/fee_waiver_claim')]);
} catch (\RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => gjc_safe_error($e, 'parent/api/fee_waiver_claim')]);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[parent/api/fee_waiver_claim.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
