<?php
// ============================================================
//  merchant/api/business_profile.php
//  The stall owner's own fixes from the Business Profile page
//  (merchant/settings.php), for things that used to be read-only:
//    action=upload_document  doc_key + file   → a compliance document
//    action=update_contact   mobile + address → the owner's contact details
//  Display name and logo stay in merchant/api/profile.php.
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

function bp_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bp_json(['success' => false, 'message' => 'Use POST.']);
}
if (gjc_is_merchant_staff()) {
    bp_json(['success' => false, 'message' => 'Only the stall owner can change the business profile.']);
}
if (!gjc_csrf_verify($_POST['csrf_token'] ?? null)) {
    bp_json(['success' => false, 'message' => 'Your session expired. Reload the page and try again.']);
}

$currentUser = gjc_current_user($db);
$userId = (int) $currentUser['id'];

$stmt = $db->prepare("SELECT merchantID, stall_id FROM merchant WHERE userID = ? LIMIT 1");
$stmt->execute([$userId]);
$merchant = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$merchant) {
    bp_json(['success' => false, 'message' => 'Merchant record not found.']);
}

$action = trim((string) ($_POST['action'] ?? ''));

try {
    switch ($action) {
        case 'upload_document': {
            $docs = gjc_merchant_compliance_docs();
            $key = (string) ($_POST['doc_key'] ?? '');
            if (!isset($docs[$key])) {
                bp_json(['success' => false, 'message' => 'Unknown document.']);
            }
            $label = $docs[$key][0];

            $file = $_FILES['file'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                bp_json(['success' => false, 'message' => 'Choose a file to upload.']);
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                bp_json(['success' => false, 'message' => 'Upload error (code ' . (int) $file['error'] . ').']);
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                bp_json(['success' => false, 'message' => $label . ' must be 5 MB or smaller.']);
            }
            $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)
                || !in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
                bp_json(['success' => false, 'message' => $label . ' must be a JPG, PNG or PDF file.']);
            }

            gjc_ensure_merchant_documents_schema($db);
            $prevStmt = $db->prepare("SELECT file_path FROM merchant_documents WHERE merchant_user_id = ? AND doc_key = ?");
            $prevStmt->execute([$userId, $key]);
            $previous = (string) ($prevStmt->fetchColumn() ?: '');

            // The record is keyed by an id that already exists (the owner), so
            // the file goes straight into its folder. A timestamped name means a
            // replacement never overwrites the file the old row still points at.
            $dir = 'uploads/merchant_documents/' . $userId;
            if (!is_dir(BASE_PATH . '/' . $dir) && !mkdir(BASE_PATH . '/' . $dir, 0755, true)) {
                throw new RuntimeException('Could not prepare the upload folder.');
            }
            $relPath = $dir . '/' . $key . '_' . date('Ymd_His') . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            if (!move_uploaded_file($file['tmp_name'], BASE_PATH . '/' . $relPath)) {
                throw new RuntimeException('Could not save the uploaded file.');
            }

            $db->prepare(
                "INSERT INTO merchant_documents (merchant_user_id, doc_key, file_path, original_name, uploaded_by, uploaded_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), original_name = VALUES(original_name),
                                         uploaded_by = VALUES(uploaded_by), uploaded_at = VALUES(uploaded_at)"
            )->execute([$userId, $key, $relPath, mb_substr((string) $file['name'], 0, 255), $userId]);

            // Only ever delete an earlier *upload* of ours — never the copy held
            // on the stall application, which finance treats as the original.
            if ($previous !== '' && $previous !== $relPath && str_starts_with($previous, $dir . '/')) {
                @unlink(BASE_PATH . '/' . $previous);
            }

            logAudit(
                $db, $userId, gjc_current_role(), 'MERCHANT_PROFILE_UPDATE', 'merchant_documents',
                ['event' => 'compliance_document_upload', 'document' => $key, 'file' => $previous !== '' ? $previous : null],
                ['event' => 'compliance_document_upload', 'document' => $key, 'file' => $relPath],
                $merchant['stall_id'] ?? null,
                [
                    'target_id' => (int) $merchant['merchantID'],
                    'reason'    => 'Merchant ' . ($previous !== '' ? 'replaced' : 'uploaded') . ' their ' . strtolower($label) . ' on the Business Profile page.',
                ]
            );

            bp_json(['success' => true, 'message' => $label . ' uploaded. The finance office can see it now.']);
        }

        case 'update_contact': {
            $phone = preg_replace('/\D+/', '', (string) ($_POST['contact_number'] ?? ''));
            if ($phone !== '' && !preg_match('/^0?9\d{9}$/', $phone)) {
                bp_json(['success' => false, 'message' => 'Enter an 11-digit mobile number, like 09171234567.']);
            }
            $address = [];
            foreach (['address_barangay' => 'Barangay', 'address_city' => 'City / municipality', 'address_province' => 'Province'] as $col => $label) {
                $value = trim((string) ($_POST[$col] ?? ''));
                if (mb_strlen($value) > 120) {
                    bp_json(['success' => false, 'message' => $label . ' must be 120 characters or fewer.']);
                }
                $address[$col] = $value !== '' ? $value : null;
            }

            gjc_ensure_user_profile_schema($db);
            $columns = gjc_table_columns($db, 'users');
            $values = array_intersect_key(
                ['contact_number' => $phone !== '' ? $phone : '0'] + $address,
                array_flip($columns)
            );

            $oldStmt = $db->prepare('SELECT ' . implode(', ', array_keys($values)) . ' FROM users WHERE userID = ?');
            $oldStmt->execute([$userId]);
            $old = $oldStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $set = implode(', ', array_map(static fn($c) => "{$c} = ?", array_keys($values)));
            $db->prepare("UPDATE users SET {$set} WHERE userID = ?")->execute([...array_values($values), $userId]);

            $changed = array_keys(array_filter($values, static fn($v, $c) => (string) ($old[$c] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                logAudit(
                    $db, $userId, gjc_current_role(), 'MERCHANT_PROFILE_UPDATE', 'users',
                    ['event' => 'contact_update'] + array_intersect_key($old, array_flip($changed)),
                    ['event' => 'contact_update'] + array_intersect_key($values, array_flip($changed)),
                    $merchant['stall_id'] ?? null,
                    [
                        'target_id' => $userId,
                        'reason'    => 'Merchant updated their contact details on the Business Profile page.',
                    ]
                );
            }

            bp_json(['success' => true, 'message' => $changed ? 'Contact details saved.' : 'Nothing changed, so nothing was saved.']);
        }

        default:
            bp_json(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (RuntimeException $e) {
    bp_json(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('business_profile.php: ' . $e->getMessage());
    bp_json(['success' => false, 'message' => 'A server error occurred.']);
}
