<?php
// ============================================================
//  api/profile_photo.php
//  AJAX endpoint — upload / remove a profile photo for the
//  currently logged-in user (any role).
// ============================================================
session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

header('Content-Type: application/json; charset=UTF-8');

$userId = gjc_user_id();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') gjc_csrf_require_json();

$action = trim((string) ($_POST['action'] ?? 'upload'));
$photoDir = BASE_PATH . '/assets/profile_photos';

if (!is_dir($photoDir)) {
    mkdir($photoDir, 0755, true);
}

// ── REMOVE ─────────────────────────────────────────────────────────────────
if ($action === 'remove') {
    $idCol = gjc_column($db, 'users', ['id', 'userID']);
    if ($idCol) {
        // Delete physical file(s) for this user
        foreach (glob($photoDir . '/' . $userId . '.*') ?: [] as $old) {
            @unlink($old);
        }
        $db->prepare("UPDATE users SET profile_img = '' WHERE {$idCol} = ?")->execute([$userId]);
    }
    echo json_encode(['success' => true, 'photo_url' => null]);
    exit;
}

// ── UPLOAD ─────────────────────────────────────────────────────────────────
if (empty($_FILES['photo']['name']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'error' => 'No file received.']);
    exit;
}

$file = $_FILES['photo'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
    ];
    echo json_encode(['success' => false, 'error' => $errMap[$file['error']] ?? 'Upload error.']);
    exit;
}

if ($file['size'] > 2 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Photo must be 2 MB or smaller.']);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
$allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowedMimes[$mime])) {
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, or WebP images are allowed.']);
    exit;
}

$ext = $allowedMimes[$mime];

// Remove any previous photo for this user regardless of extension
foreach (glob($photoDir . '/' . $userId . '.*') ?: [] as $old) {
    @unlink($old);
}

$relPath  = 'assets/profile_photos/' . $userId . '.' . $ext;
$fullPath = BASE_PATH . '/' . $relPath;

if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save photo. Check folder permissions.']);
    exit;
}

$idCol = gjc_column($db, 'users', ['id', 'userID']);
if ($idCol) {
    $db->prepare("UPDATE users SET profile_img = ? WHERE {$idCol} = ?")->execute([$relPath, $userId]);
}

echo json_encode([
    'success'   => true,
    'photo_url' => BASE_URL . '/' . $relPath . '?v=' . time(),
]);
