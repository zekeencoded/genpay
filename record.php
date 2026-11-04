<?php

namespace Classes;



require_once __DIR__ . '/connection/config.php';
require_once __DIR__ . '/connection/pdo.php';
require_once __DIR__ . '/connection/app.php';
require_once __DIR__ . '/connection/audit_logger.php';

class Record
{
    /** Failed sign-ins from one network address per 15 minutes before it is paused. */
    private const IP_FAILURE_LIMIT = 100;

    private $con;

    public function __construct($db){
        $this->con = $db;
    }

    
    public function loginUser() {
        if (isset($_POST['login'])) {
            \gjc_ensure_first_login_schema($this->con);
            

            $email = trim((string) ($_POST['email'] ?? '')); // email or student ID
            $password = $_POST['password'] ?? '';

            // Lock after 5 failures in 15 minutes (checked for every identifier, so nothing leaks).
            $throttleKey = hash('sha256', strtolower($email));
            // Also throttle per IP (high limit; campus shares IPs). REMOTE_ADDR only.
            $ipKey = \gjc_throttle_key('ip', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            if (\gjc_login_throttled($this->con, $throttleKey)
                || \gjc_login_throttled($this->con, $ipKey, self::IP_FAILURE_LIMIT)) {
                return 'Too many failed sign-in attempts. Try again in 15 minutes.';
            }

            $stmt = $this->con->prepare(
                "SELECT * FROM users
                  WHERE email = ?
                     OR userID = (SELECT userID FROM student_info WHERE studentID = ? LIMIT 1)
                  ORDER BY email = ? DESC
                  LIMIT 1"
            );
            $stmt->execute([$email, $email, $email]);

            if ($stmt->rowCount()) {
                $user = $stmt->fetch();

                // Email and student ID both reach this account, so failures are also
                // counted per account: five in total, not five for each way of typing it.
                $accountKey = \gjc_throttle_key('account', (string) (int) ($user['id'] ?? $user['userID'] ?? 0));
                if (\gjc_login_throttled($this->con, $accountKey)) {
                    return 'Too many failed sign-in attempts. Try again in 15 minutes.';
                }

                $storedPassword = (string) ($user['password'] ?? '');
                // bcrypt only; don't reinstate the plaintext fallback.
                $validPassword = password_verify($password, $storedPassword);

                if ($validPassword) {
                    \gjc_clear_login_failures($this->con, $throttleKey);
                    \gjc_clear_login_failures($this->con, $accountKey);
                    // Log blocked logins with a correct password.
                    $blockedUserId = (int) ($user['id'] ?? $user['userID'] ?? 0);

                    if (!empty($user['status']) && $user['status'] === 'Inactive') {
                        \logAudit(
                            $this->con,
                            $blockedUserId,
                            \gjc_audit_role_from_user($this->con, $blockedUserId),
                            'LOGIN_BLOCKED',
                            'users',
                            null,
                            [
                                'userID' => $blockedUserId,
                                'email'  => $user['email'] ?? '',
                                'block'  => 'account_deactivated',
                            ],
                            null,
                            [
                                'target_id' => $blockedUserId,
                                'outcome'   => 'denied',
                                'reason'    => 'Credentials correct, but the account is deactivated.',
                            ]
                        );
                        return 'Access Denied: Your account has been deactivated. Please contact your merchant admin.';
                    }

                    // A stall auto-suspended over restricted-product strikes blocks
                    // the owner and every staff account for the duration.
                    $suspendedUntil = \gjc_merchant_suspended_until($this->con, (int) ($user['id'] ?? $user['userID'] ?? 0));
                    if ($suspendedUntil !== null) {
                        \logAudit(
                            $this->con,
                            $blockedUserId,
                            \gjc_audit_role_from_user($this->con, $blockedUserId),
                            'LOGIN_BLOCKED',
                            'users',
                            null,
                            [
                                'userID'          => $blockedUserId,
                                'email'           => $user['email'] ?? '',
                                'block'           => 'merchant_suspended',
                                'suspended_until' => $suspendedUntil,
                            ],
                            null,
                            [
                                'target_id' => $blockedUserId,
                                'outcome'   => 'denied',
                                'reason'    => 'Merchant stall suspended over restricted-product strikes until ' . $suspendedUntil . '.',
                            ]
                        );
                        return 'Access Denied: This merchant account is temporarily suspended until '
                            . date('M d, Y g:i A', strtotime($suspendedUntil))
                            . ' due to repeated attempts to list restricted products.';
                    }

                    // A finance-issued suspension (admin/users.php) blocks every
                    // role, and merchant staff go down with their owner's stall.
                    $accountSuspension = \gjc_account_suspension(
                        $this->con,
                        (int) ($user['id'] ?? $user['userID'] ?? 0),
                        in_array((int) ($user['roleID'] ?? 0), [2, 6], true)
                    );
                    if ($accountSuspension !== null) {
                        \logAudit(
                            $this->con,
                            $blockedUserId,
                            \gjc_audit_role_from_user($this->con, $blockedUserId),
                            'LOGIN_BLOCKED',
                            'users',
                            null,
                            [
                                'userID'          => $blockedUserId,
                                'email'           => $user['email'] ?? '',
                                'block'           => ($accountSuspension['kind'] ?? '') === 'ban' ? 'account_banned' : 'account_suspended',
                                'suspended_until' => $accountSuspension['until'] ?? null,
                            ],
                            null,
                            [
                                'target_id' => $blockedUserId,
                                'outcome'   => 'denied',
                                'reason'    => (string) ($accountSuspension['reason'] ?? '') !== ''
                                    ? (string) $accountSuspension['reason']
                                    : 'Account locked by finance.',
                            ]
                        );
                        return 'Access Denied: ' . \gjc_suspension_notice($accountSuspension, 'Your account');
                    }

                    $userId = (int) ($user['id'] ?? $user['userID'] ?? 0);
                    $roleId = (int) ($user['roleID'] ?? 0);

                    // Session vars (userID, roleID, sub_role, role, ...) —
                    // shared with the remember-me auto-login in app.php.
                    \gjc_establish_login_session($user);
                    $subRole = (string) $_SESSION['sub_role'];

                    if (in_array($roleId, [1, \GJC_FACULTY_ROLE_ID], true)) {
                        \gjc_notify_welcome_if_new($this->con, $userId);
                    }

                    $mustChangePassword =
                        !empty($user['force_password_change']);

                    // Remember me: 30-day token (not during a forced password change).
                    if (!empty($_POST['remember']) && !$mustChangePassword) {
                        \gjc_issue_remember_token($this->con, $userId);
                    } else {
                        \gjc_clear_remember_token($this->con);
                    }

                    \logAudit(
                        $this->con,
                        $userId,
                        $_SESSION['role'],
                        'LOGIN',
                        'users',
                        null,
                        [
                            'userID' => $userId,
                            'email' => $user['email'] ?? '',
                            'roleID' => $roleId,
                            'sub_role' => $subRole,
                        ],
                        null,
                        ['target_id' => $userId]
                    );

                    if ($mustChangePassword) {
                        $_SESSION['force_change'] = true;
                        header('Location: ' . BASE_URL . '/change_password.php');
                        exit;
                    }

                    header('Location: ' . BASE_URL . '/dashboard.php');
                    exit;
                } else {
                    $failedUserId = (int) ($user['id'] ?? $user['userID'] ?? 0);
                    $failedRoleId = (int) ($user['roleID'] ?? 0);
                    \logAudit(
                        $this->con,
                        $failedUserId,
                        \gjc_audit_role_from_user($this->con, $failedUserId),
                        'LOGIN_FAILED',
                        'users',
                        null,
                        [
                            'userID' => $failedUserId,
                            'email' => $user['email'] ?? '',
                            'roleID' => $failedRoleId,
                        ],
                        null,
                        [
                            'target_id' => $failedUserId,
                            'outcome'   => 'failure',
                            'reason'    => 'Incorrect password.',
                        ]
                    );
                    \gjc_record_login_failure($this->con, $throttleKey);
                    \gjc_record_login_failure($this->con, $accountKey);
                    \gjc_record_login_failure($this->con, $ipKey);
                    // One message for a wrong password and an unknown account, so the form
                    // does not reveal which emails or student IDs are registered.
                    return "Invalid email/ID or password.";
                }
            } else {
                // Unknown account: log as Anonymous; identifier capped at 100 chars.
                \logAudit(
                    $this->con,
                    0,
                    '',
                    'LOGIN_FAILED',
                    'users',
                    null,
                    [
                        'attempted_email' => mb_substr((string) $email, 0, 100),
                        'block'           => 'no_such_account',
                    ],
                    null,
                    [
                        'outcome' => 'failure',
                        'reason'  => 'Login attempted for an address with no account.',
                    ]
                );
                \gjc_record_login_failure($this->con, $throttleKey);
                \gjc_record_login_failure($this->con, $ipKey);
                return "Invalid email/ID or password.";
            }
        }
    }
}
