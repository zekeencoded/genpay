<?php
/** Apply a roster inside the caller's activation transaction. Balances never move. */
function gjc_apply_school_year_roster(PDO $db, int $schoolYearId, int $changedBy): int
{
    if (!$db->inTransaction()) throw new LogicException('Roster activation requires a transaction.');
    $promoteStmt = $db->prepare(
        "SELECT matched_user_id, yr_lvl, status
           FROM enrollment_roster_staging
          WHERE school_year_id = ? AND match_status = 'matched' AND matched_user_id IS NOT NULL"
    );
    $promoteStmt->execute([$schoolYearId]);
    $rosterRows = $promoteStmt->fetchAll(PDO::FETCH_ASSOC);

    $promotedCount = 0;
    foreach ($rosterRows as $rosterRow) {
        $ok = gjc_set_enrollment_status(
            $db,
            (int) $rosterRow['matched_user_id'],
            $schoolYearId,
            (string) $rosterRow['status'],
            'roster_import',
            $changedBy,
            $rosterRow['yr_lvl'] !== null ? (string) $rosterRow['yr_lvl'] : null,
            false
        );
        if (!$ok) throw new RuntimeException('Roster contains an unsupported student status. Upload a corrected CSV.');
        $promotedCount++;
    }

    $missing = $db->prepare(
        "SELECT si.userID FROM student_info si
         JOIN users u ON u.userID = si.userID AND u.roleID = 1
         WHERE NOT EXISTS (
             SELECT 1 FROM enrollment_roster_staging r
             WHERE r.school_year_id = ? AND r.match_status = 'matched' AND r.matched_user_id = si.userID
         )"
    );
    $missing->execute([$schoolYearId]);
    foreach ($missing->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
        gjc_set_enrollment_status($db, (int) $studentId, $schoolYearId, 'not_enrolled', 'roster_import', $changedBy, null, false);
    }

    return $promotedCount;
}
