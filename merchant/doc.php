<?php
// ============================================================
//  merchant/doc.php — Merchant-scoped compliance document
//  Serves one of the permits/clearances the merchant submitted
//  with their stall application, but ONLY to the merchant it
//  belongs to. Same shape as merchant/contract.php: there is no
//  ?f= path parameter — the caller names a *column*, which is
//  matched against a fixed whitelist, and the path is then read
//  from that merchant's own awarded application. A merchant can
//  never reach another stall's documents.
//  Usage: /merchant/doc.php?t=business_permit          (inline)
//         /merchant/doc.php?t=business_permit&dl=1     (download)
// ============================================================
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';

gjc_require_role(['merchant']);
if (gjc_is_merchant_staff()) {
    http_response_code(403);
    exit('Only the stall owner can open these documents.');
}

$currentUser = gjc_current_user($db);
$ownerId     = gjc_merchant_owner_id($db, (int) $currentUser['id']);

if ($ownerId <= 0) {
    http_response_code(404);
    exit('No documents on file.');
}

// Whitelist: the request names a key, never a column or a path.
$documents = [
    'business_permit'  => 'Business-Permit',
    'sanitary_permit'  => 'Sanitary-Permit',
    'clearance'        => 'Barangay-Clearance',
    'gjc_requirements' => 'GJC-Requirements',
];

$key = (string) ($_GET['t'] ?? '');
if (!isset($documents[$key])) {
    http_response_code(400);
    exit('Unknown document.');
}

// The column name is one of our own literals, chosen by the whitelist above —
// it never comes from the request string itself.
$stmt = $db->prepare(
    "SELECT {$key}
       FROM stall_applications
      WHERE merchant_user_id = ?
        AND status = 'awarded'
        AND {$key} IS NOT NULL
        AND {$key} <> ''
      ORDER BY awarded_at DESC, id DESC
      LIMIT 1"
);
$stmt->execute([$ownerId]);
$relPath = (string) $stmt->fetchColumn();

if ($relPath === '') {
    http_response_code(404);
    exit('That document is not on file.');
}

// Defensive path handling (files are written by us, but never trust blindly).
$relPath = ltrim(str_replace('\\', '/', $relPath), '/');
if (strpos($relPath, '..') !== false || strpos($relPath, "\0") !== false
    || !str_starts_with($relPath, 'uploads/')) {
    http_response_code(400);
    exit('Invalid document path.');
}

$baseUploads = realpath(BASE_PATH . '/uploads');
$absPath     = realpath(BASE_PATH . '/' . $relPath);

if (!$baseUploads || !$absPath || !is_file($absPath)
    || strpos($absPath, $baseUploads . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('Document file not found.');
}

// Reference only, never contents — see admin/doc.php.
logAudit(
    $db,
    (int) $currentUser['id'],
    gjc_current_role(),
    'DOCUMENT_VIEW',
    'uploads',
    null,
    [
        'file'          => $relPath,
        'document_type' => $key,
        'bytes'         => filesize($absPath) ?: null,
        'via'           => 'merchant/doc.php',
        'download'      => !empty($_GET['dl']),
    ],
    null,
    ['target_id' => basename($absPath)]
);

$ext  = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'pdf'         => 'application/pdf',
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    default       => 'application/octet-stream',
};

if (ob_get_length()) {
    ob_end_clean();
}

$forceDownload = isset($_GET['dl']);
$disposition   = $forceDownload ? 'attachment' : 'inline';
$downloadName  = 'GenPay-' . $documents[$key] . '.' . ($ext ?: 'pdf');

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absPath));
header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($absPath);
exit;
