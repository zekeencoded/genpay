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
                $validPassword = password_verify($password, $storedPassword) || hash_equals($storedPassword, $password);

                if ($validPassword) {
                    if (!empty($user['status']) && $user['status'] === 'Inactive') {
                        return 'Access Denied: Your account has been deactivated. Please contact your merchant admin.';
                    }

                    // A stall auto-suspended over restricted-product strikes blocks
                    // the owner and every staff account for the duration.
                    $suspendedUntil = \gjc_merchant_suspended_until($this->con, (int) ($user['id'] ?? $user['userID'] ?? 0));
                    if ($suspendedUntil !== null) {
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
                        ]
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
                        ]
                    );
                    return "Invalid password";
                }
            } else {
                return "User not found";
            }
        }
    }
}
