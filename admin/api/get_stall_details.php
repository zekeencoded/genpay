<?php
// Flag/unflag a product for the merchant details page (the only action left).
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/MerchantTenantDirectory.php';

header('Content-Type: application/json');

// Explicit role check so XHR callers get JSON instead of a redirect.
if (gjc_current_role() !== 'finance') {
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Finance privileges are required.',
    ]);
    exit;
}

$directory = new MerchantTenantDirectory($db);
$action = trim((string) ($_REQUEST['action'] ?? ''));
$adminId = gjc_user_id();

function gjc_json_fail(string $message): void
{
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

try {
    switch ($action) {
        case 'toggle_product_restriction': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                gjc_json_fail('POST is required for compliance updates.');
            }
            gjc_csrf_require_json();

            $itemId = (int) ($_POST['item_id'] ?? 0);
            $restricted = (int) ($_POST['restricted'] ?? 0) === 1;
            $note = trim((string) ($_POST['note'] ?? ''));

            if ($itemId <= 0) {
                gjc_json_fail('Invalid inventory item.');
            }

            $updated = $directory->toggleProductRestriction($itemId, $restricted, $adminId, $note);
            echo json_encode([
                'success' => $updated,
                'message' => $updated
                    ? ($restricted ? 'Product restricted and disabled from POS.' : 'Product restriction cleared.')
                    : 'Inventory item not found.',
            ]);
            break;
        }

        default:
            gjc_json_fail('Unknown action.');
    }
} catch (Throwable $e) {
    error_log('[admin/api/get_stall_details.php] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. Nothing was changed.',
    ]);
}
