<?php
/**
 * Account actions for admin/users.php (Suspend, Ban, lifts, staff). JSON out.
 * Lockout logic lives in connection/app/suspensions.php.
 */
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';
require_once __DIR__ . '/../../connection/student_password_reset.php';

header('Content-Type: application/json');
gjc_require_role(['finance']);
gjc_ensure_account_suspension_schema($db);

/** Hard ceiling on a timed suspension — anything longer should be indefinite. */
const GJC_SUSPEND_MAX_DAYS = 365;

$action  = trim((string) ($_POST['action'] ?? ''));
$adminId = gjc_user_id();

function users_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

/** Target account, or null. Reads roleID directly (the role table only has 1-3). */
function users_fetch(PDO $db, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT userID, first_name, last_name, email, roleID, " . gjc_sub_role_sql() . " AS sub_role, status
           FROM users WHERE userID = ? LIMIT 1"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ? gjc_with_user_suspension($db, $user, $userId) : null;
}

/** Why this account can't be locked out (self or finance staff), or null. */
function users_suspend_guard(array $user, int $adminId, string $verb = 'suspend'): ?string
{
    $past = $verb === 'ban' ? 'banned' : 'suspended';
    if ((int) $user['userID'] === $adminId) {
        return "You cannot {$verb} your own account.";
    }
    if (in_array((int) $user['roleID'], [3, 4], true)) {
        return "Finance accounts cannot be {$past} from this page — revoking finance access is a school-administrator action.";
    }
    if ((string) ($user['sub_role'] ?? '') === 'super_admin') {
        return "The super-admin account cannot be {$past}.";
    }
    return null;
}

/** Roles the super admin may create from Users → Add User: form value => [roleID, label]. */
const USERS_CREATABLE_ROLES = [
    'student'       => [1, 'Student'],
    'merchant'      => [2, 'Merchant'],
    'parent'        => [7, 'Guardian'],
    'faculty'       => [GJC_FACULTY_ROLE_ID, 'Faculty'],
    'finance_staff' => [8, 'Finance Staff'],
];

/** Queue a new account's credentials email. Returns true when queued. */
function users_send_staff_credentials(string $email, string $name, string $tempPassword, string $roleLabel): bool
{
    $loginUrl  = rtrim(BASE_URL, '/') . '/login';
    $safeName  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safePass  = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');

    $body = '
            <div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#f3faf6;padding:28px;border-radius:14px">
                <h2 style="color:#0b4a28;margin-top:0">Your GenPay ' . $roleLabel . ' Account</h2>
                <p style="color:#334155;line-height:1.7">Dear <strong>' . $safeName . '</strong>,</p>
                <p style="color:#334155;line-height:1.7">A GenPay ' . $roleLabel . ' account has been created for you.</p>
                <div style="background:#052e16;border-radius:10px;padding:16px;margin:16px 0;color:#dcfce7">
                    <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#86efb2;text-transform:uppercase">Login Credentials</p>
                    <p style="margin:0"><strong>Email:</strong> ' . $safeEmail . '</p>
                    <p style="margin:6px 0 0"><strong>Temporary Password:</strong> ' . $safePass . '</p>
                </div>
                <p style="color:#b42318;font-weight:700">You must change this password on first login before accessing your dashboard.</p>
                <p style="color:#334155">Login page: <a href="' . $loginUrl . '" style="color:#147b3a">' . $loginUrl . '</a></p>
                <p style="font-size:12px;color:#64748b">GenPay Team</p>
            </div>';
    $altBody = "Dear {$name},\n\nA GenPay {$roleLabel} account has been created for you.\n\nEmail: {$email}\nTemporary Password: {$tempPassword}\n\nLog in at {$loginUrl}. You must change your password on first login.\n\nGenPay Team";

    try {
        return gjc_queue_email($email, $name, "GenPay - {$roleLabel} Account Credentials", $body, $altBody);
    } catch (Throwable $e) {
        error_log('[admin/api/users.php] staff credentials email: ' . $e->getMessage());
        return false;
    }
}

try {
    switch ($action) {

        case 'reset_password': {
            header('Cache-Control: no-store');
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !gjc_csrf_verify()) {
                http_response_code(403);
                users_json(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
            }
            $userId = (int) ($_POST['user_id'] ?? 0);
            if (($_POST['confirm'] ?? '') !== 'RESET') {
                users_json(['success' => false, 'message' => 'Type RESET to confirm.']);
            }
            gjc_ensure_first_login_schema($db);
            $db->beginTransaction();
            try {
                $studentEmail = gjc_reset_student_password_with_email($db, $userId);
            } catch (InvalidArgumentException $e) {
                $db->rollBack();
                users_json(['success' => false, 'message' => $e->getMessage()]);
            }
            $db->commit();
            logAudit(
                $db, $adminId, gjc_current_role(), 'PASSWORD_CHANGE', 'users', null,
                ['event' => 'admin_reset', 'userID' => $userId, 'force_password_change' => 1, 'email_sent' => true],
                null, ['target_id' => $userId, 'reason' => 'Account holder requested password recovery.']
            );
            users_json([
                'success' => true,
                'message' => "Password reset. The temporary password was emailed to {$studentEmail}. They must change it when they sign in.",
            ]);
        }

        case 'add_user': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !gjc_csrf_verify()) {
                http_response_code(403);
                users_json(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
            }
            if (gjc_sub_role() !== 'super_admin') {
                users_json(['success' => false, 'message' => 'Only the super admin can add users.']);
            }
            $firstName  = trim((string) ($_POST['first_name'] ?? ''));
            $middleName = trim((string) ($_POST['middle_name'] ?? ''));
            $lastName   = trim((string) ($_POST['last_name'] ?? ''));
            $suffix     = trim((string) ($_POST['suffix'] ?? ''));
            $email     = strtolower(trim((string) ($_POST['email'] ?? '')));
            $contact   = preg_replace('/\D/', '', (string) ($_POST['contact_number'] ?? ''));
            $password  = (string) ($_POST['password'] ?? '');
            $roleKey   = (string) ($_POST['role'] ?? '');

            if (!isset(USERS_CREATABLE_ROLES[$roleKey])) {
                users_json(['success' => false, 'message' => 'Pick a role for the new account.']);
            }
            [$roleId, $roleLabel] = USERS_CREATABLE_ROLES[$roleKey];

            if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
                users_json(['success' => false, 'message' => 'First name, last name, email and temporary password are required.']);
            }
            if (mb_strlen($firstName) > 60 || mb_strlen($middleName) > 60 || mb_strlen($lastName) > 60) {
                users_json(['success' => false, 'message' => 'Name fields must not exceed 60 characters.']);
            }
            if (mb_strlen($suffix) > 20) {
                users_json(['success' => false, 'message' => 'Suffix must not exceed 20 characters.']);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                users_json(['success' => false, 'message' => 'Enter a valid email address.']);
            }
            if (strlen($contact) > 15) {
                users_json(['success' => false, 'message' => 'Contact number is too long.']);
            }
            if (strlen($password) < 8) {
                users_json(['success' => false, 'message' => 'Temporary password must be at least 8 characters.']);
            }
            $dup = $db->prepare("SELECT userID FROM users WHERE email = ? LIMIT 1");
            $dup->execute([$email]);
            if ($dup->fetch()) {
                users_json(['success' => false, 'message' => 'An account with that email already exists.']);
            }

            // Role-specific fields, validated before anything is written.
            $studentId     = trim((string) ($_POST['student_id_number'] ?? ''));
            $courseId      = (int) ($_POST['course_id'] ?? 0);
            $yearLevel     = (int) ($_POST['year_level'] ?? 0);
            $businessName  = trim((string) ($_POST['business_name'] ?? ''));
            $sex           = strtolower(trim((string) ($_POST['sex'] ?? '')));
            $stallId       = trim((string) ($_POST['stall_id'] ?? ''));
            $linkStudentId = trim((string) ($_POST['link_student_id'] ?? ''));
            $employeeId    = trim((string) ($_POST['employee_id'] ?? ''));
            $department    = trim((string) ($_POST['department'] ?? ''));
            $linkStudentUserId = 0;

            if ($roleKey === 'student') {
                if ($studentId === '' || mb_strlen($studentId) > 80) {
                    users_json(['success' => false, 'message' => 'Enter the Student ID (up to 80 characters).']);
                }
                if (gjc_id_reserved_for_guardian($studentId)) {
                    users_json(['success' => false, 'message' => 'IDs starting with GRD are reserved for guardians.']);
                }
                $chk = $db->prepare("SELECT 1 FROM student_info WHERE studentID = ? LIMIT 1");
                $chk->execute([$studentId]);
                if ($chk->fetchColumn()) {
                    users_json(['success' => false, 'message' => 'That Student ID already exists.']);
                }
                $chk = $db->prepare("SELECT 1 FROM course WHERE courseID = ? AND course_code <> 'FACULTY' LIMIT 1");
                $chk->execute([$courseId]);
                if (!$chk->fetchColumn()) {
                    users_json(['success' => false, 'message' => "Pick the student's course."]);
                }
                if ($yearLevel < 1 || $yearLevel > 12) {
                    users_json(['success' => false, 'message' => 'Year level must be 1–12.']);
                }
            } elseif ($roleKey === 'faculty') {
                if ($employeeId === '' || mb_strlen($employeeId) > 80) {
                    users_json(['success' => false, 'message' => 'Enter the Employee ID (up to 80 characters).']);
                }
                if (gjc_id_reserved_for_guardian($employeeId)) {
                    users_json(['success' => false, 'message' => 'IDs starting with GRD are reserved for guardians.']);
                }
                // Employee IDs share student_info.studentID with Student IDs, so they must not collide.
                $chk = $db->prepare("SELECT 1 FROM student_info WHERE studentID = ? LIMIT 1");
                $chk->execute([$employeeId]);
                if ($chk->fetchColumn()) {
                    users_json(['success' => false, 'message' => 'That ID is already used by another student or faculty account.']);
                }
                if ($department === '' || mb_strlen($department) > 100) {
                    users_json(['success' => false, 'message' => 'Enter the department (up to 100 characters).']);
                }
            } elseif ($roleKey === 'merchant') {
                if ($businessName === '' || mb_strlen($businessName) > 255) {
                    users_json(['success' => false, 'message' => 'Enter the business name (up to 255 characters).']);
                }
                if (!in_array($sex, ['male', 'female'], true)) {
                    users_json(['success' => false, 'message' => "Select the proprietor's sex."]);
                }
            } elseif ($roleKey === 'parent' && $linkStudentId !== '') {
                $chk = $db->prepare("SELECT userID FROM student_info WHERE studentID = ? LIMIT 1");
                $chk->execute([$linkStudentId]);
                $linkStudentUserId = (int) $chk->fetchColumn();
                if ($linkStudentUserId === 0) {
                    users_json(['success' => false, 'message' => 'No student has that Student ID to link.']);
                }
            }

            $fullName = implode(' ', array_filter([$firstName, $middleName, $lastName, $suffix], fn($part) => $part !== ''));
            gjc_ensure_finance_staff_role($db);
            if ($roleKey === 'parent') {
                gjc_ensure_parent_schema($db);
            }
            if ($roleKey === 'faculty') {
                gjc_ensure_faculty_schema($db);
                $facultyCourseId = gjc_faculty_course_id($db);
            }

            // A failure anywhere below is rolled back by the outer catch.
            $db->beginTransaction();
            // force_password_change: the new user must replace the temporary password at first sign-in.
            $db->prepare(
                "INSERT INTO users (last_name, first_name, middle_name, suffix, email, contact_number, roleID, password, profile_img, force_password_change)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', 1)"
            )->execute([
                $lastName, $firstName,
                $middleName !== '' ? $middleName : null,
                $suffix !== '' ? $suffix : null,
                $email, $contact !== '' ? $contact : '0', $roleId, password_hash($password, PASSWORD_BCRYPT),
            ]);
            $newId = (int) $db->lastInsertId();

            // Same records Maintenance creates for an imported student, a bypass merchant or a guardian.
            if ($roleKey === 'student') {
                [$yrCols, $yrMarks, $yrCount] = gjc_student_entry_year_write_columns($db);
                $db->prepare("INSERT INTO student_info (userID, studentID, {$yrCols}, courseID) VALUES (?, ?, {$yrMarks}, ?)")
                   ->execute(array_merge([$newId, $studentId], array_fill(0, $yrCount, (string) $yearLevel), [$courseId]));
                gjc_student_wallet($db, $newId);
                $db->prepare("INSERT IGNORE INTO fee_waiver_credits (student_user_id, status) VALUES (?, 'empty')")->execute([$newId]);
            } elseif ($roleKey === 'faculty') {
                // No fee_waiver_credits row: fee waivers are for students only.
                [$yrCols, $yrMarks, $yrCount] = gjc_student_entry_year_write_columns($db);
                $db->prepare("INSERT INTO student_info (userID, studentID, {$yrCols}, courseID, department) VALUES (?, ?, {$yrMarks}, ?, ?)")
                   ->execute(array_merge([$newId, $employeeId], array_fill(0, $yrCount, ''), [$facultyCourseId, $department]));
                gjc_student_wallet($db, $newId);
            } elseif ($roleKey === 'merchant') {
                $merchantId = gjc_insert_merchant_record($db, $newId, $businessName, '', $stallId !== '' ? $stallId : null);
                gjc_merchant_wallet($db, $newId);
                if ($stallId !== '' && !gjc_claim_stall($db, $stallId, $merchantId)) {
                    throw new RuntimeException('That stall is no longer available — pick another.');
                }
                gjc_insert_awarded_merchant_application($db, [
                    'business_name' => $businessName, 'first_name' => $firstName, 'middle_name' => $middleName,
                    'last_name' => $lastName, 'suffix' => $suffix, 'sex' => $sex, 'phone' => $contact,
                    'email' => $email, 'stall_id' => $stallId,
                ], $newId, $adminId);
            } elseif ($roleKey === 'parent') {
                $db->prepare("INSERT IGNORE INTO parents (user_id) VALUES (?)")->execute([$newId]);
                if ($linkStudentUserId > 0) {
                    $db->prepare(
                        "INSERT IGNORE INTO parent_student_links (parent_id, student_user_id)
                         SELECT id, ? FROM parents WHERE user_id = ?"
                    )->execute([$linkStudentUserId, $newId]);
                }
            }
            $db->commit();

            // Sent after the insert, so a mail failure never undoes the account.
            $emailSent = users_send_staff_credentials($email, $fullName, $password, $roleLabel);

            logAudit(
                $db, $adminId, gjc_current_role(), 'USER_ACCOUNT', 'users', null,
                ['event' => 'created', 'user_id' => $newId, 'name' => $fullName, 'email' => $email, 'roleID' => $roleId, 'sub_role' => gjc_sub_role_map()[$roleId], 'email_sent' => $emailSent],
                null, ['target_id' => $newId]
            );

            users_json([
                'success' => true,
                'message' => $emailSent
                    ? "{$roleLabel} account created for {$fullName}. The login credentials were emailed to {$email}; they must change the password at first sign-in."
                    : "{$roleLabel} account created for {$fullName}, but the credentials email could not be sent. Give them the temporary password yourself; they must change it at first sign-in.",
            ]);
        }

        case 'update_faculty': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !gjc_csrf_verify()) {
                http_response_code(403);
                users_json(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
            }
            if (gjc_sub_role() !== 'super_admin') {
                users_json(['success' => false, 'message' => 'Only the super admin can edit faculty details.']);
            }
            $userId     = (int) ($_POST['user_id'] ?? 0);
            $employeeId = trim((string) ($_POST['employee_id'] ?? ''));
            $department = trim((string) ($_POST['department'] ?? ''));

            $user = users_fetch($db, $userId);
            if (!$user || (int) $user['roleID'] !== GJC_FACULTY_ROLE_ID) {
                users_json(['success' => false, 'message' => 'Faculty account not found.']);
            }
            // Same rules as add_user.
            if ($employeeId === '' || mb_strlen($employeeId) > 80) {
                users_json(['success' => false, 'message' => 'Enter the Employee ID (up to 80 characters).']);
            }
            if (gjc_id_reserved_for_guardian($employeeId)) {
                users_json(['success' => false, 'message' => 'IDs starting with GRD are reserved for guardians.']);
            }
            if ($department === '' || mb_strlen($department) > 100) {
                users_json(['success' => false, 'message' => 'Enter the department (up to 100 characters).']);
            }
            gjc_ensure_faculty_schema($db);
            $old = $db->prepare("SELECT studentID, department FROM student_info WHERE userID = ? LIMIT 1");
            $old->execute([$userId]);
            $before = $old->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                users_json(['success' => false, 'message' => 'This faculty account has no details on record.']);
            }
            // Employee IDs share student_info.studentID with Student IDs, so they must not collide.
            $chk = $db->prepare("SELECT 1 FROM student_info WHERE studentID = ? AND userID <> ? LIMIT 1");
            $chk->execute([$employeeId, $userId]);
            if ($chk->fetchColumn()) {
                users_json(['success' => false, 'message' => 'That ID is already used by another student or faculty account.']);
            }
            if ($before['studentID'] === $employeeId && (string) $before['department'] === $department) {
                users_json(['success' => true, 'message' => 'Nothing changed.']);
            }

            $db->prepare("UPDATE student_info SET studentID = ?, department = ? WHERE userID = ?")
               ->execute([$employeeId, $department, $userId]);
            logAudit(
                $db, $adminId, gjc_current_role(), 'USER_ACCOUNT', 'student_info',
                ['employee_id' => $before['studentID'], 'department' => $before['department']],
                ['event' => 'faculty_updated', 'user_id' => $userId, 'employee_id' => $employeeId, 'department' => $department],
                null, ['target_id' => $userId]
            );
            users_json(['success' => true, 'message' => 'Faculty details saved.']);
        }

        case 'offboard_faculty':
        case 'reinstate_faculty': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !gjc_csrf_verify()) {
                http_response_code(403);
                users_json(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
            }
            if (gjc_sub_role() !== 'super_admin') {
                users_json(['success' => false, 'message' => 'Only the super admin can offboard or reinstate faculty.']);
            }
            $userId = (int) ($_POST['user_id'] ?? 0);
            $user = users_fetch($db, $userId);
            if (!$user || (int) $user['roleID'] !== GJC_FACULTY_ROLE_ID) {
                users_json(['success' => false, 'message' => 'Faculty account not found.']);
            }
            gjc_ensure_school_year_schema($db); // adds student_info.graduated_at if missing
            $offboard = $action === 'offboard_faculty';
            // Only flips when the state actually changes, so a double click logs once.
            $stmt = $db->prepare($offboard
                ? "UPDATE student_info SET graduated_at = NOW() WHERE userID = ? AND graduated_at IS NULL"
                : "UPDATE student_info SET graduated_at = NULL WHERE userID = ? AND graduated_at IS NOT NULL");
            $stmt->execute([$userId]);
            if ($stmt->rowCount() === 0) {
                users_json(['success' => false, 'message' => $offboard ? 'This faculty member is already offboarded.' : 'This faculty member is not offboarded.']);
            }
            logAudit(
                $db, $adminId, gjc_current_role(), 'USER_ACCOUNT', 'student_info',
                ['userID' => $userId, 'left_school' => !$offboard],
                ['event' => $offboard ? 'faculty_offboarded' : 'faculty_reinstated', 'userID' => $userId, 'left_school' => $offboard],
                null, ['target_id' => $userId]
            );
            users_json(['success' => true, 'message' => $offboard ? 'Faculty member offboarded.' : 'Faculty member reinstated.']);
        }

        case 'suspend': {
            $userId   = (int) ($_POST['user_id'] ?? 0);
            $duration = trim((string) ($_POST['duration'] ?? ''));
            $reason   = trim((string) ($_POST['reason'] ?? ''));

            $user = users_fetch($db, $userId);
            if (!$user) {
                users_json(['success' => false, 'message' => 'That user no longer exists.']);
            }
            if (($guard = users_suspend_guard($user, $adminId)) !== null) {
                users_json(['success' => false, 'message' => $guard]);
            }
            if (mb_strlen($reason) < 5) {
                users_json(['success' => false, 'message' => 'Give a reason of at least 5 characters — the user sees it, and it goes into the audit trail.']);
            }

            // NULL = indefinite; otherwise a day count within the allowed range.
            $until = null;
            if ($duration !== 'indefinite') {
                $days = filter_var($duration, FILTER_VALIDATE_INT);
                if ($days === false || $days < 1 || $days > GJC_SUSPEND_MAX_DAYS) {
                    users_json(['success' => false, 'message' => 'Pick a duration between 1 and ' . GJC_SUSPEND_MAX_DAYS . ' days, or Indefinite.']);
                }
                $until = date('Y-m-d H:i:s', strtotime("+{$days} days"));
            }

            if (!gjc_suspend_account($db, $userId, $until, $reason, $adminId)) {
                users_json(['success' => false, 'message' => 'That account is already suspended.']);
            }

            $untilLabel = $until !== null ? date('M d, Y g:i A', strtotime($until)) : 'further notice';
            $name = trim($user['first_name'] . ' ' . $user['last_name']);

            logAudit(
                $db, $adminId, gjc_current_role(),
                'USER_ACCOUNT', 'users',
                ['userID' => $userId, 'status' => (string) $user['status']],
                [
                    'event'           => 'suspended',
                    'userID'          => $userId,
                    'status'          => 'Suspended',
                    'suspended_until' => $until,
                    'indefinite'      => $until === null,
                    'reason'          => $reason,
                    'suspended_by'    => $adminId,
                ],
                null,
                ['target_id' => $userId, 'reason' => $reason]
            );

            gjc_notify(
                $db, $userId, 'compliance', 'Your account has been suspended',
                gjc_suspension_notice(['until' => $until, 'reason' => $reason], 'Your GenPay account'),
                'triangle-exclamation'
            );

            users_json([
                'success' => true,
                'message' => "{$name} suspended until {$untilLabel}. Any signed-in session ends on their next click.",
            ]);
        }

        case 'lift_suspension': {
            $userId = (int) ($_POST['user_id'] ?? 0);

            $user = users_fetch($db, $userId);
            if (!$user) {
                users_json(['success' => false, 'message' => 'That user no longer exists.']);
            }

            // Writes its own audit entry and notification.
            if (!gjc_lift_account_suspension($db, $userId, $adminId)) {
                users_json(['success' => false, 'message' => 'That account is not currently suspended.']);
            }

            $name = trim($user['first_name'] . ' ' . $user['last_name']);
            users_json(['success' => true, 'message' => "Suspension lifted. {$name} can sign in again."]);
        }

        case 'ban': {
            $userId  = (int) ($_POST['user_id'] ?? 0);
            $reason  = trim((string) ($_POST['reason'] ?? ''));
            $confirm = trim((string) ($_POST['confirm'] ?? ''));

            $user = users_fetch($db, $userId);
            if (!$user) {
                users_json(['success' => false, 'message' => 'That user no longer exists.']);
            }
            if (($guard = users_suspend_guard($user, $adminId, 'ban')) !== null) {
                users_json(['success' => false, 'message' => $guard]);
            }
            if (mb_strlen($reason) < 5) {
                users_json(['success' => false, 'message' => 'Give a reason of at least 5 characters — the user sees it, and it goes into the audit trail.']);
            }

            // Server-side check of the typed BAN confirmation.
            if (strcasecmp($confirm, 'BAN') !== 0) {
                users_json(['success' => false, 'message' => 'Type BAN in the confirmation box to continue.']);
            }

            $wasSuspended = (string) $user['status'] === 'Suspended';

            if (!gjc_ban_account($db, $userId, $reason, $adminId)) {
                users_json(['success' => false, 'message' => 'That account is already banned.']);
            }

            $name = trim($user['first_name'] . ' ' . $user['last_name']);

            logAudit(
                $db, $adminId, gjc_current_role(),
                'USER_ACCOUNT', 'users',
                ['userID' => $userId, 'status' => (string) $user['status']],
                [
                    'event'     => 'banned',
                    'userID'    => $userId,
                    'status'    => 'Banned',
                    'permanent' => true,
                    'escalated_from_suspension' => $wasSuspended,
                    'reason'    => $reason,
                    'banned_by' => $adminId,
                ],
                null,
                ['target_id' => $userId, 'reason' => $reason]
            );

            gjc_notify(
                $db, $userId, 'compliance', 'Your account has been banned',
                gjc_suspension_notice(['kind' => 'ban', 'reason' => $reason], 'Your GenPay account'),
                'ban'
            );

            users_json([
                'success' => true,
                'message' => "{$name} has been permanently banned. Any signed-in session ends on their next click.",
            ]);
        }

        case 'lift_ban': {
            $userId = (int) ($_POST['user_id'] ?? 0);

            $user = users_fetch($db, $userId);
            if (!$user) {
                users_json(['success' => false, 'message' => 'That user no longer exists.']);
            }

            // Writes its own audit entry and notification.
            if (!gjc_lift_account_ban($db, $userId, $adminId)) {
                users_json(['success' => false, 'message' => 'That account is not currently banned.']);
            }

            $name = trim($user['first_name'] . ' ' . $user['last_name']);
            users_json(['success' => true, 'message' => "Ban lifted. {$name} can sign in again."]);
        }

        default:
            users_json(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (RuntimeException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    users_json(['success' => false, 'message' => gjc_safe_error($e, 'admin/api/users')]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[admin/api/users.php] ' . $e->getMessage());
    users_json(['success' => false, 'message' => 'A server error occurred.']);
}
