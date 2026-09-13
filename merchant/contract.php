<?php
// ============================================================
//  merchant/contract.php — Merchant-scoped contract download
//  Serves the signed lease contract that finance uploaded on
//  award, but ONLY to the merchant it belongs to. No ?f= path
//  param: the file is looked up from the owner's awarded
//  application, so a merchant can never reach another's file.
//  Usage: /merchant/contract.php            (inline view)
//         /merchant/contract.php?dl=1       (force download)
// ============================================================
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';

gjc_require_role(['merchant']);

$currentUser = gjc_current_user($db);
$ownerId     = gjc_merchant_owner_id($db, (int) $currentUser['id']);

if ($ownerId <= 0) {
    http_response_code(404);
    exit('No contract on file.');
}

$stmt = $db->prepare(
    "SELECT contract_file
       FROM stall_applications
      WHERE merchant_user_id = ?
        AND status = 'awarded'
        AND contract_file IS NOT NULL
        AND contract_file <> ''
      ORDER BY awarded_at DESC, id DESC
      LIMIT 1"
);
$stmt->execute([$ownerId]);
$relPath = (string) $stmt->fetchColumn();

if ($relPath === '') {
    http_response_code(404);
    exit('No contract on file.');
}

// Defensive path handling (files are written by us, but never trust blindly).
$relPath = ltrim(str_replace('\\', '/', $relPath), '/');
if (strpos($relPath, '..') !== false || strpos($relPath, "\0") !== false
    || !str_starts_with($relPath, 'uploads/')) {
    http_response_code(400);
    exit('Invalid contract path.');
}

$baseUploads = realpath(BASE_PATH . '/uploads');
$absPath     = realpath(BASE_PATH . '/' . $relPath);

if (!$baseUploads || !$absPath || !is_file($absPath)
    || strpos($absPath, $baseUploads . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('Contract file not found.');
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
        'file'     => $relPath,
        'document_type' => 'signed_lease_contract',
        'bytes'    => filesize($absPath) ?: null,
        'via'      => 'merchant/contract.php',
        'download' => !empty($_GET['dl']),
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
$downloadName  = 'GenPay-Signed-Contract.' . ($ext ?: 'pdf');

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absPath));
header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($absPath);
exit;
