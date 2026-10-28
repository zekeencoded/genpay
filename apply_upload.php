<?php
// ============================================================
//  apply_upload.php
//  Eager per-file AJAX upload for the public stall application form
//  (apply.php). Called the moment a visitor attaches a file to one of the
//  5 upload tiles, before the rest of the form is ever submitted, so the
//  attachment is already sitting on the server (session-scoped draft
//  folder) and survives a page refresh or a validation error on some other
//  field. apply.php's final submit renames this same draft folder into the
//  real uploads/stall_applications/<id>/ once the row is inserted.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/connection/config.php";
require_once __DIR__ . "/connection/pdo.php";
require_once __DIR__ . "/connection/app.php";

header('Content-Type: application/json');

// Fired when apply.php's JS detects this is a fresh arrival at the form (not
// a refresh of the form the visitor was already on) — wipes any leftover
// draft from an earlier, abandoned visit before the visitor starts attaching
// documents for what is, from their perspective, a brand-new attempt.
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
