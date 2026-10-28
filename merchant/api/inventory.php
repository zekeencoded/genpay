<?php
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json');
gjc_require_role(['merchant']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') gjc_csrf_require_json();
gjc_ensure_inventory_sku_index($db);

$action         = trim((string) ($_POST['action'] ?? ''));
$merchantUserId = gjc_user_id();
$ownerMerchId   = gjc_merchant_owner_id($db, $merchantUserId);
$isMerchAdmin   = gjc_is_merchant_admin() || (gjc_current_role() === 'merchant' && !gjc_is_merchant_staff());

try {
    switch ($action) {
        case 'add_product': {
            if (!$isMerchAdmin) {
                echo json_encode(['success' => false, 'message' => 'Only Merchant Admin can add products.']);
                exit;
            }
            $sku          = trim((string) ($_POST['sku'] ?? ''));
            $productName  = trim((string) ($_POST['product_name'] ?? ''));
            $description  = trim((string) ($_POST['description'] ?? ''));
            $category     = trim((string) ($_POST['category'] ?? 'general'));
            $unit         = trim((string) ($_POST['unit'] ?? 'piece'));
            $price        = (float)  ($_POST['price'] ?? 0);
            $stockQty     = (int)    ($_POST['stock_qty'] ?? 0);
            $minAlert     = (int)    ($_POST['min_stock_alert'] ?? 5);
            $isAvailable  = isset($_POST['is_available']) ? 1 : 0;

            if (!$productName || $price < 0) {
                echo json_encode(['success' => false, 'message' => 'Product name and valid price are required.']);
                exit;
            }

            if ($sku !== '') {
                $dupe = $db->prepare(
                    "SELECT id FROM merchant_inventory WHERE merchant_user_id = ? AND LOWER(sku) = LOWER(?)"
                );
                $dupe->execute([$merchantUserId, $sku]);
                if ($dupe->fetchColumn()) {
                    echo json_encode(['success' => false, 'message' => "SKU \"{$sku}\" is already used by another product. Each SKU must be unique since it doubles as the scan barcode."]);
                    exit;
                }
            }

            // Banned-product gate: reject the save outright and alert the admins.
            $restrictionReason = gjc_check_restricted($db, $productName);
            if ($restrictionReason !== null) {
                logAudit(
                    $db, $merchantUserId, gjc_current_role(),
                    'PRODUCT_RESTRICTION', 'merchant_inventory', null,
                    [
                        'event' => 'blocked_add',
                        'merchant_user_id' => $merchantUserId,
                        'attempted_name' => $productName,
                        'matched_reason' => $restrictionReason,
                    ],
                    null,
                    ['reason' => $restrictionReason]
                );
                $violationCount = gjc_record_product_violation($db, $merchantUserId, $restrictionReason);
                echo json_encode([
                    'success' => false,
                    'blocked' => true,
                    'message' => "\"{$productName}\" is a banned product and cannot be added: {$restrictionReason}",
                    'attempted_name' => $productName,
                    'reason' => $restrictionReason,
                    'violation_count' => $violationCount,
                    'warn_at' => gjc_setting_int($db, 'violation_warn_at'),
                    'risk_at' => gjc_setting_int($db, 'violation_risk_at'),
                    'suspend_days' => gjc_setting_int($db, 'violation_suspend_days'),
                    'suspended_until' => gjc_merchant_suspended_until($db, $merchantUserId),
                ]);
                exit;
            }
            $isRestricted = 0;

            $stmt = $db->prepare(
                "INSERT INTO merchant_inventory
                    (merchant_user_id, sku, product_name, description, category, unit, price,
                     stock_qty, min_stock_alert, is_available, is_restricted, restriction_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $merchantUserId,
                $sku ?: null,
                $productName,
                $description ?: null,
                $category,
                $unit,
                $price,
                $stockQty,
                $minAlert,
                $isAvailable,
                $isRestricted,
                $restrictionReason,
            ]);
            $itemId = (int) $db->lastInsertId();

            logAudit(
                $db,
                $merchantUserId,
                gjc_current_role(),
                'MENU_MUTATION',
                'menu_items',
                null,
                [
                    'id' => $itemId,
                    'merchant_user_id' => $merchantUserId,
                    'sku' => $sku ?: null,
                    'product_name' => $productName,
                    'description' => $description ?: null,
                    'category' => $category,
                    'unit' => $unit,
                    'price' => $price,
                    'stock_qty' => $stockQty,
                    'min_stock_alert' => $minAlert,
                    'is_available' => $isAvailable,
                    'is_restricted' => $isRestricted,
                    'restriction_note' => $restrictionReason,
                ],
                null,
                ['target_id' => $itemId]
            );

            $msg = $isRestricted
                ? "Product saved but flagged as RESTRICTED: {$restrictionReason}"
                : 'Product added successfully.';
            echo json_encode(['success' => true, 'message' => $msg, 'is_restricted' => $isRestricted]);
            break;
        }

        case 'edit_product': {
            if (!$isMerchAdmin) {
                echo json_encode(['success' => false, 'message' => 'Only Merchant Admin can edit products.']);
                exit;
            }
            $itemId      = (int) ($_POST['item_id'] ?? 0);
            $sku         = trim((string) ($_POST['sku'] ?? ''));
            $productName = trim((string) ($_POST['product_name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $category    = trim((string) ($_POST['category'] ?? 'general'));
            $unit        = trim((string) ($_POST['unit'] ?? 'piece'));
            $price       = (float) ($_POST['price'] ?? 0);
            $stockQty    = (int)   ($_POST['stock_qty'] ?? 0);
            $minAlert    = (int)   ($_POST['min_stock_alert'] ?? 5);
            $isAvailable = isset($_POST['is_available']) ? 1 : 0;

            if (!$itemId || !$productName) {
                echo json_encode(['success' => false, 'message' => 'Item ID and product name required.']);
                exit;
            }

            // Verify ownership
            $own = $db->prepare(
                "SELECT id, sku, product_name, description, category, unit, price,
                        stock_qty, min_stock_alert, is_available, is_restricted, restriction_note
                   FROM merchant_inventory WHERE id = ? AND merchant_user_id = ?"
            );
            $own->execute([$itemId, $merchantUserId]);
            $oldItem = $own->fetch(PDO::FETCH_ASSOC);
            if (!$oldItem) {
                echo json_encode(['success' => false, 'message' => 'Item not found in your inventory.']);
                exit;
            }

            if ($sku !== '') {
                $dupe = $db->prepare(
                    "SELECT id FROM merchant_inventory WHERE merchant_user_id = ? AND LOWER(sku) = LOWER(?) AND id != ?"
                );
                $dupe->execute([$merchantUserId, $sku, $itemId]);
                if ($dupe->fetchColumn()) {
                    echo json_encode(['success' => false, 'message' => "SKU \"{$sku}\" is already used by another product. Each SKU must be unique since it doubles as the scan barcode."]);
                    exit;
                }
            }

            // Banned-product gate: reject the rename/save outright and alert admins.
            $restrictionReason = gjc_check_restricted($db, $productName);
            if ($restrictionReason !== null) {
                logAudit(
                    $db, $merchantUserId, gjc_current_role(),
                    'PRODUCT_RESTRICTION', 'merchant_inventory', $oldItem,
                    [
                        'event' => 'blocked_edit',
                        'id' => $itemId,
                        'merchant_user_id' => $merchantUserId,
                        'attempted_name' => $productName,
                        'matched_reason' => $restrictionReason,
                    ],
                    null,
                    ['target_id' => $itemId, 'reason' => $restrictionReason]
                );
                $violationCount = gjc_record_product_violation($db, $merchantUserId, $restrictionReason);
                echo json_encode([
                    'success' => false,
                    'blocked' => true,
                    'message' => "\"{$productName}\" is a banned product and cannot be saved: {$restrictionReason}",
                    'attempted_name' => $productName,
                    'reason' => $restrictionReason,
                    'violation_count' => $violationCount,
                    'warn_at' => gjc_setting_int($db, 'violation_warn_at'),
                    'risk_at' => gjc_setting_int($db, 'violation_risk_at'),
                    'suspend_days' => gjc_setting_int($db, 'violation_suspend_days'),
                    'suspended_until' => gjc_merchant_suspended_until($db, $merchantUserId),
                ]);
                exit;
            }
            $isRestricted = 0;

            $stmt = $db->prepare(
                "UPDATE merchant_inventory
                    SET sku=?, product_name=?, description=?, category=?, unit=?, price=?,
                        stock_qty=?, min_stock_alert=?, is_available=?, is_restricted=?, restriction_note=?
                  WHERE id=? AND merchant_user_id=?"
            );
            $stmt->execute([
                $sku ?: null, $productName, $description ?: null, $category, $unit, $price,
                $stockQty, $minAlert, $isAvailable, $isRestricted, $restrictionReason,
                $itemId, $merchantUserId,
            ]);
            logAudit(
                $db,
                $merchantUserId,
                gjc_current_role(),
                'MENU_MUTATION',
                'menu_items',
                $oldItem,
                [
                    'id' => $itemId,
                    'merchant_user_id' => $merchantUserId,
                    'sku' => $sku ?: null,
                    'product_name' => $productName,
                    'description' => $description ?: null,
                    'category' => $category,
                    'unit' => $unit,
                    'price' => $price,
                    'stock_qty' => $stockQty,
                    'min_stock_alert' => $minAlert,
                    'is_available' => $isAvailable,
                    'is_restricted' => $isRestricted,
                    'restriction_note' => $restrictionReason,
                ],
                null,
                ['target_id' => $itemId]
            );
            echo json_encode([
                'success' => true,
                'message' => $isRestricted ? "Saved but flagged as RESTRICTED: {$restrictionReason}" : 'Product updated.',
            ]);
            break;
        }

        case 'delete_product': {
            if (!$isMerchAdmin) {
                echo json_encode(['success' => false, 'message' => 'Only Merchant Admin can delete products.']);
                exit;
            }
            $itemId = (int) ($_POST['item_id'] ?? 0);
            if (!$itemId) {
                echo json_encode(['success' => false, 'message' => 'Item ID required.']);
                exit;
            }

            // Verify ownership before deleting (mirrors edit_product)
            $own = $db->prepare(
                "SELECT id, sku, product_name, description, category, unit, price,
                        stock_qty, min_stock_alert, is_available, is_restricted, restriction_note
                   FROM merchant_inventory WHERE id = ? AND merchant_user_id = ?"
            );
            $own->execute([$itemId, $merchantUserId]);
            $oldItem = $own->fetch(PDO::FETCH_ASSOC);
            if (!$oldItem) {
                echo json_encode(['success' => false, 'message' => 'Item not found in your inventory.']);
                exit;
            }

            $del = $db->prepare("DELETE FROM merchant_inventory WHERE id = ? AND merchant_user_id = ?");
            $del->execute([$itemId, $merchantUserId]);

            // Audit as a menu mutation: old = item, new = null (removed)
            logAudit(
                $db,
                $merchantUserId,
                gjc_current_role(),
                'MENU_MUTATION',
                'menu_items',
                $oldItem,
                null,
                null,
                ['target_id' => $itemId]
            );

            echo json_encode(['success' => true, 'message' => 'Product deleted.']);
            break;
        }

        case 'update_stock': {
            $itemId   = (int) ($_POST['item_id'] ?? 0);
            $stockQty = (int) ($_POST['stock_qty'] ?? 0);

            if (!$itemId || $stockQty < 0) {
                echo json_encode(['success' => false, 'message' => 'Valid item ID and stock quantity required.']);
                exit;
            }

            // Ownership check — staff can only update stock for their owner's inventory
            $oldStmt = $db->prepare(
                "SELECT id, sku, product_name, description, category, unit, price,
                        stock_qty, min_stock_alert, is_available, is_restricted, restriction_note
                   FROM merchant_inventory WHERE id = ? AND merchant_user_id = ?"
            );
            $oldStmt->execute([$itemId, $ownerMerchId]);
            $oldItem = $oldStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare(
                "UPDATE merchant_inventory SET stock_qty = ? WHERE id = ? AND merchant_user_id = ?"
            );
            $stmt->execute([$stockQty, $itemId, $ownerMerchId]);
            if ($stmt->rowCount() > 0 && $oldItem) {
                $newItem = $oldItem;
                $newItem['stock_qty'] = $stockQty;
                logAudit(
                    $db,
                    $merchantUserId,
                    gjc_current_role(),
                    'MENU_MUTATION',
                    'menu_items',
                    $oldItem,
                    $newItem,
                    null,
                    ['target_id' => $itemId]
                );
            }
            echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => $stmt->rowCount() > 0 ? 'Stock updated.' : 'Item not found.']);
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'merchant/api/inventory', 'Server error. Please try again.')]);
}
