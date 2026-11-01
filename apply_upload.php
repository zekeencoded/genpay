<?php
// Immediate per-file upload for apply.php into the session's draft folder.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/connection/config.php";
require_once __DIR__ . "/connection/pdo.php";
require_once __DIR__ . "/connection/app.php";

header('Content-Type: application/json');

// Fresh visit: clear the old draft.
if (($_POST['action'] ?? '') === 'clear') {
    gjc_clear_stall_application_draft();
    echo json_encode(['success' => true]);
    exit;
}

$field = trim((string) ($_POST['field'] ?? ''));
$rules = gjc_stall_application_upload_rules();

if (!isset($rules[$field])) {
    echo json_encode(['success' => false, 'message' => 'Unknown field.']);
    exit;
}

$result = gjc_stage_stall_application_upload($db, $field, $_FILES['file'] ?? null, $rules[$field]);
echo json_encode($result);
