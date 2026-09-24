<?php
// ============================================================
//  admin/api/archived_rejections.php
//  Read API for declined applications archived during Step 1/2,
//  plus a reactivate action to bring one back into the live pipeline.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';

header('Content-Type: application/json; charset=UTF-8');
gjc_require_role(['finance']);
gjc_ensure_archived_rejections_schema($db);

$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'list'));

function archived_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

try {
    if ($action === 'list') {
        $rows = $db->query(
            "SELECT * FROM archived_rejections WHERE reactivated = 0 ORDER BY archived_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        archived_json(['success' => true, 'rows' => $rows]);
    }

    if ($action === 'reactivate') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM archived_rejections WHERE id = ? AND reactivated = 0 LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            archived_json(['success' => false, 'message' => 'Archived record not found.']);
        }

        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO stall_applications
                    (business_name, proprietor_name, contact_number, email,
                     profile_picture, business_permit, sanitary_permit, gjc_requirements, clearance,
                     terms_accepted, status, current_step)
                 VALUES (?,?,?,?,?,?,?,?,?, 1, 'review', 1)"
            )->execute([
                $row['business_name'], $row['proprietor_name'], $row['contact_number'], $row['email'],
                $row['profile_picture'], $row['business_permit'], $row['sanitary_permit'],
                $row['gjc_requirements'], $row['clearance'],
            ]);
            $db->prepare("UPDATE archived_rejections SET reactivated = 1 WHERE id = ?")->execute([$id]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[admin/api/archived_rejections.php] reactivate: ' . $e->getMessage());
            archived_json(['success' => false, 'message' => 'The application could not be reactivated. Nothing was changed.']);
        }

        archived_json(['success' => true, 'message' => 'Application reactivated at Step 1 - Review Requirements.']);
    }

    archived_json(['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[admin/api/archived_rejections.php] ' . $e->getMessage());
    archived_json(['success' => false, 'message' => 'A server error occurred. Nothing was changed.']);
}
