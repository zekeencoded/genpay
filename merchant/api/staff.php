<?php
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json');
gjc_require_role(['merchant']);
gjc_ensure_staff_position_schema($db);

// Only Merchant Admin (or legacy merchant) can manage staff
if (!gjc_is_merchant_admin() && (gjc_current_role() !== 'merchant' || gjc_is_merchant_staff())) {
    echo json_encode(['success' => false, 'message' => 'Only Merchant Admin can manage staff accounts.']);
    exit;
}

$action         = trim((string) ($_POST['action'] ?? ''));
$merchantUserId = gjc_user_id();

// The Manage actions change someone else's credentials or remove them, so
// they carry the page's CSRF token (create/toggle predate this and don't).
if (in_array($action, ['update_staff', 'reset_staff_password', 'remove_staff'], true) && !gjc_csrf_verify()) {
    echo json_encode(['success' => false, 'message' => 'Security check failed. Reload the page and try again.']);
    exit;
}

/** This owner's staff row, still on the roster — or null. */
$findStaff = static function (int $userId) use ($db, $merchantUserId): ?array {
    if ($userId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT userID, first_name, middle_name, last_name, suffix, position, status
           FROM users
          WHERE userID = ? AND merchant_owner_id = ? AND roleID = 6 AND staff_removed_at IS NULL
          LIMIT 1"
    );
    $stmt->execute([$userId, $merchantUserId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};

try {
    switch ($action) {
        case 'create_staff': {
            $firstName  = trim((string) ($_POST['first_name'] ?? ''));
            $middleName = trim((string) ($_POST['middle_name'] ?? ''));
            $lastName   = trim((string) ($_POST['last_name'] ?? ''));
            $suffix     = trim((string) ($_POST['suffix'] ?? ''));
            $position   = trim((string) ($_POST['position'] ?? ''));
            $email      = strtolower(trim((string) ($_POST['email'] ?? '')));
            $contact    = trim((string) ($_POST['contact_number'] ?? '0'));
            $password   = (string) ($_POST['password'] ?? '');

            if (!$firstName || !$lastName || !$email || !$password) {
                echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
                exit;
            }

            if (mb_strlen($middleName) > 60 || mb_strlen($firstName) > 60 || mb_strlen($lastName) > 60) {
                echo json_encode(['success' => false, 'message' => 'Name fields must not exceed 60 characters.']);
                exit;
            }

            if (mb_strlen($suffix) > 20) {
                echo json_encode(['success' => false, 'message' => 'Suffix must not exceed 20 characters.']);
                exit;
            }

            if (mb_strlen($position) > 60) {
                echo json_encode(['success' => false, 'message' => 'Position must not exceed 60 characters.']);
                exit;
            }

            if (strlen($password) < 8) {
                echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
                exit;
            }

            // Check email uniqueness
            $dup = $db->prepare("SELECT userID FROM users WHERE email = ? LIMIT 1");
            $dup->execute([$email]);
            if ($dup->fetch()) {
                echo json_encode(['success' => false, 'message' => 'An account with that email already exists.']);
                exit;
            }

            $hashedPw = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare(
                "INSERT INTO users
                    (last_name, first_name, middle_name, suffix, email, contact_number, roleID, position, merchant_owner_id, password, profile_img)
                 VALUES (?, ?, ?, ?, ?, ?, 6, ?, ?, ?, '')"
            );
            $stmt->execute([
                $lastName,
                $firstName,
                $middleName !== '' ? $middleName : null,
                $suffix !== '' ? $suffix : null,
                $email,
                $contact ?: '0',
                $position !== '' ? $position : null,
                $merchantUserId,
                $hashedPw,
            ]);
            $newStaffId = (int) $db->lastInsertId();

            $fullName = implode(' ', array_filter(
                [$firstName, $middleName, $lastName, $suffix],
                fn($part) => $part !== ''
            ));

            logAudit(
                $db,
                $merchantUserId,
                gjc_current_role(),
                'USER_ACCOUNT',
                'users',
                null,
                [
                    'event' => 'created',
                    'user_id' => $newStaffId,
                    'name' => $fullName,
                    'email' => $email,
                    'role' => 'merchant_staff',
                    'position' => $position !== '' ? $position : null,
                    'merchant_owner_id' => $merchantUserId,
                ],
                null,
                ['target_id' => $newStaffId]
            );

            echo json_encode(['success' => true, 'message' => 'Staff account created successfully.', 'user_id' => $newStaffId]);
            break;
        }

        case 'toggle_staff_status': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            if (!$userId) {
                echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
                exit;
            }

            // Verify this staff belongs to the current merchant admin
            $staffRow = $findStaff($userId);
            if (!$staffRow) {
                echo json_encode(['success' => false, 'message' => 'Staff member not found or not under your management.']);
                exit;
            }

            $currentStatus = $staffRow['status'];
            $newStatus     = $currentStatus === 'Active' ? 'Inactive' : 'Active';

            $db->prepare(
                "UPDATE users SET status = ? WHERE userID = ? AND merchant_owner_id = ?"
            )->execute([$newStatus, $userId, $merchantUserId]);

            logAudit(
                $db,
                $merchantUserId,
                gjc_current_role(),
                'USER_ACCOUNT',
                'users',
                ['user_id' => $userId, 'status' => $currentStatus],
                ['event' => $newStatus === 'Active' ? 'reactivated' : 'deactivated', 'user_id' => $userId, 'changed_by' => $merchantUserId, 'new_status' => $newStatus],
                null,
                ['target_id' => $userId]
            );

            echo json_encode(['success' => true, 'new_status' => $newStatus, 'message' => "Staff account set to {$newStatus}."]);
            break;
        }

        case 'update_staff': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $staffRow = $findStaff($userId);
            if (!$staffRow) {
                echo json_encode(['success' => false, 'message' => 'Staff member not found or not under your management.']);
                exit;
            }

            $firstName  = trim((string) ($_POST['first_name'] ?? ''));
            $middleName = trim((string) ($_POST['middle_name'] ?? ''));
            $lastName   = trim((string) ($_POST['last_name'] ?? ''));
            $suffix     = trim((string) ($_POST['suffix'] ?? ''));
            $position   = trim((string) ($_POST['position'] ?? ''));

            if ($firstName === '' || $lastName === '') {
                echo json_encode(['success' => false, 'message' => 'First and last name are required.']);
                exit;
            }
            if (mb_strlen($firstName) > 60 || mb_strlen($middleName) > 60 || mb_strlen($lastName) > 60) {
                echo json_encode(['success' => false, 'message' => 'Name fields must not exceed 60 characters.']);
                exit;
            }
            if (mb_strlen($suffix) > 20) {
                echo json_encode(['success' => false, 'message' => 'Suffix must not exceed 20 characters.']);
                exit;
            }
            if (mb_strlen($position) > 60) {
                echo json_encode(['success' => false, 'message' => 'Position must not exceed 60 characters.']);
                exit;
            }

            $after = [
                'first_name'  => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name'   => $lastName,
                'suffix'      => $suffix !== '' ? $suffix : null,
                'position'    => $position !== '' ? $position : null,
            ];

            $db->prepare(
                "UPDATE users
                    SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, position = ?
                  WHERE userID = ? AND merchant_owner_id = ?"
            )->execute([...array_values($after), $userId, $merchantUserId]);

            logAudit(
                $db,
                $merchantUserId,
                gjc_current_role(),
                'USER_ACCOUNT',
                'users',
                array_intersect_key($staffRow, $after),
                ['event' => 'staff_updated', 'user_id' => $userId] + $after,
                null,
                ['target_id' => $userId]
            );

            echo json_encode(['success' => true, 'message' => 'Staff details saved.']);
            break;
        }

        case 'reset_staff_password': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            if (!$findStaff($userId)) {
                echo json_encode(['success' => false, 'message' => 'Staff member not found or not under your management.']);
                exit;
            }

            $password = (string) ($_POST['password'] ?? '');
            if (strlen($password) < 8) {
                echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
                exit;
            }

            // The owner now knows this password, so the staff member has to
            // pick their own on the next sign-in (the change_password.php flow).
            gjc_ensure_first_login_schema($db);
            $sets = ['password = ?'];
            $cols = gjc_table_columns($db, 'users');
            if (in_array('force_password_change', $cols, true)) {
                $sets[] = 'force_password_change = 1';
            }

            $db->prepare(
                "UPDATE users SET " . implode(', ', $sets) . " WHERE userID = ? AND merchant_owner_id = ?"
            )->execute([password_hash($password, PASSWORD_BCRYPT), $userId, $merchantUserId]);

            // Never the password or its hash — only that it happened, and by whom.
            logAudit(
                $db,
                $merchantUserId,
                gjc_current_role(),
                'PASSWORD_CHANGE',
                'users',
                null,
                ['password_changed' => true, 'changed_via' => 'merchant_staff_reset', 'user_id' => $userId],
                null,
                ['target_id' => $userId]
            );

            echo json_encode(['success' => true, 'message' => 'Password reset. They will be asked to set a new one when they next sign in.']);
            break;
        }

        case 'remove_staff': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $staffRow = $findStaff($userId);
            if (!$staffRow) {
                echo json_encode(['success' => false, 'message' => 'Staff member not found or not under your management.']);
                exit;
            }

            // Soft delete (see gjc_ensure_staff_position_schema): Inactive
            // blocks sign-in and ends any open session on its next request;
            // the stamp takes the row off the roster. Their past sales keep
            // pointing at a real user row.
            $db->prepare(
                "UPDATE users SET status = 'Inactive', staff_removed_at = NOW()
                  WHERE userID = ? AND merchant_owner_id = ?"
            )->execute([$userId, $merchantUserId]);

            // Inactive already refuses a remember-me cookie; drop them anyway.
            if (gjc_table_exists($db, 'auth_remember_tokens')) {
                $db->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ?")->execute([$userId]);
            }

            logAudit(
                $db,
                $merchantUserId,
                gjc_current_role(),
                'USER_ACCOUNT',
                'users',
                ['user_id' => $userId, 'status' => $staffRow['status']],
                ['event' => 'removed', 'user_id' => $userId, 'removed_by' => $merchantUserId, 'status' => 'Inactive'],
                null,
                ['target_id' => $userId]
            );

            echo json_encode(['success' => true, 'message' => 'Staff member removed.']);
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'merchant/api/staff', 'Server error. Please try again.')]);
}
