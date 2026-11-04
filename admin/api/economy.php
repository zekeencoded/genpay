<?php


declare(strict_types=1);
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/CirculationEngine.php';


if (!isset($_SESSION['userID'], $_SESSION['roleID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthenticated.']);
    exit;
}

$userId = (int)$_SESSION['userID'];
// Only admin/cash_management.php calls this; merchants and parents have their own endpoints.
if (gjc_current_role() !== 'finance') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') gjc_csrf_require_json();
gjc_ensure_parent_wallet_schema($db);


$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $body = array_merge($_GET, $_POST);
}

$action = strtolower(trim($body['action'] ?? ''));
$engine = new CirculationEngine($db);

try {
    switch ($action) {

        
        case 'topup':

            $walletId = (int)($body['student_wallet_id'] ?? 0);
            $amount   = (float)($body['amount'] ?? 0);
            $notes    = trim((string)($body['notes'] ?? ''));

            $targetUserId = gjc_wallet_owner_user_id($db, 'student', $walletId);
            $blocked = $targetUserId > 0 ? gjc_funds_in_block_reason($db, $targetUserId) : null;
            if ($blocked !== null) throw new RuntimeException($blocked);

            $result = $engine->cashInWithFee($walletId, $amount, 'finance', $userId, 0, $notes);
            gjc_notify_wallet(
                $db,
                $walletId,
                'topup',
                'Top-Up Approved',
                gjc_money_plain($result['credited_amount']) . ' has been credited to your wallet.',
                'circle-plus',
                STUDENT_URL . '/history.php',
                $result['reference']
            );
            echo json_encode(array_merge(['success' => true], $result));
            break;

        
        case 'settle':

            $merchantWallet = (int)($body['merchant_wallet_id'] ?? 0);
            $amount         = (float)($body['amount'] ?? 0);

            $result = $engine->merchantSettle($merchantWallet, $amount, $userId);
            echo json_encode(array_merge(['success' => true], $result));
            break;

        
        case 'circulation':
            $snapshot = $engine->getCirculationSnapshot();

            
            $drift = abs((float)($snapshot['circulation_drift'] ?? 1));
            $snapshot['is_balanced'] = $drift < 0.01;
            $snapshot['alert']       = $snapshot['is_balanced']
                ? null
                : sprintf(' Economy drift detected: ₱%s. Run integrity audit immediately.',
                    number_format($drift, 2));

            echo json_encode($snapshot);
            break;

        // One box for every recipient: Student/Employee ID, or a guardian's GRD2026-0001 ID.
        case 'lookup_recipient':
            echo json_encode(['success' => true] + gjc_find_topup_recipient($db, (string) ($body['school_id'] ?? '')));
            break;

        case 'topup_parent':

            $parentId = (int)($body['parent_id'] ?? 0);
            $amount   = (float)($body['amount'] ?? 0);
            $notes    = trim((string)($body['notes'] ?? ''));

            if ($parentId <= 0) throw new InvalidArgumentException('Invalid guardian.');

            $parentWallet = gjc_parent_wallet($db, $parentId);

            $targetUserId = gjc_wallet_owner_user_id($db, 'parent', $parentWallet['id']);
            $blocked = $targetUserId > 0 ? gjc_funds_in_block_reason($db, $targetUserId) : null;
            if ($blocked !== null) throw new RuntimeException($blocked);

            $result = $engine->cashInParent($parentWallet['id'], $amount, 'finance', $userId, 0, $notes);

            $parentUserIdStmt = $db->prepare("SELECT user_id FROM parents WHERE id = ?");
            $parentUserIdStmt->execute([$parentId]);
            $parentUserId = (int) $parentUserIdStmt->fetchColumn();
            if ($parentUserId > 0) {
                gjc_notify(
                    $db,
                    $parentUserId,
                    'topup',
                    'Top-Up Approved',
                    gjc_money_plain($result['credited_amount']) . ' has been credited to your wallet.',
                    'circle-plus',
                    PARENT_URL . '/activity.php?student=me',
                    $result['reference']
                );
            }

            echo json_encode(array_merge(['success' => true], $result));
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action: '{$action}'"]);
    }

} catch (RuntimeException | InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => gjc_safe_error($e, 'admin/api/economy')]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[economy_api.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
