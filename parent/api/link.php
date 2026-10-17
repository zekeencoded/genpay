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

try {
    switch ($action) {

        case 'lookup_student':
        case 'link_student': {
            $schoolId = strtoupper(trim((string) ($body['school_id'] ?? '')));
            if (!$schoolId) {
                throw new \InvalidArgumentException('Student school ID is required.');
            }

            // Look up student by school ID
            $stmt = $db->prepare(
                "SELECT u.userID, u.first_name, u.last_name
                   FROM users u
                   JOIN student_info si ON si.userID = u.userID
                  WHERE si.studentID = ? AND u.roleID = 1
                  LIMIT 1"
            );
            $stmt->execute([$schoolId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                throw new \RuntimeException('No student found with that school ID. Check the ID and try again.');
            }

            $studentUserId = (int) $student['userID'];
            $studentName   = trim($student['first_name'] . ' ' . $student['last_name']);

            // Check already linked
            $chk = $db->prepare("SELECT id FROM parent_student_links WHERE parent_id = ? AND student_user_id = ?");
            $chk->execute([$parentId, $studentUserId]);
            if ($chk->fetch()) {
                throw new \RuntimeException($studentName . ' is already linked to your account.');
            }

            if ($action === 'lookup_student') {
                echo json_encode([
                    'success' => true,
                    'student_name' => $studentName,
                    'school_id' => $schoolId,
                ]);
                break;
            }

            // A school ID alone must not grant control of a wallet: the link only
            // becomes real once the student approves it (student/api/guardian_link.php).
            $req = $db->prepare(
                "INSERT IGNORE INTO parent_link_requests (parent_id, student_user_id) VALUES (?, ?)"
            );
            $req->execute([$parentId, $studentUserId]);
            if ($req->rowCount() === 0) {
                throw new \RuntimeException('Your request is still waiting for ' . $studentName . ' to approve it.');
            }

            $me = gjc_current_user($db);
            gjc_notify(
                $db, $studentUserId, 'guardian_link', 'A parent wants to link to your account',
                $me['name'] . ' asked to link to your wallet. Approve only if this is your parent or guardian.',
                'link', STUDENT_URL . '/profile.php'
            );

            logAudit(
                $db, $parentUserId, gjc_current_role(), 'PARENT_LINK', 'parent_link_requests',
                null,
                [
                    'parent_id'       => $parentId,
                    'student_user_id' => $studentUserId,
                    'school_id'       => $schoolId,
                    'event'           => 'requested',
                ],
                null,
                ['target_id' => $studentUserId]
            );

            echo json_encode([
                'success' => true,
                'message' => 'Request sent. ' . $studentName . ' must approve it from their GenPay profile.',
            ]);
            break;
        }

        case 'unlink_student': {
            $studentUserId = (int) ($body['student_user_id'] ?? 0);
            if (!$studentUserId) throw new \InvalidArgumentException('Student ID required.');

            $deleted = $db->prepare(
                "DELETE FROM parent_student_links WHERE parent_id = ? AND student_user_id = ?"
            );
            $deleted->execute([$parentId, $studentUserId]);

            // Only record a link that actually existed — this endpoint does not
            // check first, so a repeated call would otherwise log a phantom unlink.
            if ($deleted->rowCount() > 0) {
                logAudit(
                    $db, $parentUserId, gjc_current_role(), 'PARENT_LINK', 'parent_student_links',
                    ['parent_id' => $parentId, 'student_user_id' => $studentUserId, 'linked' => true],
                    ['parent_id' => $parentId, 'student_user_id' => $studentUserId, 'event' => 'unlinked'],
                    null,
                    ['target_id' => $studentUserId]
                );
            }

            echo json_encode(['success' => true]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action: '{$action}'"]);
    }

} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => gjc_safe_error($e, 'parent/api/link')]);
} catch (\RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => gjc_safe_error($e, 'parent/api/link')]);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[parent/api/link.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
