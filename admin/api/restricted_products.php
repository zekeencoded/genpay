<?php
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json');
gjc_require_role(['finance']);

$action  = trim((string) ($_POST['action'] ?? ''));
$adminId = gjc_user_id();

try {
    switch ($action) {
        case 'flag_product': {
            $name      = trim((string) ($_POST['product_name'] ?? ''));
            $category  = trim((string) ($_POST['category'] ?? 'general'));
            $matchType = in_array(($_POST['match_type'] ?? ''), ['exact', 'contains'])
                ? $_POST['match_type'] : 'contains';
            $reason    = trim((string) ($_POST['reason'] ?? ''));

            if (!$name || !$reason) {
                echo json_encode(['success' => false, 'message' => 'Product name and reason are required.']);
                exit;
            }

            $stmt = $db->prepare(
                "INSERT INTO restricted_products (product_name, category, reason, match_type, flagged_by)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $category, $reason, $matchType, $adminId]);
            $newProductId = (int) $db->lastInsertId();

            // Re-scan existing inventory: disable any already-listed items that
            // match this new ban under the smart word/fuzzy rules. Closes the
            // "merchant added it before the ban" loophole.
            $disabled = 0;
            if (gjc_table_exists($db, 'merchant_inventory')) {
                $items = $db->query(
                    "SELECT id, product_name FROM merchant_inventory WHERE is_restricted = 0"
                )->fetchAll(PDO::FETCH_ASSOC);
                $upd = $db->prepare(
                    "UPDATE merchant_inventory
                        SET is_restricted = 1, is_available = 0, restriction_note = ?
                      WHERE id = ?"
                );
                foreach ($items as $it) {
                    if (gjc_restriction_matches($name, $matchType, (string) $it['product_name'])) {
                        $upd->execute([$reason, $it['id']]);
                        $disabled++;
                    }
                }
            }

            logAudit(
                $db,
                $adminId,
                gjc_current_role(),
                'PRODUCT_RESTRICTION',
                'restricted_products',
                null,
                [
                    'event' => 'flagged',
                    'id' => $newProductId,
                    'product_name' => $name,
                    'category' => $category,
                    'match_type' => $matchType,
                    'reason' => $reason,
                    'existing_disabled' => $disabled,
                ],
                null,
                ['target_id' => $newProductId, 'reason' => $reason]
            );

            $msg = $disabled > 0
                ? "Product banned. {$disabled} existing item" . ($disabled === 1 ? '' : 's') . " disabled."
                : 'Product banned successfully.';
            echo json_encode(['success' => true, 'message' => $msg, 'existing_disabled' => $disabled]);
            break;
        }

        case 'toggle_restriction': {
            $id       = (int) ($_POST['id'] ?? 0);
            $isActive = (int) ($_POST['is_active'] ?? 0);

            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
                exit;
            }

            $prevStmt = $db->prepare("SELECT is_active FROM restricted_products WHERE id = ?");
            $prevStmt->execute([$id]);
            $prevActive = $prevStmt->fetchColumn();

            $db->prepare("UPDATE restricted_products SET is_active = ? WHERE id = ?")
               ->execute([$isActive, $id]);

            logAudit(
                $db,
                $adminId,
                gjc_current_role(),
                'PRODUCT_RESTRICTION',
                'restricted_products',
                $prevActive === false ? null : ['id' => $id, 'is_active' => (int) $prevActive],
                ['event' => 'status_changed', 'id' => $id, 'is_active' => $isActive],
                null,
                ['target_id' => $id]
            );

            echo json_encode(['success' => true, 'message' => 'Status updated.']);
            break;
        }

        case 'delete_restriction': {
            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
                exit;
            }
            $prevStmt = $db->prepare("SELECT product_name FROM restricted_products WHERE id = ?");
            $prevStmt->execute([$id]);
            $prevName = (string) $prevStmt->fetchColumn();

            $db->prepare("DELETE FROM restricted_products WHERE id = ?")->execute([$id]);

            logAudit($db, $adminId, gjc_current_role(), 'PRODUCT_RESTRICTION', 'restricted_products',
                ['id' => $id, 'product_name' => $prevName], ['event' => 'deleted', 'id' => $id],
                null,
                ['target_id' => $id]
);

            echo json_encode(['success' => true, 'message' => 'Restriction removed.']);
            break;
        }

        case 'lift_suspension': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            if (!$userId) {
                echo json_encode(['success' => false, 'message' => 'Invalid merchant.']);
                exit;
            }

            $until = gjc_merchant_suspended_until($db, $userId);
            if ($until === null) {
                echo json_encode(['success' => false, 'message' => 'This merchant is not currently suspended.']);
                exit;
            }

            $db->prepare("UPDATE users SET restricted_suspended_until = NULL WHERE userID = ?")->execute([$userId]);

            logAudit(
                $db, $adminId, gjc_current_role(),
                'PRODUCT_RESTRICTION', 'users',
                ['userID' => $userId, 'restricted_suspended_until' => $until],
                ['event' => 'suspension_lifted', 'merchant_user_id' => $userId, 'lifted_by' => $adminId],
                null,
                ['target_id' => $userId]
            );

            gjc_notify(
                $db, $userId, 'compliance', 'Suspension lifted',
                'A GenPay finance admin lifted your restricted-product suspension early. You and your staff can log in and sell again — please review the Restricted Products list before adding new items.',
                'circle-check'
            );

            echo json_encode(['success' => true, 'message' => 'Suspension lifted. The merchant can log in again.']);
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (\Throwable $e) {
    error_log('[admin/api/restricted_products.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Nothing was changed.']);
}
