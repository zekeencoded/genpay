<?php
/**
 * admin/api/users.php — account-control actions behind the Manage dropdown in
 * admin/users.php. Multi-stage dispatcher shape (references/patterns.md):
 * $_POST + FormData, one `action` switch, JSON out.
 *
 * Suspend and Ban are the two finance-issued lockouts, distinct from the
 * merchant-staff Active/Inactive toggle and from the automatic
 * restricted-product suspension. A suspension is timed (or indefinite) and can
 * expire on its own; a ban is permanent and only ever ends when finance lifts
 * it here. The state lives on users.status ('Suspended' / 'Banned') plus the
 * shared suspension_* columns; everything that reads or reverses it lives in
 * connection/app.php (gjc_suspend_account / gjc_ban_account /
 * gjc_account_suspension / gjc_lift_account_suspension / gjc_lift_account_ban)
 * so the enforcement points and this endpoint can never disagree.
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

/**
 * The target account, or null. Reads roleID/sub_role directly rather than
 * joining `role` — that table only seeds roleIDs 1-3, so a join would report
 * finance (4), merchant staff (6) and parent (7) as unknown and the guard rail
 * below would wave through the very accounts it exists to protect.
 */
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

/**
 * Why this account may not be locked out, or null when it may. Keeps an admin
 * from locking out themselves — or the finance team as a whole — which nobody
 * left inside the system would be able to undo. $verb names the action in the
 * message so the same guard can speak for both Suspend and Ban.
 */
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

/**
 * Queues a new finance staff member's login credentials. Mirrors the guardian
 * credentials mail in admin/maintenance.php, with literal colours because
 * email clients ignore CSS variables. Returns true when queued.
 */
function users_send_staff_credentials(string $email, string $name, string $tempPassword): bool
{
    $loginUrl  = rtrim(BASE_URL, '/') . '/login';
    $safeName  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safePass  = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');

    $body = '
            <div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#f3faf6;padding:28px;border-radius:14px">
                <h2 style="color:#0b4a28;margin-top:0">Your GenPay Finance Staff Account</h2>
                <p style="color:#334155;line-height:1.7">Dear <strong>' . $safeName . '</strong>,</p>
                <p style="color:#334155;line-height:1.7">A GenPay finance staff account has been created for you.</p>
                <div style="background:#052e16;border-radius:10px;padding:16px;margin:16px 0;color:#dcfce7">
                    <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#86efb2;text-transform:uppercase">Login Credentials</p>
                    <p style="margin:0"><strong>Email:</strong> ' . $safeEmail . '</p>
                    <p style="margin:6px 0 0"><strong>Temporary Password:</strong> ' . $safePass . '</p>
                </div>
                <p style="color:#b42318;font-weight:700">You must change this password on first login before accessing your dashboard.</p>
                <p style="color:#334155">Login page: <a href="' . $loginUrl . '" style="color:#147b3a">' . $loginUrl . '</a></p>
                <p style="font-size:12px;color:#64748b">GenPay Team</p>
            </div>';
    $altBody = "Dear {$name},\n\nA GenPay finance staff account has been created for you.\n\nEmail: {$email}\nTemporary Password: {$tempPassword}\n\nLog in at {$loginUrl}. You must change your password on first login.\n\nGenPay Team";

    try {
        return gjc_queue_email($email, $name, 'GenPay - Finance Staff Account Credentials', $body, $altBody);
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
                null, ['target_id' => $userId, 'reason' => 'Student requested password recovery.']
            );
            users_json([
                'success' => true,
                'message' => "Password reset. The temporary password was emailed to {$studentEmail}. The student must change it when they sign in.",
            ]);
        }

        case 'add_finance_staff': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !gjc_csrf_verify()) {
                http_response_code(403);
                users_json(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
            }
            if (gjc_sub_role() !== 'super_admin') {
                users_json(['success' => false, 'message' => 'Only the super admin can add finance staff.']);
            }
            $firstName  = trim((string) ($_POST['first_name'] ?? ''));
            $middleName = trim((string) ($_POST['middle_name'] ?? ''));
            $lastName   = trim((string) ($_POST['last_name'] ?? ''));
            $suffix     = trim((string) ($_POST['suffix'] ?? ''));
            $email     = strtolower(trim((string) ($_POST['email'] ?? '')));
            $contact   = preg_replace('/\D/', '', (string) ($_POST['contact_number'] ?? ''));
            $password  = (string) ($_POST['password'] ?? '');

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

            gjc_ensure_finance_staff_role($db);
            // force_password_change: the staff member must replace the temporary password at first sign-in.
            $db->prepare(
                "INSERT INTO users (last_name, first_name, middle_name, suffix, email, contact_number, roleID, password, profile_img, force_password_change)
                 VALUES (?, ?, ?, ?, ?, ?, 8, ?, '', 1)"
            )->execute([
                $lastName, $firstName,
                $middleName !== '' ? $middleName : null,
                $suffix !== '' ? $suffix : null,
                $email, $contact !== '' ? $contact : '0', password_hash($password, PASSWORD_BCRYPT),
            ]);
            $newId = (int) $db->lastInsertId();
            $fullName = implode(' ', array_filter([$firstName, $middleName, $lastName, $suffix], fn($part) => $part !== ''));

            // Sent after the insert, so a mail failure never undoes the account.
            $emailSent = users_send_staff_credentials($email, $fullName, $password);

            logAudit(
                $db, $adminId, gjc_current_role(), 'USER_ACCOUNT', 'users', null,
                ['event' => 'created', 'user_id' => $newId, 'name' => $fullName, 'email' => $email, 'roleID' => 8, 'sub_role' => 'finance_staff', 'email_sent' => $emailSent],
                null, ['target_id' => $newId]
            );

            users_json([
                'success' => true,
                'message' => $emailSent
                    ? "Finance staff account created for {$fullName}. The login credentials were emailed to {$email}; they must change the password at first sign-in."
                    : "Finance staff account created for {$fullName}, but the credentials email could not be sent. Give them the temporary password yourself; they must change it at first sign-in.",
            ]);
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

            // NULL end date = indefinite. Anything else is a day count, which
            // has to land inside the allowed window.
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

            // gjc_lift_account_suspension() writes the audit entry and the
            // notification itself — shared with the automatic expiry path, so a
            // suspension is always undone the same way.
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

            // A ban has no clock on it, so the only thing standing between a
            // misclick and a permanent lockout is this typed confirmation. The
            // modal collects it; checking it here too means the endpoint can't
            // be driven straight past the warning.
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
                    // Worth recording separately: a ban that replaced a live
                    // suspension is an escalation, not a fresh lockout.
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

            // gjc_lift_account_ban() writes the audit entry and the
            // notification itself, the same way the suspension lift does, so a
            // lockout is always undone through one code path.
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
