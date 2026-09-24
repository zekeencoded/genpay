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
$role = gjc_current_role();
$adminEconomyRoles = ['finance']; // gjc_current_role() only ever returns finance for staff

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
            if (!in_array($role, $adminEconomyRoles, true)) throw new RuntimeException('ACCESS_DENIED');

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
                STUDENT_URL . '/history.php'
            );
            echo json_encode(array_merge(['success' => true], $result));
            break;

        
        case 'pay':
            $studentWallet  = (int)($body['student_wallet_id']  ?? 0);
            $merchantWallet = (int)($body['merchant_wallet_id'] ?? 0);
            $amount         = (float)($body['amount'] ?? 0);

            $result = $engine->studentPay($studentWallet, $merchantWallet, $amount, $userId);
            echo json_encode(array_merge(['success' => true], $result));
            break;

        
        case 'settle':
            if (!in_array($role, $adminEconomyRoles, true)) throw new RuntimeException('ACCESS_DENIED');

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

        case 'lookup_student':
            $schoolId = trim($body['school_id'] ?? '');
            if (!$schoolId) throw new InvalidArgumentException('Student ID is required.');

            $stmt = $db->prepare(
                "SELECT u.userID, u.first_name, u.last_name, sw.id AS wallet_id
                   FROM users u
                   JOIN student_info si ON si.userID = u.userID
                   LEFT JOIN student_wallets sw ON sw.user_id = u.userID
                  WHERE si.studentID = ? AND u.roleID = 1
                  LIMIT 1"
            );
            $stmt->execute([$schoolId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) throw new RuntimeException('Student not found. Check the ID and try again.');
            if (!$student['wallet_id']) throw new RuntimeException('This student does not have a wallet yet.');

            echo json_encode([
                'success'   => true,
                'name'      => trim($student['first_name'] . ' ' . $student['last_name']),
                'user_id'   => (int) $student['userID'],
                'wallet_id' => (int) $student['wallet_id'],
            ]);
            break;

        // Parents have no school ID of their own — resolved through the
        // linked student's school ID instead, same model as
        // merchant/api/topup.php's lookup_parent_by_student action.
        case 'lookup_parent_by_student':
            if (!in_array($role, $adminEconomyRoles, true)) throw new RuntimeException('ACCESS_DENIED');

            $schoolId = trim($body['school_id'] ?? '');
            if (!$schoolId) throw new InvalidArgumentException('Student ID is required.');

            $stmt = $db->prepare(
                "SELECT p.id AS parent_id, u.first_name, u.last_name
                   FROM student_info si
                   JOIN parent_student_links psl ON psl.student_user_id = si.userID
                   JOIN parents p ON p.id = psl.parent_id
                   JOIN users u ON u.userID = p.user_id
                  WHERE si.studentID = ?"
            );
            $stmt->execute([$schoolId]);
            $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$parents) throw new RuntimeException('No parent is linked to that student. Check the ID and try again.');

            echo json_encode([
                'success' => true,
                'parents' => array_map(static fn(array $p): array => [
                    'parent_id' => (int) $p['parent_id'],
                    'name' => trim($p['first_name'] . ' ' . $p['last_name']),
                ], $parents),
            ]);
            break;

        case 'topup_parent':
            if (!in_array($role, $adminEconomyRoles, true)) throw new RuntimeException('ACCESS_DENIED');

            $parentId = (int)($body['parent_id'] ?? 0);
            $amount   = (float)($body['amount'] ?? 0);
            $notes    = trim((string)($body['notes'] ?? ''));

            if ($parentId <= 0) throw new InvalidArgumentException('Invalid parent.');

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
                    PARENT_URL . '/wallet.php'
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
