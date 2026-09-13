<?php
declare(strict_types=1);
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);

$parentUserId = gjc_user_id();

$pStmt = $db->prepare("SELECT id FROM parents WHERE user_id = ?");
$pStmt->execute([$parentUserId]);
$parentRow = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$parentRow) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Parent record not found.']);
    exit;
}
$parentId = (int) $parentRow['id'];

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($contentType, 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : array_merge($_GET, $_POST);

$action = strtolower(trim((string) ($body['action'] ?? '')));

// Helper: verify parent-student link and return student wallet id
function assertLinkedWallet(PDO $db, int $parentId, int $studentUserId): int
{
    if (!$studentUserId) throw new \InvalidArgumentException('Student user ID required.');

    $chk = $db->prepare("SELECT 1 FROM parent_student_links WHERE parent_id = ? AND student_user_id = ?");
    $chk->execute([$parentId, $studentUserId]);
    if (!$chk->fetch()) throw new \RuntimeException('You are not linked to this student.');

    $wStmt = $db->prepare("SELECT id FROM student_wallets WHERE user_id = ?");
    $wStmt->execute([$studentUserId]);
    $wallet = $wStmt->fetch(PDO::FETCH_ASSOC);
    if (!$wallet) throw new \RuntimeException('Student wallet not found.');

    return (int) $wallet['id'];
}

try {
    switch ($action) {

        case 'set_frozen': {
            $studentUserId = (int) ($body['student_user_id'] ?? 0);
            $value         = (int) ($body['value'] ?? 0);
            $walletId      = assertLinkedWallet($db, $parentId, $studentUserId);

            // Read the current value first so the entry has a real before/after.
            $prev = $db->prepare("SELECT is_frozen FROM student_wallets WHERE id = ?");
            $prev->execute([$walletId]);
            $wasFrozen = (int) $prev->fetchColumn();

            $db->prepare("UPDATE student_wallets SET is_frozen = ? WHERE id = ?")->execute([$value ? 1 : 0, $walletId]);

            // Freezing a wallet stops a student spending their own money. None of
            // the parent controls were audited at all before this.
            logAudit(
                $db, $parentUserId, gjc_current_role(), 'PARENT_CONTROL', 'student_wallets',
                ['wallet_id' => $walletId, 'is_frozen' => $wasFrozen],
                [
                    'wallet_id'       => $walletId,
                    'student_user_id' => $studentUserId,
                    'is_frozen'       => $value ? 1 : 0,
                    'event'           => $value ? 'wallet_frozen' : 'wallet_unfrozen',
                ],
                null,
                ['target_id' => $walletId]
            );

            echo json_encode(['success' => true]);
            break;
        }

        case 'set_daily_limit': {
            $studentUserId = (int) ($body['student_user_id'] ?? 0);
            $amount        = max(0.0, (float) ($body['amount'] ?? 0));
            $walletId      = assertLinkedWallet($db, $parentId, $studentUserId);

            $prev = $db->prepare("SELECT daily_spend_limit FROM student_wallets WHERE id = ?");
            $prev->execute([$walletId]);
            $prevLimit = $prev->fetchColumn();

            $db->prepare("UPDATE student_wallets SET daily_spend_limit = ? WHERE id = ?")->execute([$amount, $walletId]);

            logAudit(
                $db, $parentUserId, gjc_current_role(), 'PARENT_CONTROL', 'student_wallets',
                ['wallet_id' => $walletId, 'daily_spend_limit' => $prevLimit === false ? null : (float) $prevLimit],
                [
                    'wallet_id'         => $walletId,
                    'student_user_id'   => $studentUserId,
                    'daily_spend_limit' => $amount,
                    'event'             => 'daily_limit_set',
                ],
                null,
                ['target_id' => $walletId]
            );

            echo json_encode(['success' => true]);
            break;
        }

        case 'set_alert_threshold': {
            $amount = max(0.0, (float) ($body['amount'] ?? 0));
            $prev = $db->prepare("SELECT low_balance_threshold FROM parents WHERE id = ?");
            $prev->execute([$parentId]);
            $prevThreshold = $prev->fetchColumn();

            $db->prepare("UPDATE parents SET low_balance_threshold = ? WHERE id = ?")->execute([$amount, $parentId]);

            logAudit(
                $db, $parentUserId, gjc_current_role(), 'PARENT_CONTROL', 'parents',
                ['parent_id' => $parentId, 'low_balance_threshold' => $prevThreshold === false ? null : (float) $prevThreshold],
                [
                    'parent_id'             => $parentId,
                    'low_balance_threshold' => $amount,
                    'event'                 => 'alert_threshold_set',
                ],
                null,
                ['target_id' => $parentId]
            );

            echo json_encode(['success' => true]);
            break;
        }

        case 'mark_alerts_read': {
            $db->prepare("UPDATE parent_alerts SET is_read = 1 WHERE parent_id = ?")->execute([$parentId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'unlink_student': {
            $studentUserId = (int) ($body['student_user_id'] ?? 0);
            if (!$studentUserId) throw new \InvalidArgumentException('Student user ID required.');
            $chk = $db->prepare("SELECT 1 FROM parent_student_links WHERE parent_id = ? AND student_user_id = ?");
            $chk->execute([$parentId, $studentUserId]);
            if (!$chk->fetch()) throw new \RuntimeException('Link not found.');
            $db->prepare("DELETE FROM parent_student_links WHERE parent_id = ? AND student_user_id = ?")->execute([$parentId, $studentUserId]);

            // Unlinking removes a guardian's oversight of a student's wallet.
            logAudit(
                $db, $parentUserId, gjc_current_role(), 'PARENT_LINK', 'parent_student_links',
                ['parent_id' => $parentId, 'student_user_id' => $studentUserId, 'linked' => true],
                ['parent_id' => $parentId, 'student_user_id' => $studentUserId, 'event' => 'unlinked'],
                null,
                ['target_id' => $studentUserId]
            );

            echo json_encode(['success' => true]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action: '{$action}'"]);
    }

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[parent/api/controls.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
