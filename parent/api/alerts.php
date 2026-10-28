<?php
declare(strict_types=1);
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';

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

$body = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $body = array_merge($_GET, $_POST);
}

$action = strtolower(trim((string) ($body['action'] ?? 'get_alerts')));
if ($_SERVER['REQUEST_METHOD'] === 'POST') gjc_csrf_require_json();

if ($action === 'mark_read') {
    $db->prepare("UPDATE parent_alerts SET is_read = 1 WHERE parent_id = ?")->execute([$parentId]);
    echo json_encode(['success' => true]);
    exit;
}

// get_alerts (default)
$unreadStmt = $db->prepare("SELECT COUNT(*) FROM parent_alerts WHERE parent_id = ? AND is_read = 0");
$unreadStmt->execute([$parentId]);
$unreadCount = (int) $unreadStmt->fetchColumn();

$alertsStmt = $db->prepare(
    "SELECT pa.id, pa.balance_at_alert, pa.threshold, pa.is_read, pa.created_at,
            u.first_name, u.last_name, si.studentID
       FROM parent_alerts pa
       JOIN student_wallets sw ON sw.id = pa.student_wallet_id
       JOIN users u ON u.userID = sw.user_id
       LEFT JOIN student_info si ON si.userID = u.userID
      WHERE pa.parent_id = ?
      ORDER BY pa.created_at DESC
      LIMIT 10"
);
$alertsStmt->execute([$parentId]);
$alerts = $alertsStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success'      => true,
    'unread_count' => $unreadCount,
    'alerts'       => $alerts,
]);
