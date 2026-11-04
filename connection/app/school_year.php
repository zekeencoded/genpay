<?php

// School years, enrollment and student wallet lock.

/** School year cycles; school_year_balances records year-end carry-over. */
function gjc_ensure_school_year_schema(PDO $db): void
{
    // DDL commits open transactions; never call inside one.
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $db->exec(
        "CREATE TABLE IF NOT EXISTS school_years (
            id               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_year_name VARCHAR(9) NOT NULL,
            start_date       DATE NULL,
            end_date         DATE NULL,
            is_active        TINYINT(1) NOT NULL DEFAULT 0,
            created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sy_name (school_year_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS school_year_balances (
            id                   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_user_id      INT NOT NULL,
            school_year_id       INT NOT NULL,
            starting_balance     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            final_ending_balance DECIMAL(10,2) NULL,
            archived_at          TIMESTAMP NULL,
            created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_student_sy (student_user_id, school_year_id),
            INDEX idx_syb_year (school_year_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    if (gjc_table_exists($db, 'transactions')) {
        $cols = gjc_table_columns($db, 'transactions');
        if (!in_array('school_year_id', $cols, true)) {
            try {
                $db->exec("ALTER TABLE transactions ADD COLUMN school_year_id INT NULL");
            } catch (\Throwable $ignored) {}
        }
        $hasIndex = (bool) $db->query(
            "SHOW INDEX FROM transactions WHERE Key_name = 'idx_txn_sy'"
        )->fetchColumn();
        if (!$hasIndex) {
            try {
                $db->exec("ALTER TABLE transactions ADD INDEX idx_txn_sy (school_year_id, id)");
            } catch (\Throwable $ignored) {}
        }
    }

    if (gjc_table_exists($db, 'student_info')) {
        $cols = gjc_table_columns($db, 'student_info');
        if (!in_array('graduated_at', $cols, true)) {
            try {
                $db->exec("ALTER TABLE student_info ADD COLUMN graduated_at DATETIME NULL");
            } catch (\Throwable $ignored) {}
        }
    }
}

/** '2025-2026' format, second year must be first + 1. No DB CHECK — validated here only. */
function gjc_validate_school_year_name(string $name): bool
{
    if (!preg_match('/^(\d{4})-(\d{4})$/', $name, $m)) {
        return false;
    }
    return ((int) $m[2]) === ((int) $m[1]) + 1;
}

/** Active school year id, or null. Runs no DDL (called inside money transactions). Cached. */
function gjc_active_school_year_id(PDO $db): ?int
{
    static $cached = false;
    static $cachedId = null;

    if ($cached) {
        return $cachedId;
    }
    $cached = true;

    if (!gjc_table_exists($db, 'school_years')) {
        return $cachedId = null;
    }

    $id = $db->query("SELECT id FROM school_years WHERE is_active = 1 LIMIT 1")->fetchColumn();
    return $cachedId = ($id !== false ? (int) $id : null);
}

/** Display name of the active school year, or null if none is active. Safe to call from any context — see gjc_active_school_year_id(). */
function gjc_active_school_year_name(PDO $db): ?string
{
    if (!gjc_table_exists($db, 'school_years')) {
        return null;
    }
    $name = $db->query("SELECT school_year_name FROM school_years WHERE is_active = 1 LIMIT 1")->fetchColumn();
    return $name !== false ? (string) $name : null;
}

/** Per-year enrollment records (student_enrollments). DDL: never call inside a transaction. */
function gjc_ensure_enrollment_schema(PDO $db): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    gjc_ensure_school_year_schema($db);
    gjc_ensure_student_entry_year_schema($db);

    $db->exec(
        "CREATE TABLE IF NOT EXISTS student_enrollments (
            id                 INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_user_id    INT NOT NULL,
            school_year_id     INT NOT NULL,
            status             ENUM('enrolled','not_enrolled','on_leave','dropped','transferred','graduated')
                               NOT NULL DEFAULT 'enrolled',
            yr_lvl             VARCHAR(20) NULL,
            source             ENUM('roster_import','manual','api') NOT NULL DEFAULT 'manual',
            enrolled_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status_changed_at  DATETIME NULL,
            changed_by         INT NULL,
            UNIQUE KEY uq_student_year (student_user_id, school_year_id),
            INDEX idx_enr_year_status (school_year_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Uploaded roster awaiting review; promoted at rollover.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS enrollment_roster_staging (
            id                INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_year_id    INT NOT NULL,
            batch_id          VARCHAR(36) NOT NULL,
            student_id_number VARCHAR(50) NOT NULL,
            yr_lvl            VARCHAR(20) NULL,
            status            ENUM('enrolled','not_enrolled','on_leave','dropped','transferred','graduated')
                              NOT NULL DEFAULT 'enrolled',
            matched_user_id   INT NULL,
            match_status      ENUM('matched','unmatched') NOT NULL DEFAULT 'unmatched',
            uploaded_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_staging_batch (batch_id),
            INDEX idx_staging_year (school_year_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $stagingCols = gjc_table_columns($db, 'enrollment_roster_staging', true);
    if (!in_array('status', $stagingCols, true)) {
        try {
            $db->exec(
                "ALTER TABLE enrollment_roster_staging
                 ADD COLUMN status ENUM('enrolled','not_enrolled','on_leave','dropped','transferred','graduated')
                 NOT NULL DEFAULT 'enrolled' AFTER yr_lvl"
            );
        } catch (\Throwable $ignored) {}
    }
}

/** The student_enrollments.status ENUM, in one place. */
function gjc_enrollment_statuses(): array
{
    return ['enrolled', 'not_enrolled', 'dropped', 'transferred', 'graduated'];
}

/** The student_enrollments.source ENUM. 'api' is reserved for the registrar integration. */
function gjc_enrollment_sources(): array
{
    return ['roster_import', 'manual', 'api'];
}

/**
 * The only write path for student_enrollments. Graduation also sets student_info.graduated_at.
 * $yrLvl null keeps the current level; $audit false skips per-row audit (bulk rollover).
 */
function gjc_set_enrollment_status(
    PDO $db,
    int $studentUserId,
    int $schoolYearId,
    string $status,
    string $source,
    ?int $changedBy,
    ?string $yrLvl = null,
    bool $audit = true
): bool {
    if ($studentUserId <= 0 || $schoolYearId <= 0) {
        return false;
    }

    $status = strtolower(trim($status));
    $source = strtolower(trim($source));
    if (!in_array($status, gjc_enrollment_statuses(), true)) {
        return false;
    }
    if (!in_array($source, gjc_enrollment_sources(), true)) {
        return false;
    }

    $yrLvl = ($yrLvl !== null && trim($yrLvl) !== '') ? substr(trim($yrLvl), 0, 20) : null;

    // Prior standing, needed to spot a move away from 'graduated'.
    $priorStmt = $db->prepare(
        "SELECT status FROM student_enrollments WHERE student_user_id = ? AND school_year_id = ? LIMIT 1"
    );
    $priorStmt->execute([$studentUserId, $schoolYearId]);
    $priorRaw = $priorStmt->fetchColumn();
    $priorStatus = $priorRaw === false ? null : (string) $priorRaw;

    $gradStmt = $db->prepare("SELECT graduated_at FROM student_info WHERE userID = ? LIMIT 1");
    $gradStmt->execute([$studentUserId]);
    $gradRaw = $gradStmt->fetchColumn();
    $priorGraduatedAt = ($gradRaw === false || $gradRaw === null) ? null : (string) $gradRaw;

    $upsert = $db->prepare(
        "INSERT INTO student_enrollments
            (student_user_id, school_year_id, status, yr_lvl, source, status_changed_at, changed_by)
         VALUES (?, ?, ?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE
            status            = VALUES(status),
            yr_lvl            = COALESCE(VALUES(yr_lvl), yr_lvl),
            source            = VALUES(source),
            status_changed_at = NOW(),
            changed_by        = VALUES(changed_by)"
    );
    $upsert->execute([$studentUserId, $schoolYearId, $status, $yrLvl, $source, $changedBy]);

    if ($status === 'graduated') {
        // graduated_at is only stamped once — a re-run keeps the original date.
        $db->prepare("UPDATE student_info SET graduated_at = NOW() WHERE userID = ? AND graduated_at IS NULL")
            ->execute([$studentUserId]);
        $db->prepare("UPDATE student_wallets SET is_frozen = 1 WHERE user_id = ?")
            ->execute([$studentUserId]);
    } elseif ($priorStatus === 'graduated' || $priorGraduatedAt !== null) {
        // Un-graduate; only unfreezes if the student was graduated.
        $db->prepare("UPDATE student_info SET graduated_at = NULL WHERE userID = ?")
            ->execute([$studentUserId]);
        $db->prepare("UPDATE student_wallets SET is_frozen = 0 WHERE user_id = ?")
            ->execute([$studentUserId]);
    }

    if ($audit && function_exists('logAudit')) {
        logAudit(
            $db,
            $changedBy ?? $studentUserId,
            gjc_current_role(),
            'ENROLLMENT_STATUS_SET',
            'student_enrollments',
            [
                'student_user_id' => $studentUserId,
                'school_year_id'  => $schoolYearId,
                'status'          => $priorStatus,
                'graduated_at'    => $priorGraduatedAt,
            ],
            [
                'student_user_id' => $studentUserId,
                'school_year_id'  => $schoolYearId,
                'status'          => $status,
                'source'          => $source,
                'yr_lvl'          => $yrLvl,
            ],
            null,
            ['target_id' => $studentUserId]
        );
    }

    return true;
}

/**
 * Wallet state: frozen_exit (graduated/transferred), frozen_unenrolled, or active.
 * No DDL (called inside transactions). Returns 'active' until enrollment is set up.
 */
function gjc_student_wallet_state(PDO $db, int $studentUserId): string
{
    static $cache = [];
    static $yearPopulated = -1;

    if ($studentUserId <= 0) {
        return 'active';
    }
    if (isset($cache[$studentUserId])) {
        return $cache[$studentUserId];
    }

    $roleStmt = $db->prepare("SELECT roleID FROM users WHERE userID = ? LIMIT 1");
    $roleStmt->execute([$studentUserId]);
    $isFaculty = (int) $roleStmt->fetchColumn() === GJC_FACULTY_ROLE_ID;

    // graduated_at doubles as the exit date: graduation for students, offboarding for faculty.
    if (gjc_table_exists($db, 'student_info')) {
        $stmt = $db->prepare("SELECT graduated_at FROM student_info WHERE userID = ? LIMIT 1");
        $stmt->execute([$studentUserId]);
        $graduatedAt = $stmt->fetchColumn();
        if ($graduatedAt !== false && $graduatedAt !== null) {
            return $cache[$studentUserId] = 'frozen_exit';
        }
    }
    // Faculty are never enrolled, so school-year freezes don't apply to them.
    if ($isFaculty) {
        return $cache[$studentUserId] = 'active';
    }

    $yearId = gjc_active_school_year_id($db);
    if ($yearId === null || !gjc_table_exists($db, 'student_enrollments')) {
        return $cache[$studentUserId] = 'active';
    }

    if ($yearPopulated === -1) {
        $popStmt = $db->prepare("SELECT EXISTS(SELECT 1 FROM student_enrollments WHERE school_year_id = ?)");
        $popStmt->execute([$yearId]);
        $yearPopulated = (int) $popStmt->fetchColumn();
    }
    if ($yearPopulated === 0) {
        return $cache[$studentUserId] = 'active';
    }

    $stmt = $db->prepare(
        "SELECT status FROM student_enrollments WHERE student_user_id = ? AND school_year_id = ? LIMIT 1"
    );
    $stmt->execute([$studentUserId, $yearId]);
    $status = $stmt->fetchColumn();
    if ($status === false) {
        return $cache[$studentUserId] = 'frozen_unenrolled';
    }

    return $cache[$studentUserId] = match ((string) $status) {
        'enrolled'                 => 'active',
        'graduated', 'transferred' => 'frozen_exit',
        default                    => 'frozen_unenrolled',
    };
}

/**
 * Redirect locked students away from money pages. HTML pages only, not JSON endpoints.
 * Unenrolled uses an allowlist so new money pages fail closed.
 */
function gjc_enforce_student_lock(PDO $db): void
{
    if (gjc_current_role() !== 'student') {
        return;
    }

    $state = gjc_student_wallet_state($db, gjc_user_id());
    if ($state === 'active') {
        return;
    }

    $script = basename(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''));

    if ($state === 'frozen_exit') {
        // Graduates may still send their remaining balance to another student.
        if ($script === 'dashboard.php' || $script === 'transfer.php') {
            return;
        }
        header('Location: ' . STUDENT_URL . '/dashboard.php');
        exit();
    }

    $unenrolledAllowed = [
        'dashboard.php',
        'profile.php',
        'profile_edit.php',
        'history.php',
        'view_transaction.php',
        'security.php',
    ];
    if (in_array($script, $unenrolledAllowed, true)) {
        return;
    }

    header('Location: ' . STUDENT_URL . '/dashboard.php');
    exit();
}
