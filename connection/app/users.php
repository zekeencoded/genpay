<?php

// Current user, sessions, roles, CSRF, profiles, addresses and student ids.

function gjc_user_id(): int
{
    return (int) ($_SESSION['userID'] ?? $_SESSION['user_id'] ?? 0);
}

function gjc_current_role(): string
{
    return gjc_role_name($_SESSION['roleID'] ?? $_SESSION['role'] ?? 0);
}

/** Optional staff job title (no effect on permissions); also makes middle_name/suffix nullable. */
function gjc_ensure_staff_position_schema(PDO $db): void
{
    if (!gjc_table_exists($db, "users")) {
        return;
    }

    $columns = gjc_table_columns($db, "users");
    if (!in_array("position", $columns, true)) {
        $db->exec("ALTER TABLE users ADD COLUMN position VARCHAR(60) NULL DEFAULT NULL AFTER roleID");
    }
    // Staff removal is a soft delete because audit rows reference users.userID.
    if (!in_array("staff_removed_at", $columns, true)) {
        $db->exec("ALTER TABLE users ADD COLUMN staff_removed_at DATETIME NULL DEFAULT NULL AFTER position");
    }

    $stmt = $db->prepare(
        "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
           AND COLUMN_NAME IN ('middle_name', 'suffix') AND IS_NULLABLE = 'NO'",
    );
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            $db->exec("ALTER TABLE users MODIFY {$row['COLUMN_NAME']} {$row['COLUMN_TYPE']} NULL DEFAULT NULL");
        } catch (Throwable $ignored) {
        }
    }
}

function gjc_ensure_first_login_schema(PDO $db): void
{
    if (!gjc_table_exists($db, "users")) {
        return;
    }

    $columns = gjc_table_columns($db, "users");
    $adds = [
    ];

    foreach ($adds as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
        }
    }
}

/** Merge the user's address fields into $row. */
function gjc_with_user_address(PDO $db, array $row, int $userId): array
{
    if (!$row || !gjc_table_exists($db, 'user_addresses') || !gjc_table_exists($db, 'address_locations')) {
        return $row;
    }
    $fields = ['address_barangay', 'address_city', 'address_province'];
    $legacy = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $row)) {
            $legacy[$field] = $row[$field];
        }
    }
    $normalized = gjc_user_address_values($db, $userId);
    if (count($legacy) === 3 && ($normalized === null || $normalized !== $legacy)) {
        return $row;
    }
    return array_replace($row, $normalized ?? array_fill_keys($fields, null));
}

function gjc_current_user(PDO $db): array
{
    $id = gjc_user_id();
    if (!$id || !gjc_table_exists($db, "users")) {
        return [
            "id" => 0,
            "name" => "Guest",
            "email" => "",
            "role" => gjc_current_role(),
            "roleID" => (int) ($_SESSION["roleID"] ?? 0),
        ];
    }

    $idColumn = gjc_column($db, "users", ["id", "userID"]);
    if (!$idColumn) {
        return [
            "id" => $id,
            "name" => "User",
            "email" => "",
            "role" => gjc_current_role(),
            "roleID" => 0,
        ];
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE {$idColumn} = ? LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($user) {
        $user = gjc_with_user_address($db, $user, (int) ($user[$idColumn] ?? $id));
    }

    $name = trim((string) ($user["name"] ?? ""));
    if ($name === "") {
        $name = trim(
            (string) (($user["first_name"] ?? "") .
                " " .
                ($user["last_name"] ?? "")),
        );
    }
    if ($name === "") {
        $name = $user["email"] ?? "User";
    }

    $role = gjc_current_role();
    if (!empty($user["roleID"])) {
        $role = gjc_role_name($user["roleID"]);
    }

    return [
        "id" => (int) ($user[$idColumn] ?? $id),
        "name" => $name,
        "email" => $user["email"] ?? "",
        "role" => $role,
        "roleID" => (int) ($user["roleID"] ?? ($_SESSION["roleID"] ?? 0)),
        "raw" => $user,
    ];
}

function gjc_user_label(PDO $db, int $userId): string
{
    // Lists label the same few users on every row; names don't change mid-request.
    static $labels = [];
    if (isset($labels[$userId])) {
        return $labels[$userId];
    }
    if (!$userId || !gjc_table_exists($db, "users")) {
        return "Unknown User";
    }

    $idColumn = gjc_column($db, "users", ["id", "userID"]);
    if (!$idColumn) {
        return "User #" . $userId;
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE {$idColumn} = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $name = trim((string) ($user["name"] ?? ""));
    if ($name === "") {
        $name = trim(
            (string) (($user["first_name"] ?? "") .
                " " .
                ($user["last_name"] ?? "")),
        );
    }
    if ($name === "") {
        $name = $user["email"] ?? "User #" . $userId;
    }
    return $labels[$userId] = $name;
}

// ── Remember me: single-use rotating tokens; only the validator hash is stored ──

function gjc_ensure_remember_token_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS auth_remember_tokens (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     INT NOT NULL,
            selector    CHAR(24) NOT NULL,
            token_hash  CHAR(64) NOT NULL,
            expires_at  DATETIME NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_art_selector (selector),
            KEY idx_art_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );
}

/** Granular role for a roleID (roleID is the source of truth). */
function gjc_sub_role_for_role_id(int $roleId): string
{
    return gjc_sub_role_map()[$roleId] ?? 'student';
}

/** roleID => sub_role. The one place this mapping is defined. */
function gjc_sub_role_map(): array
{
    return [
        1 => 'student',
        2 => 'merchant_admin',
        3 => 'super_admin',
        4 => 'super_admin',
        5 => 'merchant_admin',
        6 => 'merchant_staff',
        7 => 'parent',
        8 => 'finance_staff',
    ];
}

/** Admin pages finance staff (roleID 8) may open; everything else is super-admin only. */
const GJC_FINANCE_STAFF_PAGES = [
    'dashboard.php', 'users.php', 'view_user.php',
    'transactions.php', 'view_transaction.php', 'export_transactions.php',
    'cash_management.php', 'encashments.php', 'topups.php', 'release_encashment.php', 'api/economy.php',
    'leases.php', 'lease_details.php', 'api/leases.php', 'merchant_details.php',
    'stall_applications.php', 'api/stall_applications.php', 'api/archived_rejections.php',
    'api/get_stall_details.php', 'doc.php',
];

/** Whether a finance-staff session may run this script. Non-admin scripts are not this gate's business. */
function gjc_finance_staff_may_open(string $script): bool
{
    $pos = strrpos($script, '/admin/');
    return $pos === false || in_array(substr($script, $pos + 7), GJC_FINANCE_STAFF_PAGES, true);
}

/** The role row users.roleID 8 points at (users.roleID has a foreign key to role). */
function gjc_ensure_finance_staff_role(PDO $db): void
{
    $db->exec("INSERT IGNORE INTO role (roleID, role_name) VALUES (8, 'finance_staff')");
}

/** SQL expression deriving sub_role from a roleID column, for SELECT lists. */
function gjc_sub_role_sql(string $roleIdColumn = 'roleID'): string
{
    $cases = '';
    foreach (gjc_sub_role_map() as $id => $name) {
        $cases .= " WHEN {$id} THEN '{$name}'";
    }
    return "(CASE {$roleIdColumn}{$cases} ELSE 'student' END)";
}

/** Populate the login session vars for a users row (shared by password login and remember-me). */
function gjc_establish_login_session(array $user): void
{
    $userId = (int) ($user['id'] ?? $user['userID'] ?? 0);
    $roleId = (int) ($user['roleID'] ?? 0);

    // A new id at sign-in means a session fixated before login is no longer valid.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['userID'] = $userId;
    $_SESSION['user_id'] = $userId;
    $_SESSION['roleID'] = $roleId;
    $_SESSION['password_fingerprint'] = hash('sha256', (string) ($user['password'] ?? ''));

    // roleID is the single source of truth; sub_role is derived (3NF).
    $_SESSION['sub_role'] = gjc_sub_role_for_role_id($roleId);
    $_SESSION['merchant_owner_id'] = (int) ($user['merchant_owner_id'] ?? 0);
    $_SESSION['role'] = [1 => 'student', 2 => 'merchant', 3 => 'finance', 4 => 'finance', 5 => 'merchant', 6 => 'merchant', 7 => 'parent', 8 => 'finance'][$roleId] ?? 'user';
}

function gjc_issue_remember_token(PDO $db, int $userId, int $days = 30): void
{
    gjc_ensure_remember_token_schema($db);
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $db->prepare(
        "INSERT INTO auth_remember_tokens (user_id, selector, token_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
    )->execute([$userId, $selector, hash('sha256', $validator), $days]);

    setcookie('gjc_remember', $selector . ':' . $validator, [
        'expires'  => time() + $days * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Delete the presented token (if any) and expire the cookie. Safe to call anytime. */
function gjc_clear_remember_token(PDO $db): void
{
    $raw = (string) ($_COOKIE['gjc_remember'] ?? '');
    if ($raw === '') {
        return;
    }
    if (strpos($raw, ':') !== false) {
        [$selector] = explode(':', $raw, 2);
        if (preg_match('/^[a-f0-9]{24}$/', $selector) && gjc_table_exists($db, 'auth_remember_tokens')) {
            $db->prepare("DELETE FROM auth_remember_tokens WHERE selector = ?")->execute([$selector]);
        }
    }
    setcookie('gjc_remember', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE['gjc_remember']);
}

/** Restore a session from the remember-me cookie; the token rotates on use. */
function gjc_attempt_remember_login(PDO $db): void
{
    if (gjc_user_id() > 0) {
        return;
    }
    $raw = (string) ($_COOKIE['gjc_remember'] ?? '');
    if ($raw === '' || strpos($raw, ':') === false) {
        return;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        gjc_clear_remember_token($db);
        return;
    }

    gjc_ensure_remember_token_schema($db);
    $stmt = $db->prepare("SELECT * FROM auth_remember_tokens WHERE selector = ? LIMIT 1");
    $stmt->execute([$selector]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$token
        || $token['expires_at'] <= date('Y-m-d H:i:s')
        || !hash_equals($token['token_hash'], hash('sha256', $validator))) {
        gjc_clear_remember_token($db);
        return;
    }

    $idCol = gjc_column($db, 'users', ['id', 'userID']) ?: 'userID';
    $userStmt = $db->prepare("SELECT * FROM users WHERE {$idCol} = ? LIMIT 1");
    $userStmt->execute([(int) $token['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    $mustChange = $user && !empty($user['force_password_change']);
    if (!$user || (!empty($user['status']) && $user['status'] === 'Inactive') || $mustChange) {
        gjc_clear_remember_token($db);
        return;
    }

    // Suspended accounts can't auto-login (would cause a redirect loop).
    if (gjc_account_suspension($db, (int) $token['user_id']) !== null) {
        gjc_clear_remember_token($db);
        return;
    }

    $db->prepare("DELETE FROM auth_remember_tokens WHERE id = ?")->execute([(int) $token['id']]);
    gjc_establish_login_session($user);
    session_regenerate_id(true);
    gjc_issue_remember_token($db, (int) $token['user_id']);
}

// ── Failed-attempt throttle (login, Mint PIN, apply form); keys are HMAC hashes ──
// shortcut: the table name still says "login"; rename it if a fifth use appears.

function gjc_ensure_login_attempts_schema(PDO $db): void
{
    // DDL commits any open transaction, so it runs once per request and never mid-transaction.
    static $ready = false;
    if ($ready) {
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            login_key   CHAR(64) NOT NULL,
            failed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_la_key_time (login_key, failed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );
    $ready = true;
}

/** Key for one scope (sign-in identifier, ip, mint, apply). Separate HMAC secrets keep scopes from colliding. */
function gjc_throttle_key(string $scope, string $value): string
{
    return hash_hmac('sha256', $value, 'gjc-throttle-' . $scope);
}

function gjc_login_throttled(PDO $db, string $key, int $limit = 5, int $minutes = 15): bool
{
    gjc_ensure_login_attempts_schema($db);
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts WHERE login_key = ? AND failed_at > NOW() - INTERVAL {$minutes} MINUTE"
    );
    $stmt->execute([$key]);
    return (int) $stmt->fetchColumn() >= $limit;
}

function gjc_record_login_failure(PDO $db, string $key): void
{
    gjc_ensure_login_attempts_schema($db);
    $db->prepare("INSERT INTO login_attempts (login_key) VALUES (?)")->execute([$key]);
    // Drop old rows so the table stays small. Only the last 15 minutes matter.
    $db->exec("DELETE FROM login_attempts WHERE failed_at < NOW() - INTERVAL 1 DAY");
}

function gjc_clear_login_failures(PDO $db, string $key): void
{
    $db->prepare("DELETE FROM login_attempts WHERE login_key = ?")->execute([$key]);
}

/** Reject sessions authenticated before a credential change, including older sessions. */
function gjc_password_session_valid(array $user): bool
{
    $fingerprint = (string) ($_SESSION['password_fingerprint'] ?? '');
    if ($fingerprint !== '') {
        return hash_equals($fingerprint, hash('sha256', (string) ($user['password'] ?? '')));
    }
    // Older sessions can't prove which password they used; force a fresh login.
    return false;
}

function gjc_require_role(array $roles): void
{
    // Signed-in pages must not be cached, or Back after logout shows a stale copy.
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    global $db;
    if ($db instanceof PDO) {
        gjc_attempt_remember_login($db);
    }

    $role = gjc_current_role();
    if (!in_array($role, $roles, true)) {
        // Log only signed-in users denied access; anonymous redirects are noise.
        $deniedUserId = gjc_user_id();
        if ($deniedUserId > 0 && $db instanceof PDO && function_exists('logAudit')) {
            logAudit(
                $db,
                $deniedUserId,
                $role,
                'ACCESS_DENIED',
                'users',
                null,
                [
                    'actual_role'    => $role,
                    'required_roles' => $roles,
                ],
                null,
                [
                    'target_id' => $deniedUserId,
                    'outcome'   => 'denied',
                    'reason'    => 'Role ' . $role . ' cannot open a page restricted to: ' . implode(', ', $roles) . '.',
                ]
            );
        }
        header("Location: " . BASE_URL . "/login.php");
        exit();
    }

    // If the account was deactivated after this session was created, force logout immediately.
    $userId = gjc_user_id();
    if ($userId) {
        global $db;
        if ($db instanceof PDO && gjc_table_exists($db, 'users')) {
            if (in_array('status', gjc_table_columns($db, 'users'), true)) {
                $idCol = gjc_column($db, 'users', ['id', 'userID']);
                if ($idCol) {
                    $chk = $db->prepare("SELECT * FROM users WHERE {$idCol} = ? LIMIT 1");
                    $chk->execute([$userId]);
                    $sessionUser = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$sessionUser || !gjc_password_session_valid($sessionUser)) {
                        gjc_clear_remember_token($db);
                        session_unset();
                        session_destroy();
                        header("Location: " . BASE_URL . "/login.php?reason=password_reset");
                        exit();
                    }
                    $_SESSION['password_fingerprint'] = hash('sha256', (string) $sessionUser['password']);
                    if ($sessionUser['status'] === 'Inactive') {
                        session_unset();
                        session_destroy();
                        header("Location: " . BASE_URL . "/login.php?reason=deactivated");
                        exit();
                    }
                }
            }

            // Restricted-product strike suspension locks out owner and staff.
            if ($role === 'merchant') {
                $suspendedUntil = gjc_merchant_suspended_until($db, $userId);
                if ($suspendedUntil !== null) {
                    session_unset();
                    session_destroy();
                    header("Location: " . BASE_URL . "/login.php?reason=suspended&until=" . urlencode($suspendedUntil));
                    exit();
                }
            }

            // Finance suspension is checked every page load; staff inherit the owner's.
            $account = gjc_account_suspension($db, $userId, $role === 'merchant');
            if ($account !== null) {
                session_unset();
                session_destroy();
                $reason = ($account['kind'] ?? 'suspension') === 'ban' ? 'account_banned' : 'account_suspended';
                header("Location: " . BASE_URL . "/login.php?reason={$reason}"
                    . ($account['until'] !== null ? "&until=" . urlencode((string) $account['until']) : ""));
                exit();
            }
        }
    }

    $script = str_replace("\\", "/", $_SERVER["SCRIPT_NAME"] ?? "");
    if (!empty($_SESSION["force_change"]) && !str_ends_with($script, "/change_password.php")) {
        header("Location: " . BASE_URL . "/change_password.php");
        exit();
    }

    if (gjc_sub_role() === 'finance_staff' && !gjc_finance_staff_may_open($script)) {
        if ($db instanceof PDO && function_exists('logAudit')) {
            logAudit($db, gjc_user_id(), $role, 'ACCESS_DENIED', 'users', null,
                ['actual_sub_role' => 'finance_staff', 'script' => $script], null,
                ['target_id' => gjc_user_id(), 'outcome' => 'denied',
                 'reason' => 'Finance staff cannot open ' . basename($script) . '.']);
        }
        if (str_contains($script, '/admin/api/') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Finance staff accounts cannot do this.', 'error' => 'ACCESS_DENIED']);
            exit();
        }
        header("Location: " . DASHBOARD_URL);
        exit();
    }
}

/** Nobody on file is older than this — an upper bound for date-of-birth sanity. */
const GJC_MAX_AGE = 120;

/** Self-healer for optional personal details on users (sex, DOB, address). Age is computed, not stored. */
function gjc_ensure_user_profile_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        return;
    }

    gjc_ensure_user_address_schema($db);
    $cols = gjc_table_columns($db, 'users');
    $altered = false;
    $userFields = array_intersect_key(gjc_user_profile_columns(), array_flip(['sex', 'date_of_birth']));
    foreach ($userFields as $col => $def) {
        if (!in_array($col, $cols, true)) {
            try {
                $db->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
                $altered = true;
            } catch (\Throwable $ignored) {}
        }
    }
    if ($altered) {
        gjc_table_columns($db, 'users', true);
    }
}

/** Save the profile and address together. */
function gjc_save_profile_with_address(PDO $db, int $userId, string $sql, array $params, array $address): void
{
    if ($db->inTransaction()) {
        throw new LogicException('Profile address writes must own their transaction.');
    }
    if (!gjc_table_exists($db, 'address_locations') || !gjc_table_exists($db, 'user_addresses')) {
        throw new RuntimeException('Profile could not be saved. Please try again.');
    }
    $addressKeys = ['address_barangay', 'address_city', 'address_province'];
    $columns = gjc_table_columns($db, 'users', true);
    $legacyCount = count(array_intersect($addressKeys, $columns));
    if ($legacyCount !== 0 && $legacyCount !== 3) {
        throw new LogicException('Legacy address columns are only partially present.');
    }
    foreach ($addressKeys as $key) {
        if (!array_key_exists($key, $address)) {
            throw new InvalidArgumentException('All address fields must be supplied.');
        }
    }
    if (!$params || (int) $params[array_key_last($params)] !== $userId) {
        throw new InvalidArgumentException('Profile UPDATE must target the locked user.');
    }
    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT userID FROM users WHERE userID = ? FOR UPDATE');
        $lock->execute([$userId]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Profile could not be saved. Please try again.');
        }
        $db->prepare($sql)->execute($params);
        if ($legacyCount === 3) {
            $read = $db->prepare('SELECT address_barangay, address_city, address_province FROM users WHERE userID = ?');
            $read->execute([$userId]);
            $address = $read->fetch(PDO::FETCH_ASSOC);
            if (!$address) {
                throw new RuntimeException('Profile could not be saved. Please try again.');
            }
        }
        gjc_sync_user_address($db, $userId, $address);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Self-healer for user addresses. Call outside transactions. */
function gjc_ensure_user_address_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        throw new RuntimeException('User address schema requires users.');
    }
    $type = $db->query("SHOW COLUMNS FROM users LIKE 'userID'")->fetch(PDO::FETCH_ASSOC);
    if (!$type || strtolower($type['Type']) !== 'int') {
        throw new RuntimeException('User address FK requires signed INT users.userID.');
    }
    $locationsExist = gjc_table_exists($db, 'address_locations');
    $addressesExist = gjc_table_exists($db, 'user_addresses');
    if ($locationsExist && $addressesExist) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('User address schema creation must precede a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS address_locations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id INT UNSIGNED NULL,
    location_kind ENUM('province', 'city', 'barangay') NOT NULL,
    label VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_address_location_scope (location_kind, (COALESCE(parent_id, 0)), label),
    KEY idx_address_location_parent (parent_id),
    CONSTRAINT fk_address_location_parent FOREIGN KEY (parent_id)
        REFERENCES address_locations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_address_province_root CHECK (location_kind <> 'province' OR parent_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_addresses (
    user_id INT NOT NULL,
    location_id INT UNSIGNED NULL,
    PRIMARY KEY (user_id),
    KEY idx_user_address_location (location_id),
    CONSTRAINT fk_user_address_user FOREIGN KEY (user_id)
        REFERENCES users(userID) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_user_address_location FOREIGN KEY (location_id)
        REFERENCES address_locations(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    gjc_table_columns($db, 'address_locations', true);
    gjc_table_columns($db, 'user_addresses', true);
}

/** Writes only normalized address tables; the caller owns the transaction and compatibility write. */
function gjc_sync_user_address(PDO $db, int $userId, array $values): void
{
    if (!$db->inTransaction()) {
        throw new RuntimeException('Address synchronization requires a transaction.');
    }
    foreach (['province', 'city', 'barangay'] as $kind) {
        $key = 'address_' . $kind;
        if (!array_key_exists($key, $values)) {
            throw new InvalidArgumentException('All three address fields must be supplied.');
        }
        if ($values[$key] !== null
            && (!is_string($values[$key]) || mb_strlen($values[$key], 'UTF-8') > 120)) {
            throw new InvalidArgumentException('Address labels must be strings of at most 120 characters.');
        }
    }
    $parentId = null;
    foreach (['province', 'city', 'barangay'] as $kind) {
        $label = $values['address_' . $kind];
        if ($label === null) {
            continue;
        }
        $find = $db->prepare('SELECT id FROM address_locations
            WHERE parent_id <=> ? AND location_kind = ? AND label = ? LIMIT 1');
        $find->execute([$parentId, $kind, $label]);
        $id = $find->fetchColumn();
        if ($id === false) {
            $db->prepare('INSERT INTO address_locations (parent_id, location_kind, label)
                VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
                ->execute([$parentId, $kind, $label]);
            $id = $db->lastInsertId();
        }
        $parentId = (int) $id;
    }
    $db->prepare('INSERT INTO user_addresses (user_id, location_id) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE location_id = ?')->execute([$userId, $parentId, $parentId]);
}

/** Returns NULL for an unmigrated user, or the exact original address field layout. */
function gjc_user_address_values(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare('SELECT location_id FROM user_addresses WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $values = ['address_barangay' => null, 'address_city' => null, 'address_province' => null];
    $id = $row['location_id'];
    $previousRank = 3;
    $ranks = ['province' => 0, 'city' => 1, 'barangay' => 2];
    $lookup = $db->prepare('SELECT parent_id, location_kind, label FROM address_locations WHERE id = ?');
    while ($id !== null) {
        $lookup->execute([(int) $id]);
        $node = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$node || !isset($ranks[$node['location_kind']])
            || $ranks[$node['location_kind']] >= $previousRank) {
            throw new RuntimeException('Address hierarchy is missing or invalid.');
        }
        $previousRank = $ranks[$node['location_kind']];
        $values['address_' . $node['location_kind']] = $node['label'];
        $id = $node['parent_id'];
    }
    return $values;
}

/** Personal-details columns in display order (shared by schema, forms and views). */
function gjc_user_profile_columns(): array
{
    return [
        'sex'                        => "ENUM('Male', 'Female', 'Prefer not to say') NULL DEFAULT NULL",
        'date_of_birth'              => 'DATE NULL',
        'address_barangay'           => 'VARCHAR(120) NULL',
        'address_city'               => 'VARCHAR(120) NULL',
        'address_province'           => 'VARCHAR(120) NULL',
    ];
}

/** The values users.sex accepts. Matches the ENUM above. */
function gjc_sex_options(): array
{
    return ['Male', 'Female', 'Prefer not to say'];
}

/** Age in years from date of birth, or null. */
function gjc_age_from_dob(?string $dob): ?int
{
    $dob = trim((string) $dob);
    if ($dob === '' || $dob === '0000-00-00') {
        return null;
    }
    try {
        $born = new DateTimeImmutable($dob);
    } catch (\Throwable $ignored) {
        return null;
    }
    $now = new DateTimeImmutable('today');
    if ($born > $now) {
        return null;
    }
    return (int) $born->diff($now)->y;
}

/** "Mar 04, 2007", or an em dash when no birth date is on file. */
function gjc_dob_label(?string $dob): string
{
    if (gjc_age_from_dob($dob) === null) {
        return '—';
    }
    return date('M d, Y', strtotime((string) $dob));
}

/** "19 years old", or an em dash with no birth date. */
function gjc_age_label(?string $dob): string
{
    $age = gjc_age_from_dob($dob);
    return $age === null ? '—' : $age . ' years old';
}

/** Address as one line, skipping blank parts. */
function gjc_format_address(array $row): string
{
    $parts = array_filter([
        trim((string) ($row['address_barangay'] ?? '')),
        trim((string) ($row['address_city'] ?? '')),
        trim((string) ($row['address_province'] ?? '')),
    ], static fn(string $part): bool => $part !== '');

    return implode(', ', $parts);
}

/** Validate submitted personal details. Returns ['values' => [...], 'error' => ?string]; blanks become NULL. */
function gjc_collect_profile_details(array $post, array $columns): array
{
    $blank = static function (string $key) use ($post): ?string {
        $value = trim((string) ($post[$key] ?? ''));
        return $value === '' ? null : $value;
    };

    $sex = $blank('sex');
    if ($sex !== null && !in_array($sex, gjc_sex_options(), true)) {
        return ['values' => [], 'error' => 'Pick one of the listed options for Sex.'];
    }

    $dob = $blank('date_of_birth');
    if ($dob !== null) {
        $ts = strtotime($dob);
        if ($ts === false) {
            return ['values' => [], 'error' => 'Enter your date of birth as a valid date.'];
        }
        $dob = date('Y-m-d', $ts);
        if ($ts > strtotime('today')) {
            return ['values' => [], 'error' => 'Date of birth cannot be in the future.'];
        }
        if (gjc_age_from_dob($dob) > GJC_MAX_AGE) {
            return ['values' => [], 'error' => 'Check the date of birth — that works out to over ' . GJC_MAX_AGE . ' years old.'];
        }
    }

    $values = [
        'sex'                        => $sex,
        'date_of_birth'              => $dob,
        'address_barangay'           => $blank('address_barangay'),
        'address_city'               => $blank('address_city'),
        'address_province'           => $blank('address_province'),
    ];

    $profileFields = array_unique(array_merge($columns, [
        'address_barangay', 'address_city', 'address_province',
    ]));
    return [
        'values' => array_intersect_key($values, array_flip($profileFields)),
        'error'  => null,
    ];
}

function gjc_generate_student_id(PDO $db): string
{
    // Year comes from the active school year, falling back to the current year.
    $activeYearName = gjc_active_school_year_name($db);
    $year = $activeYearName !== null ? substr($activeYearName, 0, 4) : date('Y');

    $stmt = $db->query(
        "SELECT MAX(CAST(SUBSTRING_INDEX(studentID, '-', -1) AS UNSIGNED))
           FROM student_info
          WHERE studentID REGEXP '^GJC[0-9]{4}-[0-9]+$'"
    );
    $max = (int) $stmt->fetchColumn();
    return sprintf('GJC%s-%04d', $year, $max + 1);
}

/**
 * Year level per school year lives on student_enrollments; student_info keeps entry_yr_lvl.
 * DDL: never call inside a transaction.
 */
function gjc_ensure_student_entry_year_schema(PDO $db): void
{
    static $ensured = false;
    if ($ensured || $db->inTransaction() || !gjc_table_exists($db, 'student_info')) {
        return;
    }
    $ensured = true;

    $cols = gjc_table_columns($db, 'student_info');
    if (!in_array('entry_yr_lvl', $cols, true)) {
        $after = in_array('yr_lvl', $cols, true) ? ' AFTER yr_lvl' : '';
        $db->exec("ALTER TABLE student_info ADD COLUMN entry_yr_lvl VARCHAR(11) NULL{$after}");
        gjc_table_columns($db, 'student_info', true);
        $cols = gjc_table_columns($db, 'student_info');
    }
    if (in_array('yr_lvl', $cols, true)) {
        $db->exec("UPDATE student_info SET entry_yr_lvl = yr_lvl WHERE entry_yr_lvl IS NULL");
    }
}

/** Columns for writing the entry year level. Returns [columnSql, placeholderSql, count]. */
function gjc_student_entry_year_write_columns(PDO $db): array
{
    $cols = gjc_table_columns($db, 'student_info');
    $names = array_values(array_intersect(['yr_lvl', 'entry_yr_lvl'], $cols));
    if (!$names) {
        throw new RuntimeException('student_info has no year level column.');
    }
    return [implode(', ', $names), implode(', ', array_fill(0, count($names), '?')), count($names)];
}

function gjc_ensure_student_info_record(PDO $db, int $userId, string $studentId): void
{
    $existing = $db->prepare("SELECT stud_infoID FROM student_info WHERE userID = ? LIMIT 1");
    $existing->execute([$userId]);
    if ($existing->fetchColumn()) {
        $db->prepare("UPDATE student_info SET studentID = ? WHERE userID = ?")->execute([$studentId, $userId]);
        return;
    }

    // Resolve a valid courseID — use the first existing one, or create a placeholder.
    $courseId = (int) $db->query("SELECT courseID FROM course ORDER BY courseID ASC LIMIT 1")->fetchColumn();
    if ($courseId === 0) {
        $db->exec("INSERT INTO course (course_code, course_name) VALUES ('GENERAL', 'General')");
        $courseId = (int) $db->lastInsertId();
    }

    [$yrCols, $yrMarks, $yrCount] = gjc_student_entry_year_write_columns($db);
    $db->prepare("INSERT INTO student_info (userID, studentID, {$yrCols}, courseID) VALUES (?, ?, {$yrMarks}, ?)")
       ->execute(array_merge([$userId, $studentId], array_fill(0, $yrCount, '1'), [$courseId]));
}

function gjc_backfill_student_ids(PDO $db): void
{
    if (!gjc_table_exists($db, 'student_info') || !gjc_table_exists($db, 'users')) {
        return;
    }
    gjc_ensure_student_entry_year_schema($db);
    $stmt = $db->query(
        "SELECT u.userID
           FROM users u
           LEFT JOIN student_info si ON si.userID = u.userID
          WHERE u.roleID = 1
            AND (si.stud_infoID IS NULL
              OR si.studentID = ''
              OR si.studentID NOT REGEXP '^GJC[0-9]{4}-[0-9]+$')
          ORDER BY u.userID ASC"
    );
    $missing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($missing as $userId) {
        $newId = gjc_generate_student_id($db);
        gjc_ensure_student_info_record($db, (int) $userId, $newId);
    }
}

/** Session CSRF token; sent as X-CSRF-Token header or csrf_token field. */
function gjc_csrf_token(): string
{
    if (empty($_SESSION["gjc_csrf_token"])) {
        $_SESSION["gjc_csrf_token"] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION["gjc_csrf_token"];
}

function gjc_csrf_verify(?string $token = null): bool
{
    $known = (string) ($_SESSION["gjc_csrf_token"] ?? "");
    if ($known === "") {
        return false;
    }

    $given = $token
        ?? $_SERVER["HTTP_X_CSRF_TOKEN"]
        ?? $_POST["csrf_token"]
        ?? "";

    return is_string($given) && $given !== "" && hash_equals($known, $given);
}

/** Refuse a state-changing request that lacks the session's CSRF token (JSON endpoints). */
function gjc_csrf_require_json(): void
{
    if (gjc_csrf_verify()) {
        return;
    }
    http_response_code(403);
    header("Content-Type: application/json");
    echo json_encode(["success" => false, "code" => "csrf", "message" => "Security check failed. Please refresh the page and try again."]);
    exit;
}

// ─── Phase 2: Role & Auth Layer helpers ──────────────────────────────────────

function gjc_sub_role(): string
{
    return strtolower((string) ($_SESSION["sub_role"] ?? ""));
}

function gjc_is_merchant_admin(): bool
{
    return gjc_sub_role() === "merchant_admin";
}

function gjc_is_merchant_staff(): bool
{
    return gjc_sub_role() === "merchant_staff";
}
