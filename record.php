<?php

namespace Classes;



require_once __DIR__ . '/connection/config.php';
require_once __DIR__ . '/connection/pdo.php';
require_once __DIR__ . '/connection/app.php';
require_once __DIR__ . '/connection/audit_logger.php';

class Record
{
    private $con;

    public function __construct($db){
        $this->con = $db;
    }

    
    public function loginUser() {
        if (isset($_POST['login'])) {
            \gjc_ensure_first_login_schema($this->con);
            

            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';

            $stmt = $this->con->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->rowCount()) {
                $user = $stmt->fetch();

                $storedPassword = (string) ($user['password'] ?? '');
                // bcrypt only. This used to fall back to hash_equals($storedPassword,
                // $password) so legacy un-hashed rows could still sign in — verified
                // 2026-09-06 that all 221 user rows are bcrypt ($2y$), so the fallback
                // was accepting plaintext for no one. Do not reinstate it: hash any
                // legacy row in place instead.
                $validPassword = password_verify($password, $storedPassword);

                if ($validPassword) {
                    // The three blocks below turn away an account whose password was
                    // CORRECT. That is a different event from a bad password, and none
                    // of them used to leave any trace — probing a deactivated or banned
                    // account produced an audit trail identical to nobody trying at all.
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
                        in_array((int) ($user['roleID'] ?? 0), [2, 5, 6], true)
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

                    if ($roleId === 1) {
                        \gjc_notify_welcome_if_new($this->con, $userId);
                    }

                    $mustChangePassword =
                        !empty($user['force_password_change']) ||
                        !empty($user['is_first_login']) ||
                        (isset($user['password_changed']) && (int) $user['password_changed'] === 0);

                    // Remember me: issue a 30-day token when ticked (never for
                    // accounts still on a forced password change); an unticked
                    // login discards any token from a previous session.
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
                    return "Invalid password";
                }
            } else {
                // No matching account. This is the shape a password spray against
                // made-up addresses takes, and it used to produce no row at all —
                // the log looked exactly as though nobody had tried.
                //
                // There is no user id to attribute it to, so logAudit() files it
                // against a NULL actor as 'Anonymous'. The attempted identifier is
                // capped at 100 characters: it is unvalidated request input and
                // there is no reason to keep more of it than that.
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
                return "User not found";
            }
        }
    }
}
