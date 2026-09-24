<?php
// ============================================================
//  merchant/api/notifications.php
//  Backs the topbar notification bell: list recent notifications (with
//  unread count) and mark them read. Polled by includes/partials/topbar_merchant.php.
//  Owner-only by design — see includes/partials/topbar_merchant.php's
//  $topbarShowBell default (hidden for staff logins). This endpoint itself
//  just reflects whoever is logged in, same as student/api/notifications.php.
// ============================================================
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';

header('Content-Type: application/json');
gjc_require_role(['merchant']);

$currentUserId = gjc_user_id();
$action = trim((string) ($_POST['action'] ?? ($_GET['action'] ?? 'list')));

try {
    if ($action === 'list') {
        echo json_encode([
            'success' => true,
            'unread_count' => gjc_notifications_unread_count($db, $currentUserId),
            'notifications' => array_map(
                static function (array $row): array {
                    return [
                        'id' => (int) $row['id'],
                        'type' => (string) $row['type'],
                        'icon' => (string) $row['icon'],
                        'title' => (string) $row['title'],
                        'message' => (string) $row['message'],
                        'link' => $row['link'] !== null ? (string) $row['link'] : null,
                        'is_read' => (int) $row['is_read'] === 1,
                        'created_at' => (string) $row['created_at'],
                    ];
                },
                gjc_notifications_recent($db, $currentUserId, 20)
            ),
        ]);
        exit;
    }

    if ($action === 'unread_count') {
        echo json_encode([
            'success' => true,
            'unread_count' => gjc_notifications_unread_count($db, $currentUserId),
        ]);
        exit;
    }

    if ($action === 'mark_read') {
        if (!gjc_csrf_verify()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Security check failed. Please refresh the page and try again.']);
            exit;
        }
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        gjc_notifications_mark_read($db, $currentUserId, $id);
        echo json_encode([
            'success' => true,
            'unread_count' => gjc_notifications_unread_count($db, $currentUserId),
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'merchant/api/notifications', 'Server error. Please try again.')]);
}
