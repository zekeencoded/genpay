<?php
// ============================================================
//  admin/doc.php  â€” Secure document proxy for admin viewing
//  Serves files from /uploads/ only to authenticated admins.
//  Usage: /admin/doc?f=uploads/stall_applications/1/file.pdf
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';

gjc_require_role(['finance', 'student', 'parent']);
$docRole = gjc_current_role();

$relPath = (string) ($_GET['f'] ?? '');

// Sanitise: strip leading slashes, block traversal
$relPath = ltrim(str_replace('\\', '/', $relPath), '/');
if (strpos($relPath, '..') !== false || strpos($relPath, "\0") !== false) {
    http_response_code(400);
    exit('Invalid path.');
}

// Must be inside uploads/
if (!str_starts_with($relPath, 'uploads/')) {
    http_response_code(403);
    exit('Access denied.');
}

// Finance can view anything under uploads/ (existing behaviour). Student and
// parent sessions may ONLY reach their own (or their linked student's) signed
// Fee Waiver Credit — everything else under uploads/ stays 403 for them, since
// this proxy has no per-file ownership model beyond this one carve-out.
if ($docRole !== 'finance') {
    if (!str_starts_with($relPath, 'uploads/fee_waiver_credits/')) {
        http_response_code(403);
        exit('Access denied.');
    }

    gjc_ensure_fee_waiver_credits_schema($db);
    $ownerStmt = $db->prepare("SELECT student_user_id FROM fee_waiver_credits WHERE waiver_file = ? LIMIT 1");
    $ownerStmt->execute([$relPath]);
    $ownerStudentId = (int) ($ownerStmt->fetchColumn() ?: 0);

    $allowed = false;
    if ($ownerStudentId > 0) {
        if ($docRole === 'student') {
            $allowed = $ownerStudentId === gjc_user_id();
        } elseif ($docRole === 'parent') {
            $linkStmt = $db->prepare(
                "SELECT 1 FROM parents p
                   JOIN parent_student_links psl ON psl.parent_id = p.id
                  WHERE p.user_id = ? AND psl.student_user_id = ?
                  LIMIT 1"
            );
            $linkStmt->execute([gjc_user_id(), $ownerStudentId]);
            $allowed = (bool) $linkStmt->fetchColumn();
        }
    }

    if (!$allowed) {
        http_response_code(403);
        exit('Access denied.');
    }
}

$baseUploads = realpath(BASE_PATH . '/uploads');
$absPath = realpath(BASE_PATH . '/' . $relPath);

if (!$baseUploads || !$absPath || !is_file($absPath)) {
    http_response_code(404);
    exit('File not found.');
}

if (strpos($absPath, $baseUploads . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    exit('Access denied.');
}

// Every check has passed and the file exists, so this is a document actually
// being read. Uploads here are permits, clearances and signed fee-waiver
// evidence — real people's documents — and nothing recorded who opened them.
// The reference is logged, never the contents.
logAudit(
    $db,
    gjc_user_id(),
    $docRole,
    'DOCUMENT_VIEW',
    'uploads',
    null,
    [
        'file'       => $relPath,
        'bytes'      => filesize($absPath) ?: null,
        'via'        => 'admin/doc.php',
        'viewer_role' => $docRole,
    ],
    null,
    ['target_id' => basename($absPath)]
);

// Detect MIME
$ext  = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
$mime = match($ext) {
    'pdf'         => 'application/pdf',
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'gif'         => 'image/gif',
    'webp'        => 'image/webp',
    default       => 'application/octet-stream',
};

if (ob_get_length()) {
    ob_end_clean();
}

// For images, wrap in an HTML page so the browser scales them to fit the
// iframe instead of rendering at native (potentially huge) resolution.
if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
    $dataUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($absPath));
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, max-age=3600');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  html,body{width:100%;height:100%;background:#1e1e1e;display:flex;align-items:center;justify-content:center}
  img{max-width:100%;max-height:100vh;object-fit:contain;display:block}
</style></head><body>
<img src="' . $dataUrl . '" alt="' . htmlspecialchars(basename($absPath)) . '">
</body></html>';
    exit;
}

$size = filesize($absPath);

header('Content-Type: '  . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . basename($absPath) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');

readfile($absPath);
exit;
