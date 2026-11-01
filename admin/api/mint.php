<?php


header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
// Needed by CirculationEngine::logTransaction() (gjc_active_school_year_id()).
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/MintingGuard.php';

if (!isset($_SESSION['userID']) || ($_SESSION['sub_role'] ?? '') !== 'super_admin') {
    // Log denied mint attempts.
    logAudit(
        $db,
        (int) ($_SESSION['userID'] ?? 0),
        (string) ($_SESSION['sub_role'] ?? ''),
        'ACCESS_DENIED',
        'system_settings',
        null,
        [
            'endpoint_action' => 'mint',
            'actual_sub_role' => (string) ($_SESSION['sub_role'] ?? ''),
            'required'        => 'super_admin',
        ],
        null,
        [
            'outcome' => 'denied',
            'reason'  => 'Mint endpoint reached without the super-admin sub-role.',
        ]
    );
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'ACCESS_DENIED: Super-Admin only.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed.']);
    exit;
}

gjc_csrf_require_json();

// JSON only. A form post from another site cannot set this content type
// without a CORS preflight, so it cannot reach the minting code.
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (!str_contains($contentType, 'application/json')) {
    http_response_code(415);
    echo json_encode(['success' => false, 'error' => 'Mint requests must be JSON.']);
    exit;
}
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$amount  = isset($body['amount'])  ? (float)$body['amount']    : 0.0;
$reason  = isset($body['reason'])  ? trim((string)$body['reason']) : '';
$pin     = isset($body['pin'])     ? trim((string)$body['pin'])    : null;
if ($pin === '') $pin = null;

try {
    $guard  = new MintingGuard($db);
    $result = $guard->attemptMint($_SESSION['userID'], $amount, $reason, $pin);
    echo json_encode($result);
} catch (RuntimeException | InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => gjc_safe_error($e, 'admin/api/mint')]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[mint.php] Unexpected error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error. Check server logs.']);
}

