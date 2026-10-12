<?php
declare(strict_types=1);
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/CirculationEngine.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

gjc_require_role(['merchant']);
gjc_ensure_operational_tables($db);
gjc_ensure_parent_wallet_schema($db);

$initiatedBy      = gjc_user_id();
$ownerMerchId     = gjc_merchant_owner_id($db, $initiatedBy);
$merchantWallet   = gjc_merchant_wallet($db, $ownerMerchId);
$merchantWalletId = (int) $merchantWallet['id'];

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($contentType, 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : array_merge($_GET, $_POST);

$action = strtolower(trim($body['action'] ?? ''));
$maxTopup = gjc_setting($db, 'topup_max_per_request');

try {
    switch ($action) {

        case 'lookup_student': {
            $schoolId = trim((string)($body['school_id'] ?? ''));
            if (!$schoolId) throw new \InvalidArgumentException('Student ID is required.');

            $stmt = $db->prepare(
                "SELECT u.userID, u.first_name, u.last_name,
                        sw.id AS wallet_id
                   FROM users u
                   JOIN student_info si ON si.userID = u.userID
                   JOIN student_wallets sw ON sw.user_id = u.userID
                  WHERE si.studentID = ? AND u.roleID = 1
                  LIMIT 1"
            );
            $stmt->execute([$schoolId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) throw new \RuntimeException('Student not found. Check the ID and try again.');

            echo json_encode([
                'success'   => true,
                'name'      => trim($student['first_name'] . ' ' . $student['last_name']),
                'user_id'   => (int) $student['userID'],
                'wallet_id' => (int) $student['wallet_id'],
            ]);
            break;
        }

        case 'load_wallet': {
            $studentWalletId = (int)($body['student_wallet_id'] ?? 0);
            $cashAmount      = (float)($body['cash_amount'] ?? 0);

            if ($studentWalletId <= 0) throw new \InvalidArgumentException('Invalid student wallet.');
            if ($cashAmount <= 0)      throw new \InvalidArgumentException('Cash amount must be greater than zero.');
            if ($cashAmount > $maxTopup) {
                throw new \InvalidArgumentException('Maximum top-up per request is ' . gjc_money_plain($maxTopup) . '.');
            }

            $targetUserId = gjc_wallet_owner_user_id($db, 'student', $studentWalletId);
            $blocked = $targetUserId > 0 ? gjc_funds_in_block_reason($db, $targetUserId) : null;
            if ($blocked !== null) throw new RuntimeException($blocked);
            $engine = new CirculationEngine($db);
            $result = $engine->merchantSendToStudent(
                $merchantWalletId,
                $studentWalletId,
                $cashAmount,
                $initiatedBy
            );

            logAudit(
                $db,
                $initiatedBy,
                gjc_current_role(),
                'TRANSACTION',
                'transactions',
                null,
                [
                    'event'            => 'merchant_wallet_load',
                    'reference'        => $result['reference'],
                    'cash_amount'      => $cashAmount,
                    'system_fee'       => $result['system_fee'],
                    'merchant_fee'     => $result['merchant_fee'],
                    'credited_amount'  => $result['credited_amount'],
                    'merchant_wallet'  => $merchantWalletId,
                    'student_wallet'   => $studentWalletId,
                ],
                null,
                ['target_id' => $result['reference']]
            );

            gjc_notify_wallet(
                $db,
                $studentWalletId,
                'topup',
                'Wallet Loaded',
                gjc_user_label($db, $ownerMerchId) . ' loaded ' . gjc_money_plain($result['credited_amount']) . ' into your wallet.',
                'circle-plus',
                STUDENT_URL . '/history.php'
            );

            if ($result['merchant_fee'] > 0) {
                gjc_notify(
                    $db,
                    $ownerMerchId,
                    'topup',
                    'Wallet Credited',
                    'Your ' . CirculationEngine::ratePct(CirculationEngine::FEE_MERCHANT_RATE) . ' cut of ' . gjc_money_plain($result['merchant_fee']) . ' was credited for loading a student wallet.',
                    'circle-plus',
                    MERCHANT_URL . '/history.php'
                );
            }

            echo json_encode(array_merge(['success' => true], $result));
            break;
        }

        case 'lookup_parent_by_student': {
            // Parents have no school ID of their own — merchants resolve them
            // through the linked student's school ID instead.
            $schoolId = trim((string) ($body['school_id'] ?? ''));
            if (!$schoolId) throw new \InvalidArgumentException('Student ID is required.');

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

            if (!$parents) throw new \RuntimeException('No parent is linked to that student. Check the ID and try again.');

            echo json_encode([
                'success' => true,
                'parents' => array_map(static fn(array $p): array => [
                    'parent_id' => (int) $p['parent_id'],
                    'name' => trim($p['first_name'] . ' ' . $p['last_name']),
                ], $parents),
            ]);
            break;
        }

        case 'load_parent_wallet': {
            $parentId   = (int) ($body['parent_id'] ?? 0);
            $cashAmount = (float) ($body['cash_amount'] ?? 0);

            if ($parentId <= 0) throw new \InvalidArgumentException('Invalid parent.');
            if ($cashAmount <= 0) throw new \InvalidArgumentException('Cash amount must be greater than zero.');
            if ($cashAmount > $maxTopup) {
                throw new \InvalidArgumentException('Maximum top-up per request is ' . gjc_money_plain($maxTopup) . '.');
            }

            $parentWallet = gjc_parent_wallet($db, $parentId);

            $engine = new CirculationEngine($db);
            $result = $engine->merchantSendToParent(
                $merchantWalletId,
                $parentWallet['id'],
                $cashAmount,
                $initiatedBy
            );

            logAudit(
                $db,
                $initiatedBy,
                gjc_current_role(),
                'TRANSACTION',
                'transactions',
                null,
                [
                    'event'            => 'merchant_parent_wallet_load',
                    'reference'        => $result['reference'],
                    'cash_amount'      => $cashAmount,
                    'system_fee'       => $result['system_fee'],
                    'merchant_fee'     => $result['merchant_fee'],
                    'credited_amount'  => $result['credited_amount'],
                    'merchant_wallet'  => $merchantWalletId,
                    'parent_wallet'    => $parentWallet['id'],
                ],
                null,
                ['target_id' => $result['reference']]
            );

            $parentUserIdStmt = $db->prepare("SELECT user_id FROM parents WHERE id = ?");
            $parentUserIdStmt->execute([$parentId]);
            $parentUserId = (int) $parentUserIdStmt->fetchColumn();
            if ($parentUserId > 0) {
                gjc_notify(
                    $db,
                    $parentUserId,
                    'topup',
                    'Wallet Loaded',
                    gjc_user_label($db, $ownerMerchId) . ' loaded ' . gjc_money_plain($result['credited_amount']) . ' into your wallet.',
                    'circle-plus',
                    PARENT_URL . '/wallet.php'
                );
            }

            if ($result['merchant_fee'] > 0) {
                gjc_notify(
                    $db,
                    $ownerMerchId,
                    'topup',
                    'Wallet Credited',
                    'Your ' . CirculationEngine::ratePct(CirculationEngine::FEE_MERCHANT_RATE) . ' cut of ' . gjc_money_plain($result['merchant_fee']) . ' was credited for loading a parent wallet.',
                    'circle-plus',
                    MERCHANT_URL . '/history.php'
                );
            }

            echo json_encode(array_merge(['success' => true], $result));
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action: '{$action}'"]);
    }

} catch (\RuntimeException | \InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => gjc_safe_error($e, 'merchant/api/topup')]);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[merchant/api/topup.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
