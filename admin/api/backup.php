<?php

declare(strict_types=1);
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';

if (!isset($_SESSION['userID'], $_SESSION['roleID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthenticated.']);
    exit;
}

$userId = (int) $_SESSION['userID'];
// A backup is a full copy of every wallet, password hash and uploaded permit — super-admin only.
if (gjc_current_role() !== 'finance' || gjc_sub_role() !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') gjc_csrf_require_json();

$body = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : array_merge($_GET, $_POST);
$action = strtolower(trim((string) ($body['action'] ?? '')));
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$currentUser = gjc_current_user($db);
$userName = (string) ($currentUser['name'] ?? '');

try {
    switch ($action) {
        case 'list':
            echo json_encode(['success' => true, 'backups' => gjc_backup_list()]);
            break;

        case 'download':
            $name = (string) ($body['name'] ?? '');
            $path = gjc_backup_path($name);
            $part = ($body['part'] ?? 'zip') === 'pdf' ? 'pdf' : 'zip';
            logAudit($db, $userId, 'finance', 'BACKUP_DOWNLOAD', 'backups', null, ['part' => $part], null, ['target_id' => $name]);
            gjc_backup_log(['action' => 'download', 'name' => $name, 'part' => $part, 'user' => $userId]);

            if ($part === 'pdf') {
                $zip = new ZipArchive();
                $stream = $zip->open($path, ZipArchive::RDONLY) === true ? $zip->getStream('database_report.pdf') : false;
                if (!$stream) throw new RuntimeException('This backup has no PDF report.');
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . basename($name, '.zip') . '.pdf"');
                fpassthru($stream);
                fclose($stream);
                $zip->close();
            } else {
                header('Content-Type: application/zip');
                header('Content-Length: ' . filesize($path));
                header('Content-Disposition: attachment; filename="' . basename($name) . '"');
                readfile($path);
            }
            exit;

        case 'create':
            if (!$isPost) throw new InvalidArgumentException('POST required.');
            $m = gjc_backup_create($db, 'manual', $userId, $userName);
            logAudit($db, $userId, 'finance', 'BACKUP_CREATE', 'backups', null,
                ['size' => $m['size'], 'tables' => count($m['tables']), 'files' => $m['files'], 'pdf' => $m['pdf']],
                null, ['target_id' => $m['name']]);
            gjc_backup_log(['action' => 'create', 'name' => $m['name'], 'user' => $userId, 'pdf' => $m['pdf']]);
            echo json_encode(['success' => true, 'name' => $m['name'], 'pdf' => $m['pdf']]);
            break;

        case 'delete':
            if (!$isPost) throw new InvalidArgumentException('POST required.');
            $name = (string) ($body['name'] ?? '');
            $path = gjc_backup_path($name);
            if (!unlink($path)) throw new RuntimeException('Could not delete the backup.');
            logAudit($db, $userId, 'finance', 'BACKUP_DELETE', 'backups', null, null, null, ['target_id' => $name]);
            gjc_backup_log(['action' => 'delete', 'name' => $name, 'user' => $userId]);
            echo json_encode(['success' => true]);
            break;

        case 'restore':
            if (!$isPost) throw new InvalidArgumentException('POST required.');
            $name = (string) ($body['name'] ?? '');
            gjc_backup_path($name);

            $hash = $db->prepare('SELECT password FROM users WHERE userID = ?');
            $hash->execute([$userId]);
            $okPassword = password_verify((string) ($body['password'] ?? ''), (string) $hash->fetchColumn());
            if (!$okPassword || ($body['confirm'] ?? '') !== 'RESTORE') {
                logAudit($db, $userId, 'finance', 'BACKUP_RESTORE', 'backups', null, null, null,
                    ['target_id' => $name, 'outcome' => 'denied', 'reason' => $okPassword ? 'confirmation phrase missing' : 'wrong password']);
                throw new RuntimeException($okPassword ? 'Type RESTORE to confirm.' : 'Incorrect password.');
            }

            gjc_backup_log(['action' => 'restore_start', 'name' => $name, 'user' => $userId]);
            try {
                $result = gjc_backup_restore($db, $name, $userId, $userName);
            } catch (Throwable $e) {
                gjc_backup_log(['action' => 'restore_failed', 'name' => $name, 'user' => $userId, 'error' => $e->getMessage()]);
                logAudit($db, $userId, 'finance', 'BACKUP_RESTORE', 'backups', null, null, null,
                    ['target_id' => $name, 'outcome' => 'failure', 'reason' => $e->getMessage()]);
                throw $e;
            }
            // Written into the restored audit trail; the entries made before the restore are gone with the old table.
            logAudit($db, $userId, 'finance', 'BACKUP_RESTORE', 'backups', null, $result, null, ['target_id' => $name]);
            gjc_backup_log(['action' => 'restore_done', 'user' => $userId] + $result);
            echo json_encode(['success' => true] + $result);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[backup] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
