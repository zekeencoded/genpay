<?php
// ============================================================
//  student/api/guardian_link.php
//  A student approves or declines a parent's request to link to
//  their wallet (requests are created by parent/api/link.php).
// ============================================================
declare(strict_types=1);
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

gjc_require_role(['student']);
gjc_ensure_parent_schema($db);

$studentUserId = gjc_user_id();

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($contentType, 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : $_POST;

$action = strtolower(trim((string) ($body['action'] ?? '')));

if (!gjc_csrf_verify($body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security check failed. Please refresh the page and try again.']);
    exit;
}

try {
    if ($action !== 'approve' && $action !== 'decline') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Unknown action: '{$action}'"]);
        exit;
    }

    $requestId = (int) ($body['request_id'] ?? 0);

    // Scoped to this student, so nobody can answer a request addressed to someone else.
    $find = $db->prepare("SELECT parent_id FROM parent_link_requests WHERE id = ? AND student_user_id = ?");
    $find->execute([$requestId, $studentUserId]);
    $parentId = (int) $find->fetchColumn();
    if (!$parentId) {
        throw new \RuntimeException('This request is no longer pending.');
    }

    $db->beginTransaction();
    $del = $db->prepare("DELETE FROM parent_link_requests WHERE id = ? AND student_user_id = ?");
    $del->execute([$requestId, $studentUserId]);
    // rowCount guards a double-click: only the call that removed the request links.
    if ($del->rowCount() > 0 && $action === 'approve') {
        $db->prepare(
            "INSERT IGNORE INTO parent_student_links (parent_id, student_user_id) VALUES (?, ?)"
        )->execute([$parentId, $studentUserId]);
    }
    $db->commit();

    logAudit(
        $db, $studentUserId, gjc_current_role(), 'PARENT_LINK', 'parent_student_links',
        null,
        [
            'parent_id'       => $parentId,
            'student_user_id' => $studentUserId,
            'event'           => $action === 'approve' ? 'linked' : 'declined',
        ],
        null,
        ['target_id' => $studentUserId]
    );

    echo json_encode(['success' => true]);

} catch (\RuntimeException $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => gjc_safe_error($e, 'student/api/guardian_link')]);
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    error_log('[student/api/guardian_link.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
