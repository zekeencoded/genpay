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
if (gjc_is_merchant_staff()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only the stall owner can send GenCoin.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') gjc_csrf_require_json();
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

        // One box for every recipient: Student/Employee ID, or a guardian's GRD2026-0001 ID.
        case 'lookup_recipient': {
            echo json_encode(['success' => true] + gjc_find_topup_recipient($db, (string) ($body['school_id'] ?? '')));
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
                STUDENT_URL . '/history.php',
                $result['reference']
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

        case 'load_parent_wallet': {
            $parentId   = (int) ($body['parent_id'] ?? 0);
            $cashAmount = (float) ($body['cash_amount'] ?? 0);

            if ($parentId <= 0) throw new \InvalidArgumentException('Invalid guardian.');
            if ($cashAmount <= 0) throw new \InvalidArgumentException('Cash amount must be greater than zero.');
            if ($cashAmount > $maxTopup) {
                throw new \InvalidArgumentException('Maximum top-up per request is ' . gjc_money_plain($maxTopup) . '.');
            }

            $parentWallet = gjc_parent_wallet($db, $parentId);

            $targetUserId = gjc_wallet_owner_user_id($db, 'parent', $parentWallet['id']);
            $blocked = $targetUserId > 0 ? gjc_funds_in_block_reason($db, $targetUserId) : null;
            if ($blocked !== null) throw new RuntimeException($blocked);

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
                    PARENT_URL . '/activity.php?student=me',
                    $result['reference']
                );
            }

            if ($result['merchant_fee'] > 0) {
                gjc_notify(
                    $db,
                    $ownerMerchId,
                    'topup',
                    'Wallet Credited',
                    'Your ' . CirculationEngine::ratePct(CirculationEngine::FEE_MERCHANT_RATE) . ' cut of ' . gjc_money_plain($result['merchant_fee']) . ' was credited for loading a guardian wallet.',
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
