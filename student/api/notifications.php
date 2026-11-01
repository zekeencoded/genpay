<?php
// Topbar notification bell: list and mark read.
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';

header('Content-Type: application/json');
// parent/api/notifications.php reuses this endpoint with its own role.
gjc_require_role($gjcNotificationRoles ?? ['student']);

$currentUserId = gjc_user_id();
$action = trim((string) ($_POST['action'] ?? ($_GET['action'] ?? 'list')));

try {
    if ($action === 'list') {
        $rows = gjc_notifications_recent($db, $currentUserId, 20);
        $role = gjc_current_role();
        $viewUrls = gjc_notification_view_urls($db, $rows, $currentUserId, $role);
        // Where a tap goes when the notification isn't about a transaction.
        $typeUrls = $role === 'parent' ? [] : [
            'fee_waiver' => STUDENT_URL . '/profile.php?tab=status',
            'guardian_link' => STUDENT_URL . '/profile.php?tab=guardian',
        ];
        $defaultUrl = $role === 'parent' ? PARENT_URL . '/activity.php?student=me' : STUDENT_URL . '/history.php';
        echo json_encode([
            'success' => true,
            'unread_count' => gjc_notifications_unread_count($db, $currentUserId),
            'notifications' => array_map(
                static function (array $row) use ($viewUrls, $typeUrls, $defaultUrl): array {
                    return [
                        'id' => (int) $row['id'],
                        'type' => (string) $row['type'],
                        'icon' => (string) $row['icon'],
                        'title' => (string) $row['title'],
                        'message' => (string) $row['message'],
                        // Tap target: the transaction's details page, else the section it's
                        // about, else its stored link, else the wallet history.
                        'url' => $viewUrls[(int) $row['id']]
                            ?? $typeUrls[(string) $row['type']]
                            ?? (($row['link'] ?? '') !== '' ? (string) $row['link'] : $defaultUrl),
                        'is_read' => (int) $row['is_read'] === 1,
                        'created_at' => (string) $row['created_at'],
                    ];
                },
                $rows
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
        if (gjc_current_role() === 'parent') {
            gjc_parent_alerts_mark_read_from_bell($db, $currentUserId, $id);
        }
        gjc_notifications_mark_read($db, $currentUserId, $id);
        echo json_encode([
            'success' => true,
            'unread_count' => gjc_notifications_unread_count($db, $currentUserId),
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'student/api/notifications', 'Server error. Please try again.')]);
}
