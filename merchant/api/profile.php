<?php
// ============================================================
//  merchant/api/profile.php
//  Lets a merchant admin update their business display name and logo.
//  Both values are what the public Stall Directory (stalls.php) renders
//  for an occupied stall, via merchant.stall_name and users.profile_img.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json; charset=UTF-8');
gjc_require_role(['merchant']);

if (gjc_is_merchant_staff()) {
    echo json_encode(['success' => false, 'message' => 'Only the merchant admin can edit the business profile.']);
    exit;
}

$currentUser = gjc_current_user($db);
$userId = (int) $currentUser['id'];

$stmt = $db->prepare(
    "SELECT m.merchantID, m.stall_name, m.stall_id, u.profile_img
       FROM merchant m
       LEFT JOIN users u ON u.userID = m.userID
      WHERE m.userID = ? LIMIT 1"
);
$stmt->execute([$userId]);
$merchant = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$merchant) {
    echo json_encode(['success' => false, 'message' => 'Merchant record not found.']);
    exit;
}
$merchantId = (int) $merchant['merchantID'];
$oldStallName = (string) $merchant['stall_name'];
$oldLogo = trim((string) ($merchant['profile_img'] ?? ''));

/**
 * Short content hash of a stored logo, or null if there is no file.
 *
 * A re-uploaded logo with the same extension lands on the same path, so the
 * path alone cannot show in the audit trail that the image was replaced.
 */
function profile_logo_fingerprint(string $relPath): ?string
{
    $abs = BASE_PATH . '/' . ltrim($relPath, '/');
    if ($relPath === '' || !is_file($abs)) {
        return null;
    }
    $hash = hash_file('sha256', $abs);
    return $hash === false ? null : substr($hash, 0, 16);
}

// Taken before the upload below deletes or overwrites the old file.
$oldLogoFingerprint = profile_logo_fingerprint($oldLogo);

$stallName = trim((string) ($_POST['stall_name'] ?? ''));
if ($stallName === '' || mb_strlen($stallName) > 255) {
    echo json_encode(['success' => false, 'message' => 'Please enter a display name (1-255 characters).']);
    exit;
}

$logoRelPath = null;
if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['logo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Logo upload error (code ' . $file['error'] . ').']);
        exit;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Logo must be 5 MB or smaller.']);
        exit;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        echo json_encode(['success' => false, 'message' => 'Logo must be a JPG or PNG image.']);
        exit;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        echo json_encode(['success' => false, 'message' => 'Logo has an invalid file type.']);
        exit;
    }

    $logoDir = BASE_PATH . '/assets/merchant_logos';
    if (!is_dir($logoDir)) {
        mkdir($logoDir, 0755, true);
    }
    // Clear any previous logo for this merchant regardless of its extension.
    foreach (glob($logoDir . '/' . $merchantId . '.*') ?: [] as $old) {
        @unlink($old);
    }
    $logoRelPath = 'assets/merchant_logos/' . $merchantId . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], BASE_PATH . '/' . $logoRelPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded logo.']);
        exit;
    }
}

$db->prepare("UPDATE merchant SET stall_name = ? WHERE merchantID = ?")->execute([$stallName, $merchantId]);
if ($logoRelPath !== null) {
    $db->prepare("UPDATE users SET profile_img = ? WHERE userID = ?")->execute([$logoRelPath, $userId]);
}

// Before/after with the same keys on both sides, so the audit viewer's diff
// highlights exactly what changed. A save that changed nothing is not logged.
$nameChanged = $stallName !== $oldStallName;
$logoChanged = $logoRelPath !== null;

if ($nameChanged || $logoChanged) {
    $changed = array_values(array_filter([
        $nameChanged ? 'display name' : null,
        $logoChanged ? 'logo' : null,
    ]));

    logAudit(
        $db, $userId, gjc_current_role(), 'MERCHANT_PROFILE_UPDATE', 'merchant',
        [
            'event'            => 'business_profile_update',
            'stall_name'       => $oldStallName,
            'logo'             => $oldLogo !== '' ? $oldLogo : null,
            'logo_fingerprint' => $oldLogoFingerprint,
        ],
        [
            'event'            => 'business_profile_update',
            'stall_name'       => $stallName,
            'logo'             => $logoChanged ? $logoRelPath : ($oldLogo !== '' ? $oldLogo : null),
            'logo_fingerprint' => $logoChanged ? profile_logo_fingerprint($logoRelPath) : $oldLogoFingerprint,
        ],
        $merchant['stall_id'] ?? null,
        [
            'target_id' => $merchantId,
            'reason'    => 'Merchant changed their ' . implode(' and ', $changed) . ' on the Business Profile page.',
        ]
    );
}

echo json_encode([
    'success' => true,
    'message' => 'Business profile updated. Changes are now live on the public Stall Directory.',
    'logo_url' => $logoRelPath ? (BASE_URL . '/' . $logoRelPath) : null,
]);
