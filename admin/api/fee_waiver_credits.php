<?php
// ============================================================
//  admin/api/fee_waiver_credits.php
//  JSON API for the Fee Waiver Credit workflow — a school-managed,
//  non-wallet misc. credit line on a student's tuition assessment.
//  This never touches CirculationEngine, student_wallets, or
//  system_settings; it has nothing to do with GenCoin.
//
//  Actions (all operate on one student's fee_waiver_credits row):
//    set_amount     empty   -> pending  (finance enters the amount)
//    upload_waiver  pending -> posted   (signed parent waiver upload)
//    cancel         pending -> empty    (full reset)
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json; charset=UTF-8');
gjc_require_role(['finance']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') gjc_csrf_require_json();
gjc_ensure_audit_table($db);
gjc_ensure_fee_waiver_credits_schema($db);

// Finance-tunable from admin/settings.php; falls back to 50,000.00.
$feeWaiverMaxAmount = gjc_setting($db, 'fee_waiver_max_amount');

const FEE_WAIVER_MAX_BYTES    = 5 * 1024 * 1024;
const FEE_WAIVER_ALLOWED_EXT  = ['jpg', 'jpeg', 'png', 'pdf'];
const FEE_WAIVER_ALLOWED_MIMES = ['image/jpeg', 'image/png', 'application/pdf'];

$action    = trim((string) ($_POST['action'] ?? ''));
$adminId   = gjc_user_id();
$adminRole = gjc_current_role();

function fwc_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

/** Fetch one fee_waiver_credits row by student_user_id, or null. */
function fwc_fetch(PDO $db, int $studentUserId): ?array
{
    $stmt = $db->prepare("SELECT * FROM fee_waiver_credits WHERE student_user_id = ? LIMIT 1");
    $stmt->execute([$studentUserId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Append-only transition record — never updated or deleted. */
function fwc_log(PDO $db, int $creditId, string $oldStatus, string $newStatus, ?float $amount, int $userId, string $role): void
{
    $db->prepare(
        "INSERT INTO fee_waiver_credit_logs
            (fee_waiver_credit_id, old_status, new_status, amount, changed_by_user_id, changed_by_role)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$creditId, $oldStatus, $newStatus, $amount, $userId, $role]);
}

try {
    switch ($action) {

        // ── Read-only: the credit + full log history for one student ───
        case 'detail': {
            $studentUserId = (int) ($_POST['student_user_id'] ?? 0);
            if ($studentUserId <= 0) {
                fwc_json(['success' => false, 'message' => 'Student not found.']);
            }

            $credit = gjc_student_waiver_credit($db, $studentUserId);

            $logsStmt = $db->prepare(
                "SELECT l.old_status, l.new_status, l.amount, l.changed_by_role, l.changed_at
                   FROM fee_waiver_credit_logs l
                   JOIN fee_waiver_credits f ON f.id = l.fee_waiver_credit_id
                  WHERE f.student_user_id = ?
                  ORDER BY l.changed_at DESC, l.id DESC"
            );
            $logsStmt->execute([$studentUserId]);

            fwc_json(['success' => true, 'credit' => $credit, 'logs' => $logsStmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        // ── Enter the waiver amount: empty -> pending ───────────
        case 'set_amount': {
            $studentUserId = (int) ($_POST['student_user_id'] ?? 0);
            $amount = round((float) ($_POST['amount'] ?? 0), 2);

            if ($studentUserId <= 0) {
                fwc_json(['success' => false, 'message' => 'Student not found.']);
            }
            if ($amount <= 0 || $amount > $feeWaiverMaxAmount) {
                fwc_json(['success' => false, 'message' => 'Enter a valid amount between ₱0.01 and ₱' . number_format($feeWaiverMaxAmount, 2) . '.']);
            }

            $before = fwc_fetch($db, $studentUserId);
            if (!$before) {
                fwc_json(['success' => false, 'message' => 'No Fee Waiver Credit record exists for this student.']);
            }

            $stmt = $db->prepare(
                "UPDATE fee_waiver_credits
                    SET amount = ?, status = 'pending'
                  WHERE student_user_id = ? AND status = 'empty'"
            );
            $stmt->execute([$amount, $studentUserId]);
            if ($stmt->rowCount() === 0) {
                fwc_json(['success' => false, 'message' => 'This credit is not in the empty state — someone may have already started it.']);
            }

            fwc_log($db, (int) $before['id'], 'empty', 'pending', $amount, $adminId, $adminRole);
            logAudit(
                $db, $adminId, $adminRole, 'FEE_WAIVER_STATUS_CHANGE', 'fee_waiver_credits',
                ['student_user_id' => $studentUserId, 'status' => 'empty'],
                ['student_user_id' => $studentUserId, 'status' => 'pending', 'amount' => $amount],
                null,
                ['target_id' => $studentUserId]
            );

            fwc_json(['success' => true, 'message' => 'Amount recorded. Awaiting the signed waiver upload.', 'status' => 'pending', 'amount' => $amount]);
        }

        // ── Upload the signed waiver: pending -> posted ─────────
        case 'upload_waiver': {
            $studentUserId = (int) ($_POST['student_user_id'] ?? 0);
            $before = $studentUserId > 0 ? fwc_fetch($db, $studentUserId) : null;
            if (!$before || $before['status'] !== 'pending') {
                fwc_json(['success' => false, 'message' => 'This credit is not awaiting a waiver upload.']);
            }

            $file = $_FILES['waiver'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
                fwc_json(['success' => false, 'message' => 'Please choose the signed waiver file to upload.']);
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                fwc_json(['success' => false, 'message' => 'Upload error (code ' . $file['error'] . ').']);
            }
            if ($file['size'] > FEE_WAIVER_MAX_BYTES) {
                fwc_json(['success' => false, 'message' => 'The waiver exceeds the 5 MB limit.']);
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, FEE_WAIVER_ALLOWED_EXT, true)) {
                fwc_json(['success' => false, 'message' => 'The waiver must be a JPG, PNG, or PDF.']);
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!in_array($mime, FEE_WAIVER_ALLOWED_MIMES, true)) {
                fwc_json(['success' => false, 'message' => 'The uploaded file is not a valid image or PDF.']);
            }

            $creditId = (int) $before['id'];
            $tmpDir = BASE_PATH . '/uploads/fee_waiver_credits/tmp_' . bin2hex(random_bytes(8));
            if (!mkdir($tmpDir, 0755, true)) {
                fwc_json(['success' => false, 'message' => 'Could not prepare the upload folder.']);
            }
            $fname = 'waiver_' . time() . mt_rand(1000, 9999) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $tmpDir . '/' . $fname)) {
                @rmdir($tmpDir);
                fwc_json(['success' => false, 'message' => 'Could not save the uploaded waiver.']);
            }

            $finalDir = BASE_PATH . '/uploads/fee_waiver_credits/' . $creditId;
            $rel = 'uploads/fee_waiver_credits/' . $creditId . '/' . $fname;

            $db->beginTransaction();
            try {
                $stmt = $db->prepare(
                    "UPDATE fee_waiver_credits
                        SET status = 'posted', waiver_file = ?
                      WHERE id = ? AND status = 'pending'"
                );
                $stmt->execute([$rel, $creditId]);
                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('This credit is not awaiting a waiver upload.');
                }

                if (!is_dir($finalDir) && !mkdir($finalDir, 0755, true)) {
                    throw new RuntimeException('Could not prepare the upload destination.');
                }
                if (!rename($tmpDir . '/' . $fname, $finalDir . '/' . $fname)) {
                    throw new RuntimeException('Could not move the uploaded waiver into place.');
                }
                @rmdir($tmpDir);

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                @unlink($tmpDir . '/' . $fname);
                @rmdir($tmpDir);
                error_log('[admin/api/fee_waiver_credits.php] upload_waiver: ' . $e->getMessage());
                fwc_json(['success' => false, 'message' => 'The signed waiver could not be saved. Nothing was changed.']);
            }

            fwc_log($db, $creditId, 'pending', 'posted', (float) $before['amount'], $adminId, $adminRole);
            logAudit(
                $db, $adminId, $adminRole, 'FEE_WAIVER_STATUS_CHANGE', 'fee_waiver_credits',
                ['student_user_id' => $studentUserId, 'status' => 'pending'],
                ['student_user_id' => $studentUserId, 'status' => 'posted', 'waiver_file' => $rel],
                null,
                ['target_id' => $studentUserId]
            );

            gjc_notify(
                $db,
                $studentUserId,
                'fee_waiver',
                'Fee Waiver Credit Posted',
                gjc_money_plain((float) $before['amount']) . ' Fee Waiver Credit is now confirmed and on file with finance.',
                'hand-holding-dollar',
                STUDENT_URL . '/profile.php'
            );

            fwc_json(['success' => true, 'message' => 'Signed waiver uploaded. The credit is now posted.', 'status' => 'posted', 'waiver_file' => $rel]);
        }

        // ── Cancel: pending -> empty (full reset) ───────────────
        case 'cancel': {
            $studentUserId = (int) ($_POST['student_user_id'] ?? 0);
            $before = $studentUserId > 0 ? fwc_fetch($db, $studentUserId) : null;
            if (!$before || $before['status'] !== 'pending') {
                fwc_json(['success' => false, 'message' => 'This credit is not pending, so there is nothing to cancel.']);
            }

            $stmt = $db->prepare(
                "UPDATE fee_waiver_credits
                    SET status = 'empty', amount = NULL, waiver_file = NULL
                  WHERE student_user_id = ? AND status = 'pending'"
            );
            $stmt->execute([$studentUserId]);
            if ($stmt->rowCount() === 0) {
                fwc_json(['success' => false, 'message' => 'This credit is not pending, so there is nothing to cancel.']);
            }

            fwc_log($db, (int) $before['id'], 'pending', 'empty', null, $adminId, $adminRole);
            logAudit(
                $db, $adminId, $adminRole, 'FEE_WAIVER_STATUS_CHANGE', 'fee_waiver_credits',
                ['student_user_id' => $studentUserId, 'status' => 'pending', 'amount' => $before['amount']],
                ['student_user_id' => $studentUserId, 'status' => 'empty'],
                null,
                ['target_id' => $studentUserId]
            );

            fwc_json(['success' => true, 'message' => 'Cancelled and reset.', 'status' => 'empty']);
        }

        default:
            fwc_json(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwc_json(['success' => false, 'message' => 'A server error occurred.']);
}
