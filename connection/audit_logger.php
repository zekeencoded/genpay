<?php

/**
 * Central, append-only audit logger.
 *
 * Configure a restricted database user with:
 *   AUDIT_DB_USER=gjc_audit_writer
 *   AUDIT_DB_PASS=...
 *
 * The function keeps a separate PDO connection from the app connection and
 * suppresses all logging errors so audit failures never interrupt workflows.
 */

function gjc_audit_table_sql(): string
{
    return <<<SQL
CREATE TABLE IF NOT EXISTS systemic_audit_trail (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_role ENUM('Finance', 'Student', 'Merchant', 'Vendor/Staff') NOT NULL,
    action_type ENUM('LOGIN', 'LOGOUT', 'PASSWORD_CHANGE', 'TRANSACTION', 'MENU_MUTATION', 'STALL_UPDATE', 'USER_IMPORT', 'MERCHANT_CREATE', 'USER_ACCOUNT', 'MERCHANT_ONBOARDING', 'PRODUCT_RESTRICTION', 'LOGIN_FAILED') NOT NULL,
    stall_id VARCHAR(20) NULL,
    affected_table VARCHAR(50) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_timestamp (timestamp),
    INDEX idx_audit_role_action (user_role, action_type),
    CONSTRAINT fk_systemic_audit_user
        FOREIGN KEY (user_id) REFERENCES users(userID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL;
}

function gjc_ensure_audit_table(PDO $pdo): void
{
    try {
        $pdo->exec(gjc_audit_table_sql());
        $column = $pdo->query("SHOW COLUMNS FROM systemic_audit_trail LIKE 'action_type'")->fetch(PDO::FETCH_ASSOC);
        $actionType = (string) ($column['Type'] ?? '');
        $requiredActions = ['USER_IMPORT', 'MERCHANT_CREATE', 'USER_ACCOUNT', 'MERCHANT_ONBOARDING', 'PRODUCT_RESTRICTION', 'LOGIN_FAILED'];
        $missingAny = false;
        foreach ($requiredActions as $required) {
            if (strpos($actionType, $required) === false) {
                $missingAny = true;
                break;
            }
        }
        if ($column && $missingAny) {
            $pdo->exec(
                "ALTER TABLE systemic_audit_trail
                 MODIFY action_type ENUM(
                    'LOGIN',
                    'LOGOUT',
                    'PASSWORD_CHANGE',
                    'TRANSACTION',
                    'MENU_MUTATION',
                    'STALL_UPDATE',
                    'USER_IMPORT',
                    'MERCHANT_CREATE',
                    'USER_ACCOUNT',
                    'MERCHANT_ONBOARDING',
                    'PRODUCT_RESTRICTION',
                    'LOGIN_FAILED'
                 ) NOT NULL"
            );
        }

        // Renamed 'GJC Admin' -> 'Finance'. Widen the enum, migrate existing
        // rows, then drop the old label once nothing references it.
        $roleColumn = $pdo->query("SHOW COLUMNS FROM systemic_audit_trail LIKE 'user_role'")->fetch(PDO::FETCH_ASSOC);
        $roleType = (string) ($roleColumn['Type'] ?? '');
        if ($roleColumn && strpos($roleType, "'Finance'") === false) {
            $pdo->exec(
                "ALTER TABLE systemic_audit_trail
                 MODIFY user_role ENUM('GJC Admin', 'Finance', 'Student', 'Merchant', 'Vendor/Staff') NOT NULL"
            );
            $pdo->exec("UPDATE systemic_audit_trail SET user_role = 'Finance' WHERE user_role = 'GJC Admin'");
            $pdo->exec(
                "ALTER TABLE systemic_audit_trail
                 MODIFY user_role ENUM('Finance', 'Student', 'Merchant', 'Vendor/Staff') NOT NULL"
            );
        }

        // Fee Waiver Credit: widen action_type from whatever labels are live right
        // now (not a hardcoded list) so this never clobbers a label added by a
        // later migration that this function doesn't know about.
        $actionColumn = $pdo->query("SHOW COLUMNS FROM systemic_audit_trail LIKE 'action_type'")->fetch(PDO::FETCH_ASSOC);
        $liveActionType = (string) ($actionColumn['Type'] ?? '');
        if ($actionColumn && strpos($liveActionType, "'FEE_WAIVER_STATUS_CHANGE'") === false) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $liveActionType, $labelMatches);
            $labels = $labelMatches[1] ?? [];
            $labels[] = 'FEE_WAIVER_STATUS_CHANGE';
            $enumSql = implode(', ', array_map(
                static fn(string $label): string => "'" . str_replace("'", "\\'", $label) . "'",
                $labels
            ));
            $pdo->exec("ALTER TABLE systemic_audit_trail MODIFY action_type ENUM({$enumSql}) NOT NULL");
        }

        // School Year Cycles: same live-enum widen, not a hardcoded list.
        $actionColumn = $pdo->query("SHOW COLUMNS FROM systemic_audit_trail LIKE 'action_type'")->fetch(PDO::FETCH_ASSOC);
        $liveActionType = (string) ($actionColumn['Type'] ?? '');
        $schoolYearActions = ['SCHOOL_YEAR_CREATED', 'SCHOOL_YEAR_ROLLOVER', 'STUDENT_GRADUATED', 'SY_TXN_BACKFILL'];
        $missingSchoolYearAction = false;
        foreach ($schoolYearActions as $required) {
            if (strpos($liveActionType, "'{$required}'") === false) {
                $missingSchoolYearAction = true;
                break;
            }
        }
        if ($actionColumn && $missingSchoolYearAction) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $liveActionType, $labelMatches);
            $labels = $labelMatches[1] ?? [];
            foreach ($schoolYearActions as $required) {
                if (!in_array($required, $labels, true)) {
                    $labels[] = $required;
                }
            }
            $enumSql = implode(', ', array_map(
                static fn(string $label): string => "'" . str_replace("'", "\\'", $label) . "'",
                $labels
            ));
            $pdo->exec("ALTER TABLE systemic_audit_trail MODIFY action_type ENUM({$enumSql}) NOT NULL");
        }
        // Enrollment-driven school year cycles: same live-enum widen.
        $actionColumn = $pdo->query("SHOW COLUMNS FROM systemic_audit_trail LIKE 'action_type'")->fetch(PDO::FETCH_ASSOC);
        $liveActionType = (string) ($actionColumn['Type'] ?? '');
        $enrollmentActions = ['ENROLLMENT_STATUS_SET', 'ROSTER_UPLOADED', 'ROSTER_APPLIED'];
        $missingEnrollmentAction = false;
        foreach ($enrollmentActions as $required) {
            if (strpos($liveActionType, "'{$required}'") === false) {
                $missingEnrollmentAction = true;
                break;
            }
        }
        if ($actionColumn && $missingEnrollmentAction) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $liveActionType, $labelMatches);
            $labels = $labelMatches[1] ?? [];
            foreach ($enrollmentActions as $required) {
                if (!in_array($required, $labels, true)) {
                    $labels[] = $required;
                }
            }
            $enumSql = implode(', ', array_map(
                static fn(string $label): string => "'" . str_replace("'", "\\'", $label) . "'",
                $labels
            ));
            $pdo->exec("ALTER TABLE systemic_audit_trail MODIFY action_type ENUM({$enumSql}) NOT NULL");
        }

        // System settings + mint PIN: same live-enum widen, not a hardcoded list.
        $actionColumn = $pdo->query("SHOW COLUMNS FROM systemic_audit_trail LIKE 'action_type'")->fetch(PDO::FETCH_ASSOC);
        $liveActionType = (string) ($actionColumn['Type'] ?? '');
        $settingsActions = ['SYSTEM_SETTINGS_CHANGE', 'MINT_PIN_SET'];
        $missingSettingsAction = false;
        foreach ($settingsActions as $required) {
            if (strpos($liveActionType, "'{$required}'") === false) {
                $missingSettingsAction = true;
                break;
            }
        }
        if ($actionColumn && $missingSettingsAction) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $liveActionType, $labelMatches);
            $labels = $labelMatches[1] ?? [];
            foreach ($settingsActions as $required) {
                if (!in_array($required, $labels, true)) {
                    $labels[] = $required;
                }
            }
            $enumSql = implode(', ', array_map(
                static fn(string $label): string => "'" . str_replace("'", "\\'", $label) . "'",
                $labels
            ));
            $pdo->exec("ALTER TABLE systemic_audit_trail MODIFY action_type ENUM({$enumSql}) NOT NULL");
        }
    } catch (Throwable) {
    }
}

function gjc_audit_pdo(): PDO
{
    static $auditPdo = null;

    if ($auditPdo instanceof PDO) {
        return $auditPdo;
    }

    $host = getenv('AUDIT_DB_HOST') ?: 'localhost';
    $name = getenv('AUDIT_DB_NAME') ?: 'ewallet';
    $port = getenv('AUDIT_DB_PORT') ?: '3306';
    $user = getenv('AUDIT_DB_USER') ?: 'root';
    $pass = getenv('AUDIT_DB_PASS');
    if ($pass === false) {
        $pass = 'gitzeke126';
    }

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4;port={$port}";
    $auditPdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $auditPdo;
}

function gjc_audit_role(string $role): string
{
    $role = strtolower(trim($role));

    return match ($role) {
        'gjc admin', 'admin', 'finance', 'super_admin', 'cashier' => 'Finance',
        'student' => 'Student',
        'merchant', 'merchant_admin' => 'Merchant',
        'vendor/staff', 'vendor', 'staff', 'merchant_staff' => 'Vendor/Staff',
        default => 'Student',
    };
}

function gjc_audit_role_from_user(PDO $pdo, int $userId): string
{
    try {
        $stmt = $pdo->prepare("SELECT roleID, sub_role FROM users WHERE userID = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return gjc_audit_role((string) ($_SESSION['role'] ?? 'student'));
        }

        $subRole = (string) ($user['sub_role'] ?? '');
        if ($subRole !== '') {
            return gjc_audit_role($subRole);
        }

        return match ((int) ($user['roleID'] ?? 0)) {
            1 => 'Student',
            2, 5 => 'Merchant',
            3, 4 => 'Finance',
            6 => 'Vendor/Staff',
            default => gjc_audit_role((string) ($_SESSION['role'] ?? 'student')),
        };
    } catch (Throwable) {
        return gjc_audit_role((string) ($_SESSION['role'] ?? 'student'));
    }
}

function gjc_audit_json($value): ?string
{
    if ($value === null) {
        return null;
    }

    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return $encoded === false ? null : $encoded;
}

function logAudit(
    PDO $pdo,
    int $user_id,
    string $user_role,
    string $action_type,
    string $affected_table,
    $old_value,
    $new_value,
    $stall_id = null
): void {
    try {
        if ($user_id <= 0) {
            return;
        }

        $allowedActions = ['LOGIN', 'LOGOUT', 'PASSWORD_CHANGE', 'TRANSACTION', 'MENU_MUTATION', 'STALL_UPDATE', 'USER_IMPORT', 'MERCHANT_CREATE', 'USER_ACCOUNT', 'MERCHANT_ONBOARDING', 'PRODUCT_RESTRICTION', 'LOGIN_FAILED', 'FEE_WAIVER_STATUS_CHANGE', 'SCHOOL_YEAR_CREATED', 'SCHOOL_YEAR_ROLLOVER', 'STUDENT_GRADUATED', 'SY_TXN_BACKFILL', 'SYSTEM_SETTINGS_CHANGE', 'MINT_PIN_SET', 'ENROLLMENT_STATUS_SET', 'ROSTER_UPLOADED', 'ROSTER_APPLIED'];
        $action_type = strtoupper(trim($action_type));
        if (!in_array($action_type, $allowedActions, true)) {
            return;
        }

        $role = $user_role !== '' ? gjc_audit_role($user_role) : gjc_audit_role_from_user($pdo, $user_id);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 255);

        $auditDb = gjc_audit_pdo();
        $stmt = $auditDb->prepare(
            "INSERT INTO systemic_audit_trail
                (user_id, user_role, action_type, stall_id, affected_table,
                 old_value, new_value, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $user_id,
            $role,
            $action_type,
            $stall_id !== null && $stall_id !== '' ? (string) $stall_id : null,
            substr($affected_table, 0, 50),
            gjc_audit_json($old_value),
            gjc_audit_json($new_value),
            substr($ip, 0, 45),
            $agent,
        ]);
    } catch (Throwable) {
    }
}
