<?php
// ============================================================
//  admin/api/stall_applications.php
//  JSON API for the ONE-STOP stall application flow.
//
//  Submit -> meeting auto-scheduled at submission (see apply.php) ->
//  everything (document verification, contract signing, payment) happens at a
//  single in-person meeting -> Awarded.
//
//  Actions (all operate on a 'pending_verification' application):
//    upload_contract   Save the scanned signed contract (multipart)
//    record_payment    Record 2mo deposit + 1mo advance, start date, schedule
//    award             Finalize: assign stall, create merchant, -> 'awarded'
//                      (requires contract uploaded AND payment recorded)
//    reject            Documents invalid / no-show -> 'rejected' (reason), notify to re-apply
//    mark_viewed       Stamp first_viewed_at on first open (clears the "New" badge)
//    meeting_slots     Slot availability for one date (drives the reschedule picker)
//    reschedule_meeting  Finance moves the auto-assigned meeting; applicant is emailed
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/StallManager.php';
require_once __DIR__ . '/../../connection/audit_logger.php';
require_once __DIR__ . '/../../connection/mailer.php';

header('Content-Type: application/json; charset=UTF-8');
gjc_require_role(['finance']);
gjc_ensure_stall_application_workflow_schema($db);
gjc_ensure_first_login_schema($db);
gjc_ensure_meeting_scheduling_schema($db);

const CONTRACT_MAX_BYTES = 5 * 1024 * 1024;
const CONTRACT_ALLOWED_EXT   = ['pdf', 'jpg', 'jpeg', 'png'];
const CONTRACT_ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

$action  = trim((string) ($_POST['action'] ?? ''));
$adminId = gjc_user_id();

// No-show sweep: any pending_verification application whose meeting time has
// already passed becomes 'expired' before this request's action runs.
gjc_expire_overdue_stall_applications($db, $adminId);

function stall_app_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function stall_app_temp_password(int $length = 10): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

/** Fetch one application row by id, or null. */
function stall_app_fetch(PDO $db, int $appId): ?array
{
    return gjc_stall_application_fetch($db,$appId);
}

/** True when the payment section is fully recorded for an application row. */
function stall_app_payment_complete(array $app): bool
{
    return (float) ($app['deposit_amount'] ?? 0) > 0
        && (float) ($app['advance_amount'] ?? 0) > 0
        && !empty($app['rental_start_date'])
        && in_array((int) ($app['payment_schedule_day'] ?? 0), [15, 30], true);
}

/** First occurrence of the recurring day (15/30) on or after the start date. */
function stall_app_next_due_date(string $startYmd, int $day): string
{
    $mk = static function (int $y, int $m, int $day): string {
        $dim = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
        return sprintf('%04d-%02d-%02d', $y, $m, min($day, $dim));
    };
    $start = new DateTimeImmutable($startYmd);
    $y = (int) $start->format('Y');
    $m = (int) $start->format('n');
    $cand = $mk($y, $m, $day);
    if ($cand >= $startYmd) {
        return $cand;
    }
    $m++;
    if ($m > 12) { $m = 1; $y++; }
    return $mk($y, $m, $day);
}

try {
    switch ($action) {

        // ── Upload the scanned signed contract ─────────────────
        case 'upload_contract': {
            $appId = (int) ($_POST['app_id'] ?? 0);
            $app = $appId ? stall_app_fetch($db, $appId) : null;
            if (!$app || $app['status'] !== 'pending_verification') {
                stall_app_json(['success' => false, 'message' => 'Application not found or not awaiting verification.']);
            }

            $file = $_FILES['contract'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
                stall_app_json(['success' => false, 'message' => 'Please choose the signed contract file to upload.']);
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                stall_app_json(['success' => false, 'message' => 'Upload error (code ' . $file['error'] . ').']);
            }
            if ($file['size'] > CONTRACT_MAX_BYTES) {
                stall_app_json(['success' => false, 'message' => 'Contract exceeds the 5 MB limit.']);
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, CONTRACT_ALLOWED_EXT, true)) {
                stall_app_json(['success' => false, 'message' => 'Contract must be a PDF, JPG, or PNG.']);
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!in_array($mime, CONTRACT_ALLOWED_MIMES, true)) {
                stall_app_json(['success' => false, 'message' => 'The uploaded file is not a valid PDF or image.']);
            }

            $dir = BASE_PATH . '/uploads/stall_applications/' . $appId;
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                stall_app_json(['success' => false, 'message' => 'Could not prepare the upload folder.']);
            }
            $fname = 'contract_' . time() . mt_rand(1000, 9999) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $fname)) {
                stall_app_json(['success' => false, 'message' => 'Could not save the uploaded contract.']);
            }
            // Remove a previously-uploaded contract if the admin re-uploads.
            if (!empty($app['contract_file'])) {
                $old = BASE_PATH . '/' . $app['contract_file'];
                if (is_file($old)) { @unlink($old); }
            }
            $rel = 'uploads/stall_applications/' . $appId . '/' . $fname;
            if(!gjc_stall_application_update($db,$appId,['contract_file'=>$rel,'contract_uploaded_at'=>GJC_APPLICATION_DB_NOW,'contract_uploaded_by'=>$adminId],['status'=>'pending_verification']))throw new RuntimeException('Application is no longer awaiting verification.');

            // The signed contract is the document the award is justified by.
            // Uploading (or silently replacing) one was not recorded anywhere.
            logAudit(
                $db, $adminId, gjc_current_role(), 'STALL_UPDATE', 'stall_applications',
                ['id' => $appId, 'contract_file' => $app['contract_file'] ?: null],
                [
                    'id'            => $appId,
                    'event'         => empty($app['contract_file']) ? 'contract_uploaded' : 'contract_replaced',
                    'contract_file' => $rel,
                    'uploaded_by'   => $adminId,
                ],
                $app['stall_id'] ?? null,
                ['target_id' => $appId]
            );

            stall_app_json(['success' => true, 'message' => 'Signed contract uploaded.', 'contract_file' => $rel]);
        }

        // ── Record payment: 2mo deposit + 1mo advance + schedule ─
        // Always paid in cash at the meeting — there is no method to choose, so
        // the column is written as 'cash' and no reference number is stored.
        case 'record_payment': {
            $appId       = (int) ($_POST['app_id'] ?? 0);
            $deposit     = round((float) ($_POST['deposit_amount'] ?? 0), 2);
            $advance     = round((float) ($_POST['advance_amount'] ?? 0), 2);
            $startDate   = trim((string) ($_POST['rental_start_date'] ?? ''));
            $scheduleDay = (int) ($_POST['payment_schedule_day'] ?? 0);

            $app = $appId ? stall_app_fetch($db, $appId) : null;
            if (!$app || $app['status'] !== 'pending_verification') {
                stall_app_json(['success' => false, 'message' => 'Application not found or not awaiting verification.']);
            }
            if ($deposit <= 0 || $advance <= 0) {
                stall_app_json(['success' => false, 'message' => 'Enter both the 2-month deposit and the 1-month advance.']);
            }
            $d = DateTime::createFromFormat('Y-m-d', $startDate);
            if (!$d || $d->format('Y-m-d') !== $startDate) {
                stall_app_json(['success' => false, 'message' => 'Enter a valid rental start date.']);
            }
            if (!in_array($scheduleDay, [15, 30], true)) {
                stall_app_json(['success' => false, 'message' => 'Choose a payment schedule — every 15th or every 30th.']);
            }

            if(!gjc_stall_application_update($db,$appId,['deposit_amount'=>$deposit,'advance_amount'=>$advance,'rental_start_date'=>$startDate,'payment_schedule_day'=>$scheduleDay,'payment_method'=>'cash','payment_ref_no'=>null],['status'=>'pending_verification']))throw new RuntimeException('Application is no longer awaiting verification.');

            // Money the applicant handed over at the counter. Recording it moved
            // the application forward and left no audit entry at all.
            logAudit(
                $db, $adminId, gjc_current_role(), 'TRANSACTION', 'stall_applications',
                [
                    'id'             => $appId,
                    'deposit_amount' => $app['deposit_amount'] ?? null,
                    'advance_amount' => $app['advance_amount'] ?? null,
                ],
                [
                    'id'                   => $appId,
                    'event'                => 'payment_recorded',
                    'deposit_amount'       => $deposit,
                    'advance_amount'       => $advance,
                    'rental_start_date'    => $startDate,
                    'payment_schedule_day' => $scheduleDay,
                    'payment_method'       => 'cash',
                    'recorded_by'          => $adminId,
                ],
                $app['stall_id'] ?? null,
                ['target_id' => $appId]
            );

            stall_app_json(['success' => true, 'message' => 'Payment recorded.']);
        }

        // ── Award: assign stall, create merchant, finalize -> awarded ─
        case 'award': {
            $appId   = (int) ($_POST['app_id'] ?? 0);
            $stallId = strtoupper(trim((string) ($_POST['stall_id'] ?? '')));

            $app = $appId ? stall_app_fetch($db, $appId) : null;
            if (!$app || $app['status'] !== 'pending_verification') {
                stall_app_json(['success' => false, 'message' => 'Application not found or not awaiting verification.']);
            }
            // Gate: signed contract uploaded AND payment recorded.
            if (empty($app['contract_file'])) {
                stall_app_json(['success' => false, 'message' => 'Upload the signed contract before awarding.']);
            }
            if (!stall_app_payment_complete($app)) {
                stall_app_json(['success' => false, 'message' => 'Record the payment (deposit, advance, start date, schedule, method) before awarding.']);
            }
            if (!preg_match('/^[A-Z]\d+$/', $stallId)) {
                stall_app_json(['success' => false, 'message' => 'Please select a stall to award.']);
            }

            $existing = $db->prepare("SELECT userID FROM users WHERE email = ? LIMIT 1");
            $existing->execute([$app['email']]);
            if ($existing->fetch()) {
                stall_app_json(['success' => false, 'message' => 'A user account already exists for this email.']);
            }

            $tempPassword = stall_app_temp_password();
            $hashedPw = password_hash($tempPassword, PASSWORD_BCRYPT);

            $nameParts = preg_split('/\s+/', trim($app['proprietor_name'])) ?: [];
            $lastName  = array_pop($nameParts) ?: trim($app['proprietor_name']);
            $firstName = trim(implode(' ', $nameParts)) ?: $lastName;
            $oldStall = null;
            $newStall = null;

            $db->beginTransaction();
            try {
                $app=gjc_stall_application_fetch($db,$appId,true);
                if(!$app||$app['status']!=='pending_verification'||empty($app['contract_file'])||!stall_app_payment_complete($app))throw new RuntimeException('APPLICATION_CHANGED');
                // Named columns, not SELECT * — this row is written to the audit
                // log as old_value/new_value, and a whole-row dump silently logs
                // any column added to `stalls` later.
                // Occupancy comes from merchant.stall_id (3NF); same keys as before.
                $stallStmt = $db->prepare(
                    "SELECT s.stall_id, s.label, " . StallManager::statusSql('s', 'm') . " AS status,
                            m.merchantID AS merchant_id, s.monthly_rate, s.pending_expires_at
                       FROM stalls s LEFT JOIN merchant m ON m.stall_id = s.stall_id
                      WHERE s.stall_id = ? FOR UPDATE"
                );
                $stallStmt->execute([$stallId]);
                $oldStall = $stallStmt->fetch(PDO::FETCH_ASSOC);
                if (!$oldStall) {
                    throw new RuntimeException('STALL_NOT_FOUND');
                }
                if ($oldStall['status'] !== 'vacant') {
                    throw new RuntimeException('STALL_TAKEN');
                }

                $db->prepare(
                    "INSERT INTO users
                         (last_name, first_name, email, contact_number,
                          roleID, password, profile_img,
                          force_password_change)
                     VALUES (?, ?, ?, ?, 2, ?, '', 1)"
                )->execute([$lastName, $firstName, $app['email'], $app['contact_number'], $hashedPw]);
                $newUserId = (int) $db->lastInsertId();

                $db->prepare(
                    "INSERT INTO merchant (userID, stall_name, stall_id, operational_status)
                     VALUES (?, ?, ?, 'active')"
                )->execute([$newUserId, $app['business_name'], $stallId]);
                $newMerchantId = (int) $db->lastInsertId();

                // NOTE: the submitted profile picture is a photo of the proprietor (a
                // verification/KYC document) — NOT the business logo. It is deliberately
                // not copied into the public logos dir, otherwise the proprietor's face
                // becomes the storefront logo. New merchants start with no logo
                // (users.profile_img = '') and set their own from Merchant Settings.

                $db->prepare("INSERT IGNORE INTO merchant_wallets (user_id, balance) VALUES (?, 0.00)")
                   ->execute([$newUserId]);

                if (!gjc_claim_stall($db, $stallId, $newMerchantId)) {
                    throw new RuntimeException('STALL_TAKEN');
                }
                $newStall = $oldStall;
                $newStall['status'] = 'occupied';
                $newStall['merchant_id'] = $newMerchantId;
                $newStall['pending_expires_at'] = null;

                // Tenant lease record (payment schedule lives here + on the award row).
                $monthlyRent = (float) ($oldStall['monthly_rate'] ?? 0);
                $scheduleDay = (int) $app['payment_schedule_day'];
                $nextDue = stall_app_next_due_date($app['rental_start_date'], $scheduleDay);
                $newLeaseId = null;
                if (gjc_table_exists($db, 'merchant_leases')) {
                    // stall_number is only filled until the 3NF contract drops it.
                    $legacyStallNumber = gjc_lease_has_stall_number($db);
                    if (in_array('next_due_date', gjc_table_columns($db, 'merchant_leases'), true)) {
                    $db->prepare(
                        "INSERT INTO merchant_leases
                            (merchant_user_id" . ($legacyStallNumber ? ", stall_number" : "") . ", stall_id, stall_name, monthly_rent,
                             deposit_amount, lease_start, lease_end, next_due_date, status, contract_notes, created_by, created_at)
                         VALUES (?, " . ($legacyStallNumber ? "?, " : "") . "?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL 1 YEAR), ?, 'active', ?, ?, NOW())"
                    )->execute([
                        $newUserId, ...($legacyStallNumber ? [$stallId] : []), $stallId, $app['business_name'], $monthlyRent,
                        $app['deposit_amount'], $app['rental_start_date'], $app['rental_start_date'], $nextDue,
                        'Payment every ' . $scheduleDay . 'th. 2-month deposit + 1-month advance collected on award.',
                        $adminId,
                    ]);
                    } else {
                    $db->prepare(
                        "INSERT INTO merchant_leases
                            (merchant_user_id" . ($legacyStallNumber ? ", stall_number" : "") . ", stall_id, stall_name, monthly_rent,
                             deposit_amount, lease_start, lease_end, status, contract_notes, created_by, created_at)
                         VALUES (?, " . ($legacyStallNumber ? "?, " : "") . "?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL 1 YEAR), 'active', ?, ?, NOW())"
                    )->execute([
                        $newUserId, ...($legacyStallNumber ? [$stallId] : []), $stallId, $app['business_name'], $monthlyRent,
                        $app['deposit_amount'], $app['rental_start_date'], $app['rental_start_date'],
                        'Payment every ' . $scheduleDay . 'th. 2-month deposit + 1-month advance collected on award.',
                        $adminId,
                    ]);
                    }
                    // Captured so the MERCHANT_ONBOARDING entry below can name the
                    // lease this award created, not just the user and merchant.
                    $newLeaseId = (int) $db->lastInsertId();
                }

                if(!gjc_stall_application_update($db,$appId,['stall_id'=>$stallId,'status'=>'awarded','awarded_by'=>$adminId,'awarded_at'=>GJC_APPLICATION_DB_NOW,'reviewed_by'=>$adminId,'reviewed_at'=>GJC_APPLICATION_DB_NOW,'merchant_user_id'=>$newUserId],['status'=>'pending_verification']))throw new RuntimeException('APPLICATION_CHANGED');

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[admin/api/stall_applications.php] award: ' . $e->getMessage());
                $msg = match ($e->getMessage()) {
                    'STALL_NOT_FOUND' => 'The selected stall does not exist.',
                    'STALL_TAKEN'     => 'The selected stall was just taken. Please choose a different stall.',
                    default           => 'The stall could not be awarded. Nothing was changed.',
                };
                stall_app_json(['success' => false, 'message' => $msg]);
            }

            logAudit(
                $db, $adminId, gjc_current_role(), 'STALL_UPDATE', 'stalls',
                $oldStall, $newStall, $stallId,
                ['target_id' => $stallId, 'reason' => 'Stall awarded from application #' . $appId . '.']
            );

            // The award also creates a user account, a merchant business row, a
            // wallet and a lease. That is an account coming into existence, not a
            // stall changing status, and none of it was logged — MERCHANT_ONBOARDING
            // existed in the enum for exactly this and had no callers anywhere.
            // Shares this request's correlation_id with the STALL_UPDATE above, so
            // the two read back as one action.
            logAudit(
                $db, $adminId, gjc_current_role(), 'MERCHANT_ONBOARDING', 'users',
                null,
                [
                    'event'                  => 'awarded',
                    'application_id'         => $appId,
                    'new_user_id'            => $newUserId,
                    'merchant_id'            => $newMerchantId,
                    'stall_id'               => $stallId,
                    'business_name'          => $app['business_name'] ?? null,
                    'proprietor_name'        => $app['proprietor_name'] ?? null,
                    'email'                  => $app['email'] ?? null,
                    'monthly_rent'           => $monthlyRent ?? null,
                    'lease_id'               => $newLeaseId ?? null,
                    'roleID'                 => 2,
                    'forced_password_change' => true,
                ],
                $stallId,
                [
                    'target_id' => $newUserId,
                    'reason'    => 'Merchant account created by award of stall ' . $stallId . '.',
                ]
            );

            $body = '
                <div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#f0fdf6;padding:28px;border-radius:14px">
                    <h2 style="color:#0e6332;margin-top:0">Your Merchant Account Is Approved</h2>
                    <p style="color:#374151;line-height:1.7">Dear <strong>' . htmlspecialchars($app['proprietor_name']) . '</strong>,</p>
                    <p style="color:#374151;line-height:1.7">Your stall application has been approved and awarded <strong>Stall ' . htmlspecialchars($stallId) . '</strong>. Your signed contract and payment schedule are available in your merchant account.</p>
                    <div style="background:#052e16;border-radius:10px;padding:16px;margin:16px 0;color:#dcfce9">
                        <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#bbf7d4;text-transform:uppercase">Login Credentials</p>
                        <p style="margin:0"><strong>Email:</strong> ' . htmlspecialchars($app['email']) . '</p>
                        <p style="margin:6px 0 0"><strong>Temporary Password:</strong> ' . htmlspecialchars($tempPassword) . '</p>
                    </div>
                    <p style="color:#b91c1c;font-weight:700">You must change this password on first login before accessing your dashboard.</p>
                    <p style="color:#374151">Login page: <a href="' . BASE_URL . '/login" style="color:#147b3a">' . BASE_URL . '/login</a></p>
                </div>';
            $altBody = "Dear {$app['proprietor_name']},\n\nYour merchant account is approved for Stall {$stallId}.\n\nEmail: {$app['email']}\nTemporary Password: {$tempPassword}\n\nLog in at " . BASE_URL . "/login. You must change your password on first login.\n\nGenPay Team";

            $mailQueued = gjc_queue_email(
                $app['email'],
                $app['proprietor_name'],
                'GenPay - Merchant Account Credentials',
                $body,
                $altBody
            );

            stall_app_json([
                'success' => true,
                'message' => "Application awarded Stall {$stallId}. Merchant account created for {$app['proprietor_name']}."
                    . ($mailQueued ? ' Credentials are being emailed.' : ' Note: credentials email could not be sent.'),
                'status'  => 'awarded',
                'user_id' => $newUserId,
                'mail_sent' => $mailQueued,
            ]);
        }

        // ── Reject: documents invalid at the meeting -> terminated ─
        case 'reject': {
            $appId  = (int) ($_POST['app_id'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $app = $appId ? stall_app_fetch($db, $appId) : null;

            if (!$app || $app['status'] !== 'pending_verification') {
                stall_app_json(['success' => false, 'message' => 'Application not found or not awaiting verification.']);
            }
            if (!$reason) {
                stall_app_json(['success' => false, 'message' => 'A rejection reason is required.']);
            }

            if(!gjc_stall_application_update($db,$appId,['status'=>'rejected','rejection_reason'=>$reason,'reviewed_by'=>$adminId,'reviewed_at'=>GJC_APPLICATION_DB_NOW],['status'=>'pending_verification']))throw new RuntimeException('Application is no longer awaiting verification.');

            // The rejection reason was already required, shown to the applicant
            // and emailed to them — but never written to the audit trail.
            logAudit(
                $db, $adminId, gjc_current_role(), 'STALL_UPDATE', 'stall_applications',
                ['id' => $appId, 'status' => 'pending_verification'],
                [
                    'id'          => $appId,
                    'status'      => 'rejected',
                    'reviewed_by' => $adminId,
                ],
                $app['stall_id'] ?? null,
                ['target_id' => $appId, 'outcome' => 'success', 'reason' => $reason]
            );

            gjc_send_stall_application_termination_email(
                $app,
                'Application Not Approved',
                'After reviewing your documents at the verification meeting, your stall application has not been approved.',
                $reason
            );

            stall_app_json([
                'success' => true,
                'message' => 'Application rejected. The applicant has been notified that they may re-apply.',
                'status'  => 'rejected',
            ]);
        }

        // ── Slot availability for one date (reschedule picker) ──
        case 'meeting_slots': {
            $appId = (int) ($_POST['app_id'] ?? 0);
            $date  = trim((string) ($_POST['date'] ?? ''));
            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                stall_app_json(['success' => false, 'message' => 'Enter a valid date.']);
            }

            $holidays = gjc_meeting_holiday_dates($db);
            // Times already held on that date by any other live application.
            $bookedStmt = $db->prepare(
                "SELECT TIME_FORMAT(meetup_scheduled_at, '%H:%i')
                   FROM " . gjc_stall_application_source($db) . "
                  WHERE meetup_scheduled_at IS NOT NULL
                    AND status NOT IN ('cancelled', 'expired', 'rejected')
                    AND DATE(meetup_scheduled_at) = ?
                    AND id <> ?"
            );
            $bookedStmt->execute([$date, $appId]);

            stall_app_json([
                'success' => true,
                'slots'   => gjc_meeting_time_slots(),
                'booked'  => $bookedStmt->fetchAll(PDO::FETCH_COLUMN),
                'weekend' => (int) $d->format('N') >= 6,
                'holiday' => $holidays[$date] ?? null,
            ]);
        }

        // ── Reschedule: finance moves the system-assigned meeting ─
        case 'reschedule_meeting': {
            $appId = (int) ($_POST['app_id'] ?? 0);
            $date  = trim((string) ($_POST['meeting_date'] ?? ''));
            $time  = trim((string) ($_POST['meeting_time'] ?? ''));

            $app = $appId ? stall_app_fetch($db, $appId) : null;
            if (!$app || $app['status'] !== 'pending_verification') {
                stall_app_json(['success' => false, 'message' => 'Application not found or not awaiting verification.']);
            }
            if (empty($app['meetup_scheduled_at'])) {
                stall_app_json(['success' => false, 'message' => 'This application has no meeting to reschedule.']);
            }
            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                stall_app_json(['success' => false, 'message' => 'Enter a valid meeting date.']);
            }
            if (!in_array($time, gjc_meeting_time_slots(), true)) {
                stall_app_json(['success' => false, 'message' => 'Choose one of the available time slots.']);
            }
            if ((int) $d->format('N') >= 6) {
                stall_app_json(['success' => false, 'message' => 'Meetings cannot be scheduled on weekends.']);
            }
            $holidays = gjc_meeting_holiday_dates($db);
            if (isset($holidays[$date])) {
                stall_app_json(['success' => false, 'message' => "That date is a holiday ({$holidays[$date]})."]);
            }
            $newScheduledAt = $date . ' ' . $time . ':00';
            if ($newScheduledAt <= date('Y-m-d H:i:s')) {
                stall_app_json(['success' => false, 'message' => 'The new schedule must be in the future.']);
            }
            if ($newScheduledAt === $app['meetup_scheduled_at']) {
                stall_app_json(['success' => false, 'message' => 'The meeting is already scheduled at that time.']);
            }

            // Same advisory lock as the auto-scheduler so a concurrent
            // submission can never be handed the slot finance is taking.
            // (stall_app_json exits, which skips finally — respond after release.)
            $slotTaken = false;
            if((int)$db->query("SELECT GET_LOCK('gjc_stall_scheduler',10)")->fetchColumn()!==1)throw new RuntimeException('Meeting scheduler is busy.');
            try {
                $clash = $db->prepare(
                    "SELECT COUNT(*) FROM " . gjc_stall_application_source($db) . "
                      WHERE meetup_scheduled_at = ?
                        AND status NOT IN ('cancelled', 'expired', 'rejected')
                        AND id <> ?"
                );
                $clash->execute([$newScheduledAt, $appId]);
                $slotTaken = (int) $clash->fetchColumn() > 0;
                if (!$slotTaken) {
                    if(!gjc_stall_application_update($db,$appId,['meetup_scheduled_at'=>$newScheduledAt,'meetup_scheduled_email_sent_at'=>GJC_APPLICATION_DB_NOW],['status'=>'pending_verification']))throw new RuntimeException('Application is no longer awaiting verification.');
                }
            } finally {
                $db->prepare("SELECT RELEASE_LOCK('gjc_stall_scheduler')")->execute();
            }
            if ($slotTaken) {
                stall_app_json(['success' => false, 'message' => 'That slot is already taken by another applicant. Pick a different time.']);
            }

            logAudit($db, $adminId, gjc_current_role(), 'STALL_UPDATE', 'stall_applications', [
                'id' => $appId, 'meetup_scheduled_at' => $app['meetup_scheduled_at'],
            ], [
                'id' => $appId, 'meetup_scheduled_at' => $newScheduledAt,
            ], $app['stall_id'] ?? null, ['target_id' => $appId]);

            $mail = gjc_send_stall_meeting_reschedule_email(
                $app['email'],
                $app['proprietor_name'],
                $app['business_name'],
                new DateTime($newScheduledAt),
                new DateTime($app['meetup_scheduled_at']),
                $app['meetup_location'] ?: gjc_meeting_default_location($db)
            );

            stall_app_json([
                'success' => true,
                'message' => 'Meeting rescheduled to ' . (new DateTime($newScheduledAt))->format('M j, Y g:i A') . '.'
                    . ($mail['sent'] ? ' The applicant is being notified by email.' : ' Note: the notification email could not be sent.'),
                'meetup_scheduled_at' => $newScheduledAt,
            ]);
        }

        // ── Mark viewed: first open clears the "New" badge ──────
        case 'mark_viewed': {
            $appId = (int) ($_POST['app_id'] ?? 0);
            if ($appId <= 0) {
                stall_app_json(['success' => false, 'message' => 'Application not found.']);
            }
            gjc_stall_application_update($db,$appId,['first_viewed_at'=>GJC_APPLICATION_DB_NOW,'first_viewed_by'=>$adminId],['first_viewed_at'=>null]);
            stall_app_json(['success' => true]);
        }

        default:
            stall_app_json(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[admin/api/stall_applications.php] ' . $e->getMessage());
    stall_app_json(['success' => false, 'message' => 'A server error occurred. Nothing was changed.']);
}
