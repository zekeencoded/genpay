<?php
// ============================================================
//  admin/api/school_years.php
//  School Year Cycles & Enrollment Lifecycle — per-year enrollment records,
//  registrar roster upload + diff review, admin-driven year rollover, and
//  student account locking.
//
//  Enrollment is explicit: a student is enrolled for a year because a roster
//  row (or an admin) says so, never because they merely haven't graduated.
//  student_enrollments is written ONLY through gjc_set_enrollment_status().
//
//  No money ever moves here: rollover never calls CirculationEngine, never
//  writes to `transactions`, and never touches student_wallets.balance.
//  student_wallets.is_frozen is the only wallet column this file can change.
//  school_year_balances is the sole record of a year-end carry-over.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';
require_once __DIR__ . '/../../connection/school_year_enrollment.php';
require_once __DIR__ . '/../../connection/audit_logger.php';

header('Content-Type: application/json; charset=UTF-8');
gjc_require_role(['finance']);
gjc_ensure_audit_table($db);
gjc_ensure_school_year_schema($db);
gjc_ensure_enrollment_schema($db);

$action    = trim((string) ($_POST['action'] ?? ''));
$adminId   = gjc_user_id();
$adminRole = gjc_current_role();

/** How many bucket rows the diff ships back. Counts are always exact. */
const SY_DIFF_ROW_CAP = 500;

function sy_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

/** One student's identity + wallet + enrollment state, or null. */
function sy_fetch_student(PDO $db, int $studentUserId): ?array
{
    $activeYearId = gjc_active_school_year_id($db);

    $stmt = $db->prepare(
        "SELECT u.userID AS student_user_id,
                TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS name,
                si.studentID, si.graduated_at,
                COALESCE(se.yr_lvl, si.yr_lvl) AS yr_lvl,
                se.status AS enrollment_status,
                sw.id AS wallet_id, sw.balance, sw.is_frozen
           FROM users u
           LEFT JOIN student_info si ON si.userID = u.userID
           LEFT JOIN student_enrollments se
                  ON se.student_user_id = u.userID AND se.school_year_id = ?
           LEFT JOIN student_wallets sw ON sw.user_id = u.userID
          WHERE u.userID = ? AND u.roleID = 1
          LIMIT 1"
    );
    $stmt->execute([$activeYearId ?? 0, $studentUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'student_user_id'   => (int) $row['student_user_id'],
        'name'              => trim((string) $row['name']),
        'studentID'         => (string) ($row['studentID'] ?? ''),
        'yr_lvl'            => (string) ($row['yr_lvl'] ?? ''),
        'graduated_at'      => $row['graduated_at'],
        'enrollment_status' => $row['enrollment_status'] !== null ? (string) $row['enrollment_status'] : null,
        'wallet_state'      => gjc_student_wallet_state($db, (int) $row['student_user_id']),
        'wallet_id'         => $row['wallet_id'] !== null ? (int) $row['wallet_id'] : null,
        'balance'           => (float) ($row['balance'] ?? 0),
        'is_frozen'         => (bool) ($row['is_frozen'] ?? false),
    ];
}

/** A target year must exist and still be inactive — you stage a roster into the year you are about to open. */
function sy_require_inactive_year(PDO $db, int $schoolYearId): array
{
    if ($schoolYearId <= 0) {
        throw new RuntimeException('Select a school year.');
    }
    $stmt = $db->prepare("SELECT id, school_year_name, is_active FROM school_years WHERE id = ?");
    $stmt->execute([$schoolYearId]);
    $year = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$year) {
        throw new RuntimeException('School year not found.');
    }
    if ((int) $year['is_active'] === 1) {
        throw new RuntimeException('That school year is already active. Upload a roster into the year you are rolling over into, not the current one.');
    }
    return $year;
}

/**
 * Parses a registrar roster CSV. Columns are matched case-insensitively by
 * header name and may appear in any order; unrecognised columns are ignored so
 * a straight export from the enrollment system doesn't have to be trimmed down
 * first. Same fgetcsv/BOM handling as admin/maintenance.php's student import.
 *
 *   student_id_number  required — the school-issued ID, matched against
 *                      student_info.studentID. Never matched by name.
 *   yr_lvl             optional
 *   status             optional, defaults to 'enrolled'
 */
function sy_parse_roster_csv(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException('Unable to read the uploaded CSV file.');
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new RuntimeException('The CSV file is empty.');
    }

    // Strip a UTF-8 BOM Excel likes to prepend to the first header cell.
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    }

    $known = ['student_id_number', 'yr_lvl', 'status'];
    $index = [];
    foreach ($header as $pos => $name) {
        $name = strtolower(trim((string) $name));
        if ($name === '' || !in_array($name, $known, true)) {
            continue;
        }
        if (isset($index[$name])) {
            fclose($handle);
            throw new RuntimeException("Duplicate CSV column '{$name}'.");
        }
        $index[$name] = $pos;
    }

    if (!isset($index['student_id_number'])) {
        fclose($handle);
        throw new RuntimeException("CSV is missing the required 'student_id_number' column.");
    }

    $validStatuses = gjc_enrollment_statuses();
    $rows          = [];
    $rejected      = [];
    $rowNumber     = 1;

    while (($data = fgetcsv($handle)) !== false) {
        $rowNumber++;
        if (count(array_filter($data, static fn($value) => trim((string) $value) !== '')) === 0) {
            continue;
        }

        $studentIdNumber = trim((string) ($data[$index['student_id_number']] ?? ''));
        if ($studentIdNumber === '') {
            $rejected[] = ['row' => $rowNumber, 'reason' => 'Blank student_id_number.'];
            continue;
        }

        $yrLvl = isset($index['yr_lvl']) ? trim((string) ($data[$index['yr_lvl']] ?? '')) : '';

        $status = isset($index['status']) ? strtolower(trim((string) ($data[$index['status']] ?? ''))) : '';
        if ($status === '') {
            $status = 'enrolled';
        }
        if (!in_array($status, $validStatuses, true)) {
            $rejected[] = ['row' => $rowNumber, 'reason' => "Unrecognised status '{$status}' for {$studentIdNumber}."];
            continue;
        }

        $rows[] = [
            'row_number'        => $rowNumber,
            'student_id_number' => substr($studentIdNumber, 0, 50),
            'yr_lvl'            => $yrLvl !== '' ? substr($yrLvl, 0, 20) : null,
            'status'            => $status,
        ];
    }

    fclose($handle);

    if (!$rows) {
        throw new RuntimeException('No usable student rows were found in the CSV file.');
    }

    return ['rows' => $rows, 'rejected' => $rejected];
}

/** Compare the uploaded statuses with existing students for the activation preview.
 * Missing students and roster students with a non-enrolled status are frozen.
 */
function sy_roster_diff(PDO $db, int $newYearId, ?string $batchId = null): array
{
    $activeYearId = gjc_active_school_year_id($db);

    $params = [$newYearId];
    $batchSql = '';
    if ($batchId !== null && $batchId !== '') {
        $batchSql = ' AND batch_id = ?';
        $params[] = $batchId;
    }

    $stagedStmt = $db->prepare(
        "SELECT matched_user_id, student_id_number, yr_lvl, status, match_status
           FROM enrollment_roster_staging
          WHERE school_year_id = ?{$batchSql}"
    );
    $stagedStmt->execute($params);
    $stagedRows = $stagedStmt->fetchAll(PDO::FETCH_ASSOC);

    $stagedIds       = [];
    $stagedYrLvl     = [];
    $enrolledIds = [];
    $frozenRosterIds = [];
    $unmatchedNumbers = [];
    foreach ($stagedRows as $row) {
        if ((string) $row['match_status'] === 'matched' && $row['matched_user_id'] !== null) {
            $id = (int) $row['matched_user_id'];
            $stagedIds[$id]   = $id;
            if ($row['status'] === 'enrolled') $enrolledIds[$id] = $id;
            else $frozenRosterIds[$id] = $id;
            $stagedYrLvl[$id] = $row['yr_lvl'] !== null ? (string) $row['yr_lvl'] : '';
        } else {
            $unmatchedNumbers[] = (string) $row['student_id_number'];
        }
    }

    $activeEnrolled = [];
    if ($activeYearId !== null) {
        $enrStmt = $db->prepare(
            "SELECT student_user_id FROM student_enrollments WHERE school_year_id = ? AND status = 'enrolled'"
        );
        $enrStmt->execute([$activeYearId]);
        foreach ($enrStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $activeEnrolled[(int) $id] = (int) $id;
        }
    }

    $returningIds    = array_values(array_intersect_key($enrolledIds, $activeEnrolled));
    $newIds          = array_values(array_diff_key($enrolledIds, $activeEnrolled));
    $allStudents = [];
    foreach ($db->query("SELECT si.userID FROM student_info si JOIN users u ON u.userID = si.userID WHERE u.roleID = 1")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $allStudents[(int) $id] = (int) $id;
    }
    $notReturningIds = array_values(array_diff_key($allStudents, $stagedIds));
    $frozenIds = array_values(array_unique(array_merge($notReturningIds, array_values($frozenRosterIds))));

    return [
        'active_school_year_id' => $activeYearId,
        'staged_total'          => count($stagedRows),
        'staged_matched'        => count($stagedIds),
        'staged_unmatched'      => count($unmatchedNumbers),
        'unmatched_numbers'     => array_slice($unmatchedNumbers, 0, SY_DIFF_ROW_CAP),
        'returning_ids'         => $returningIds,
        'new_ids'               => $newIds,
        'not_returning_ids'     => $notReturningIds,
        'roster_yr_lvl'         => $stagedYrLvl,
        'frozen_ids' => $frozenIds,
    ];
}

/**
 * Name / studentID / year level / balance for a set of student user ids.
 * $rosterYrLvl overrides the stored year level for roster rows, so the new
 * year's level shows rather than last year's.
 */
function sy_student_rows(PDO $db, array $userIds, array $rosterYrLvl = []): array
{
    if (!$userIds) {
        return [];
    }

    $activeYearId = gjc_active_school_year_id($db);
    $out = [];

    foreach (array_chunk($userIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $db->prepare(
            "SELECT u.userID AS student_user_id,
                    TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS name,
                    si.studentID,
                    COALESCE(se.yr_lvl, si.yr_lvl) AS yr_lvl,
                    COALESCE(sw.balance, 0) AS balance
               FROM users u
               LEFT JOIN student_info si ON si.userID = u.userID
               LEFT JOIN student_enrollments se
                      ON se.student_user_id = u.userID AND se.school_year_id = ?
               LEFT JOIN student_wallets sw ON sw.user_id = u.userID
              WHERE u.userID IN ({$placeholders})
              ORDER BY name ASC"
        );
        $stmt->execute(array_merge([$activeYearId ?? 0], $chunk));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['student_user_id'];
            $yrLvl = (string) ($row['yr_lvl'] ?? '');
            if (isset($rosterYrLvl[$id]) && $rosterYrLvl[$id] !== '') {
                $yrLvl = $rosterYrLvl[$id];
            }
            $out[] = [
                'student_user_id' => $id,
                'name'            => trim((string) $row['name']),
                'studentID'       => (string) ($row['studentID'] ?? ''),
                'yr_lvl'          => $yrLvl,
                'balance'         => (float) $row['balance'],
            ];
        }
    }

    return $out;
}

try {
    switch ($action) {

        // ── Read-only: one student's info for the manual-override lookup ─
        case 'student_lookup': {
            $studentUserId = (int) ($_POST['student_user_id'] ?? 0);
            if (isset($_POST['student_id_number'])) {
                $lookup = $db->prepare('SELECT userID FROM student_info WHERE studentID = ? LIMIT 1');
                $lookup->execute([trim((string) $_POST['student_id_number'])]);
                $studentUserId = (int) $lookup->fetchColumn();
            }
            $student = $studentUserId > 0 ? sy_fetch_student($db, $studentUserId) : null;
            if (!$student) {
                sy_json(['success' => false, 'message' => 'Student not found.']);
            }
            sy_json(['success' => true, 'student' => $student]);
        }

        case 'set_student_status': {
            $yearId = (int) ($_POST['school_year_id'] ?? 0);
            if ($yearId <= 0 || $yearId !== gjc_active_school_year_id($db)) {
                sy_json(['success' => false, 'message' => 'The active school year changed. Reload and try again.']);
            }
            $id = (int) ($_POST['student_user_id'] ?? 0);
            $status = strtolower(trim((string) ($_POST['status'] ?? '')));
            $student = sy_fetch_student($db, $id);
            if (!$student || !in_array($status, gjc_enrollment_statuses(), true)) {
                sy_json(['success' => false, 'message' => 'Select a valid student and status.']);
            }
            if ($student['graduated_at'] !== null && $status !== 'graduated' && gjc_sub_role() !== 'super_admin') {
                sy_json(['success' => false, 'message' => 'Only a super admin can reverse a graduation.']);
            }
            $db->beginTransaction();
            try {
                $year = $db->prepare('SELECT is_active FROM school_years WHERE id = ? FOR UPDATE');
                $year->execute([$yearId]);
                if ((int) $year->fetchColumn() !== 1) {
                    throw new RuntimeException('The active school year changed. Reload and try again.');
                }
                $student = sy_fetch_student($db, $id);
                if ($student['graduated_at'] !== null && $status !== 'graduated' && gjc_sub_role() !== 'super_admin') {
                    throw new RuntimeException('Only a super admin can reverse a graduation.');
                }
                if (!gjc_set_enrollment_status($db, $id, $yearId, $status, 'manual', $adminId, $student['yr_lvl'] ?: null)) {
                    throw new RuntimeException('Could not update student status.');
                }
                $db->commit();
                gjc_audit_commit($db);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                gjc_audit_rollback($db, $e->getMessage());
                throw $e;
            }
            sy_json(['success' => true, 'message' => 'Student status updated. Balance preserved; withdrawals through Finance remain available.']);
        }

        // ── Read-only: diff bucket counts for the rollover confirm dialog ──
        case 'preview_rollover': {
            $newYearId = (int) ($_POST['new_school_year_id'] ?? 0);
            if ($newYearId <= 0) {
                sy_json(['success' => false, 'message' => 'Select a school year to roll over into.']);
            }

            $active = $db->query("SELECT id, school_year_name FROM school_years WHERE is_active = 1 LIMIT 1")
                ->fetch(PDO::FETCH_ASSOC);

            $newStmt = $db->prepare("SELECT id, school_year_name FROM school_years WHERE id = ?");
            $newStmt->execute([$newYearId]);
            $new = $newStmt->fetch(PDO::FETCH_ASSOC);
            if (!$new) {
                sy_json(['success' => false, 'message' => 'School year not found.']);
            }

            $diff = sy_roster_diff($db, $newYearId);

            // The number that decides whether this rollover is safe to run: how
            // many wallets are about to be frozen with money still in them.
            $frozenWithBalance = 0;
            $frozenBalanceTotal = 0.0;
            foreach (sy_student_rows($db, $diff['frozen_ids']) as $row) {
                if ($row['balance'] > 0) {
                    $frozenWithBalance++;
                    $frozenBalanceTotal += $row['balance'];
                }
            }

            sy_json([
                'success'                 => true,
                'old_school_year_id'      => $active ? (int) $active['id'] : null,
                'old_school_year_name'    => $active ? (string) $active['school_year_name'] : null,
                'new_school_year_id'      => (int) $new['id'],
                'new_school_year_name'    => (string) $new['school_year_name'],
                'has_roster'              => $diff['staged_matched'] > 0,
                'roster_rows'             => $diff['staged_total'],
                'enrolled_count'          => count($diff['returning_ids']) + count($diff['new_ids']),
                'returning_count'         => count($diff['returning_ids']),
                'new_count'               => count($diff['new_ids']),
                'not_returning_count'     => count($diff['not_returning_ids']),
                'frozen_count'            => count($diff['frozen_ids']),
                'unmatched_count'         => $diff['staged_unmatched'],
                'frozen_with_balance'     => $frozenWithBalance,
                'frozen_balance_total'    => round($frozenBalanceTotal, 2),
            ]);
        }

        // ── Create a new (inactive) school year ─────────────────────────
        case 'create_year': {
            $name      = trim((string) ($_POST['school_year_name'] ?? ''));
            $startDate = trim((string) ($_POST['start_date'] ?? '')) ?: null;
            $endDate   = trim((string) ($_POST['end_date'] ?? '')) ?: null;

            if (!gjc_validate_school_year_name($name)) {
                sy_json(['success' => false, 'message' => 'Enter a school year in the format YYYY-YYYY, e.g. 2025-2026.']);
            }

            $dupStmt = $db->prepare("SELECT id FROM school_years WHERE school_year_name = ?");
            $dupStmt->execute([$name]);
            if ($dupStmt->fetchColumn()) {
                sy_json(['success' => false, 'message' => 'That school year already exists.']);
            }

            $stmt = $db->prepare(
                "INSERT INTO school_years (school_year_name, start_date, end_date, is_active) VALUES (?, ?, ?, 0)"
            );
            $stmt->execute([$name, $startDate, $endDate]);
            $newId = (int) $db->lastInsertId();

            logAudit(
                $db, $adminId, $adminRole, 'SCHOOL_YEAR_CREATED', 'school_years',
                null,
                ['school_year_id' => $newId, 'school_year_name' => $name],
                null,
                ['target_id' => $newId]
            );

            sy_json(['success' => true, 'message' => "School year {$name} created.", 'school_year_id' => $newId]);
        }

        // ── Registrar roster CSV -> staging, matched by studentID only ──
        // Staging is per-year, not per-batch: a fresh upload replaces whatever
        // was staged for that year, so rollover can never promote a stale batch
        // alongside the one the admin actually reviewed.
        case 'upload_roster': {
            $newYearId = (int) ($_POST['school_year_id'] ?? 0);
            $year = sy_require_inactive_year($db, $newYearId);

            if (empty($_FILES['roster_csv']) || (int) $_FILES['roster_csv']['error'] !== UPLOAD_ERR_OK) {
                sy_json(['success' => false, 'message' => 'Please choose a valid CSV file.']);
            }
            if ((int) $_FILES['roster_csv']['size'] > 5 * 1024 * 1024) {
                sy_json(['success' => false, 'message' => 'The roster file is too large (5MB maximum).']);
            }
            $ext = strtolower(pathinfo((string) ($_FILES['roster_csv']['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'], true)) {
                sy_json(['success' => false, 'message' => 'Upload a .csv file exported from the enrollment system.']);
            }

            $parsed   = sy_parse_roster_csv((string) $_FILES['roster_csv']['tmp_name']);
            $rows     = $parsed['rows'];
            $rejected = $parsed['rejected'];

            // Match on the school-issued ID, never on a name.
            $matchStmt = $db->prepare(
                "SELECT si.userID FROM student_info si
                   JOIN users u ON u.userID = si.userID AND u.roleID = 1
                  WHERE si.studentID = ? LIMIT 1"
            );

            $batchId          = bin2hex(random_bytes(16));
            $seen             = [];
            $duplicateCount   = 0;
            $matchedCount     = 0;
            $unmatchedNumbers = [];
            $staged           = [];

            foreach ($rows as $row) {
                $studentIdNumber = $row['student_id_number'];
                $key = strtolower($studentIdNumber);
                if (isset($seen[$key])) {
                    $duplicateCount++;
                    continue;
                }
                $seen[$key] = true;

                $matchStmt->execute([$studentIdNumber]);
                $userId = $matchStmt->fetchColumn();
                $userId = $userId === false ? null : (int) $userId;

                if ($userId === null) {
                    $unmatchedNumbers[] = $studentIdNumber;
                } else {
                    $matchedCount++;
                }

                $staged[] = [$newYearId, $batchId, $studentIdNumber, $row['yr_lvl'], $row['status'], $userId, $userId === null ? 'unmatched' : 'matched'];
            }

            $db->beginTransaction();
            try {
                $db->prepare("DELETE FROM enrollment_roster_staging WHERE school_year_id = ?")->execute([$newYearId]);

                $insertStmt = $db->prepare(
                    "INSERT INTO enrollment_roster_staging
                        (school_year_id, batch_id, student_id_number, yr_lvl, status, matched_user_id, match_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($staged as $values) {
                    $insertStmt->execute($values);
                }

                logAudit(
                    $db, $adminId, $adminRole, 'ROSTER_UPLOADED', 'enrollment_roster_staging',
                    null,
                    [
                        'school_year_id'   => $newYearId,
                        'school_year_name' => (string) $year['school_year_name'],
                        'batch_id'         => $batchId,
                        'file_name'        => (string) ($_FILES['roster_csv']['name'] ?? 'roster.csv'),
                        'total_rows'       => count($staged),
                        'matched'          => $matchedCount,
                        'unmatched'        => count($unmatchedNumbers),
                        'duplicates'       => $duplicateCount,
                        'rejected'         => count($rejected),
                    ],
                    null,
                    ['target_id' => $batchId]
                );

                $db->commit();
                gjc_audit_commit($db);
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                gjc_audit_rollback($db, $e->getMessage());
                throw $e;
            }

            sy_json([
                'success'           => true,
                'message'           => "Roster staged for {$year['school_year_name']}: " . count($staged) . ' row(s), '
                    . $matchedCount . ' matched, ' . count($unmatchedNumbers) . ' unmatched.',
                'batch_id'          => $batchId,
                'school_year_id'    => $newYearId,
                'school_year_name'  => (string) $year['school_year_name'],
                'total_rows'        => count($staged),
                'matched_count'     => $matchedCount,
                'unmatched_count'   => count($unmatchedNumbers),
                'unmatched_numbers' => array_slice($unmatchedNumbers, 0, SY_DIFF_ROW_CAP),
                'duplicate_count'   => $duplicateCount,
                'rejected_rows'     => array_slice($rejected, 0, SY_DIFF_ROW_CAP),
            ]);
        }

        // ── The rollover: claim old off, claim new on, promote the roster,
        //    snapshot balances, never touch a single peso ─────────────────
        // old_school_year_id may be 0/absent — that's the bootstrap case (no
        // school year has ever been active yet), which just activates the new
        // year and opens balances with nothing to archive out of.
        case 'rollover': {
            $oldYearId = (int) ($_POST['old_school_year_id'] ?? 0);
            $newYearId = (int) ($_POST['new_school_year_id'] ?? 0);

            if ($newYearId <= 0) {
                sy_json(['success' => false, 'message' => 'Select a school year to activate.']);
            }
            if ($oldYearId === $newYearId) {
                sy_json(['success' => false, 'message' => 'The new school year must be different from the current one.']);
            }

            // Rolling over with nothing staged would leave every student without
            // an enrollment row for the new year — the whole student body frozen
            // in one click. Refuse before anything else happens.
            $stagedStmt = $db->prepare("SELECT COUNT(*) FROM enrollment_roster_staging WHERE school_year_id = ? AND match_status = 'matched'");
            $stagedStmt->execute([$newYearId]);
            $stagedTotal = (int) $stagedStmt->fetchColumn();
            if ($stagedTotal === 0) {
                sy_json([
                    'success' => false,
                    'message' => 'Upload a roster with at least one matching student before activating this year.',
                ]);
            }

            $db->beginTransaction();
            try {
                $oldName = null;
                if ($oldYearId > 0) {
                    $oldNameStmt = $db->prepare("SELECT school_year_name FROM school_years WHERE id = ?");
                    $oldNameStmt->execute([$oldYearId]);
                    $oldName = $oldNameStmt->fetchColumn();
                    if ($oldName === false) {
                        throw new RuntimeException('The current school year record was not found.');
                    }
                }

                $newNameStmt = $db->prepare("SELECT school_year_name FROM school_years WHERE id = ? AND is_active = 0");
                $newNameStmt->execute([$newYearId]);
                $newName = $newNameStmt->fetchColumn();
                if ($newName === false) {
                    throw new RuntimeException('The selected new school year was not found or is already active.');
                }

                if ($oldYearId > 0) {
                    // Atomic test-and-set: only proceeds if the year the admin saw
                    // when the confirm dialog loaded is still the active one.
                    $offStmt = $db->prepare("UPDATE school_years SET is_active = 0 WHERE is_active = 1 AND id = ?");
                    $offStmt->execute([$oldYearId]);
                    if ($offStmt->rowCount() === 0) {
                        throw new RuntimeException('The current school year has changed since you loaded this page. Reload and try again.');
                    }
                } else {
                    // Bootstrap: there must genuinely be no active year right now,
                    // or we'd silently leave two years marked active at once.
                    $stillNone = (int) $db->query("SELECT COUNT(*) FROM school_years WHERE is_active = 1")->fetchColumn();
                    if ($stillNone > 0) {
                        throw new RuntimeException('A school year is already active. Reload and try again.');
                    }
                }

                $onStmt = $db->prepare("UPDATE school_years SET is_active = 1 WHERE id = ? AND is_active = 0");
                $onStmt->execute([$newYearId]);
                if ($onStmt->rowCount() === 0) {
                    throw new RuntimeException('Could not activate the new school year — it may already be active.');
                }

                // Archive every student wallet's ending balance into the closing
                // year (skipped entirely on bootstrap — there's no old year to
                // close out). ON DUPLICATE KEY UPDATE so a student who joined
                // mid-year (no starting_balance row yet) still gets archived.
                $archivedCount = 0;
                if ($oldYearId > 0) {
                    $archiveStmt = $db->prepare(
                        "INSERT INTO school_year_balances
                            (student_user_id, school_year_id, starting_balance, final_ending_balance, archived_at)
                         SELECT sw.user_id, ?, 0, sw.balance, NOW()
                           FROM student_wallets sw
                         ON DUPLICATE KEY UPDATE
                            final_ending_balance = VALUES(final_ending_balance),
                            archived_at = VALUES(archived_at)"
                    );
                    $archiveStmt->execute([$oldYearId]);
                    $archivedCount = $archiveStmt->rowCount();
                }

                // Promote the staged roster into real enrollment rows. Unmatched
                // student numbers are left behind on purpose — there is no account
                // to enrol. Audit is suppressed per row; one ROSTER_APPLIED entry
                // below records the batch instead of thousands of identical ones.
                $promotedCount = gjc_apply_school_year_roster($db, $newYearId, $adminId);

                $db->prepare("DELETE FROM enrollment_roster_staging WHERE school_year_id = ?")->execute([$newYearId]);

                // Open the new year from current balances — driven by who is
                // actually enrolled, not by "everyone who hasn't graduated".
                // INSERT IGNORE so a retried rollover can't duplicate.
                $openStmt = $db->prepare(
                    "INSERT IGNORE INTO school_year_balances (student_user_id, school_year_id, starting_balance)
                     SELECT sw.user_id, ?, sw.balance
                       FROM student_wallets sw
                       JOIN student_enrollments se
                            ON se.student_user_id = sw.user_id AND se.school_year_id = ?
                      WHERE se.status = 'enrolled'"
                );
                $openStmt->execute([$newYearId, $newYearId]);
                $openedCount = $openStmt->rowCount();

                $countStmt = $db->prepare(
                    "SELECT
                        SUM(status = 'enrolled')  AS enrolled,
                        SUM(status = 'graduated') AS graduated,
                        SUM(status NOT IN ('enrolled','graduated')) AS not_enrolled
                       FROM student_enrollments WHERE school_year_id = ?"
                );
                $countStmt->execute([$newYearId]);
                $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $enrolledCount    = (int) ($counts['enrolled'] ?? 0);
                $graduatedCount   = (int) ($counts['graduated'] ?? 0);
                $notEnrolledCount = (int) ($counts['not_enrolled'] ?? 0);

                logAudit(
                    $db, $adminId, $adminRole, 'SCHOOL_YEAR_ROLLOVER', 'school_years',
                    ['school_year_id' => $oldYearId, 'school_year_name' => $oldName],
                    [
                        'school_year_id'     => $newYearId,
                        'school_year_name'   => $newName,
                        'wallets_archived'   => $archivedCount,
                        'wallets_opened'     => $openedCount,
                        'enrolled_count'     => $enrolledCount,
                        'not_enrolled_count' => $notEnrolledCount,
                        'graduated_count'    => $graduatedCount,
                    ],
                    null,
                    ['target_id' => $newYearId]
                );

                logAudit(
                    $db, $adminId, $adminRole, 'ROSTER_APPLIED', 'student_enrollments',
                    null,
                    [
                        'school_year_id'   => $newYearId,
                        'school_year_name' => $newName,
                        'source'           => 'roster_import',
                        'staged_rows'      => $stagedTotal,
                        'promoted'         => $promotedCount,
                    ],
                    null,
                    ['target_id' => $newYearId]
                );

                $db->commit();
                gjc_audit_commit($db);
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                gjc_audit_rollback($db, $e->getMessage());
                throw $e;
            }

            sy_json([
                'success'            => true,
                'message'            => $oldName !== null
                    ? "Rolled over from {$oldName} to {$newName}."
                    : "{$newName} is now the active school year.",
                'wallets_archived'   => $archivedCount,
                'wallets_opened'     => $openedCount,
                'roster_promoted'    => $promotedCount,
                'enrolled_count'     => $enrolledCount,
                'not_enrolled_count' => $notEnrolledCount,
                'graduated_count'    => $graduatedCount,
            ]);
        }

        default:
            sy_json(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (RuntimeException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    gjc_audit_rollback($db, $e->getMessage());
    sy_json(['success' => false, 'message' => gjc_safe_error($e, 'admin/api/school_years')]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    gjc_audit_rollback($db, $e->getMessage());
    sy_json(['success' => false, 'message' => 'A server error occurred.']);
}
