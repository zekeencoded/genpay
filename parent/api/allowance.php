<?php
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
gjc_ensure_parent_wallet_schema($db);

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

        case 'send': {
            $studentUserId = (int) ($body['student_user_id'] ?? 0);
            $amount = round((float) ($body['amount'] ?? 0), 2);
            $note = trim((string) ($body['note'] ?? ''));

            if (!$studentUserId) {
                throw new \InvalidArgumentException('Select a student to send to.');
            }
            if ($amount < 1.00) {
                throw new \InvalidArgumentException('Minimum allowance amount is ₱1.00.');
            }

            // Never trust the posted student id — re-verify the link every time.
            $linkChk = $db->prepare(
                "SELECT u.first_name, u.last_name
                   FROM parent_student_links psl
                   JOIN users u ON u.userID = psl.student_user_id
                  WHERE psl.parent_id = ? AND psl.student_user_id = ?"
            );
            $linkChk->execute([$parentId, $studentUserId]);
            $student = $linkChk->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                throw new \RuntimeException('You are not linked to this student.');
            }
            $studentName = trim($student['first_name'] . ' ' . $student['last_name']);

            // A suspended student can't be topped up, even by their own parent.
            $studentBlocked = gjc_funds_in_block_reason($db, $studentUserId);
            if ($studentBlocked !== null) {
                throw new \RuntimeException($studentBlocked);
            }

            $parentWallet = gjc_parent_wallet($db, $parentId);
            $studentWallet = gjc_student_wallet($db, $studentUserId);
            if ($studentWallet['id'] === 0) {
                throw new \RuntimeException('Student wallet not found.');
            }
            if ($parentWallet['balance'] < $amount) {
                throw new \RuntimeException('Insufficient wallet balance. Top up your wallet first.');
            }

            $engine = new CirculationEngine($db);
            $result = $engine->allowanceTransfer(
                $parentWallet['id'],
                $studentWallet['id'],
                $amount,
                $parentUserId,
                $note !== '' ? ('Allowance: ' . $note) : ''
            );

            logAudit(
                $db,
                $parentUserId,
                gjc_current_role(),
                'TRANSACTION',
                'transactions',
                null,
                [
                    'event' => 'allowance_send',
                    'reference_no' => $result['reference'],
                    'amount' => $amount,
                    'parent_wallet_id' => $parentWallet['id'],
                    'student_wallet_id' => $studentWallet['id'],
                    'student_user_id' => $studentUserId,
                ],
                null,
                ['target_id' => $result['reference']]
            );

            gjc_notify_wallet(
                $db,
                $studentWallet['id'],
                'allowance',
                'Allowance Received',
                gjc_user_label($db, $parentUserId) . ' sent you ' . gjc_money_plain($amount) . '.',
                'hand-holding-dollar',
                STUDENT_URL . '/history.php'
            );

            echo json_encode([
                'success' => true,
                'message' => sprintf('Sent %s to %s.', gjc_money($amount), $studentName),
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
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[parent/api/allowance.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
