<?php
// ============================================================
//  admin/api/get_stall_details.php
//  What is left of the old stall-detail modal's backend. The
//  modal became admin/merchant_details.php, which renders every
//  panel server-side, so the read actions (details, payments,
//  inventory, activity) and the lease writes (update_lease,
//  record_rent_payment — duplicates of admin/api/leases.php) are
//  gone. Flagging a product is the one thing the page still has
//  to post, so it is the one action left here.
// ============================================================
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/MerchantTenantDirectory.php';

header('Content-Type: application/json');

// Finance-wide, matching admin/merchant_details.php and admin/api/leases.php.
// gjc_require_role() redirects a non-finance session, which an XHR reads as a
// failed request; the explicit check keeps the JSON contract for that case.
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
