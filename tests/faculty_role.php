<?php
// Faculty (roleID 9): student portal, never enrollment-frozen, findable by Employee ID. Rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

$fail = static function (string $why): never { fwrite(STDERR, "FAIL: {$why}\n"); exit(1); };

if (gjc_role_name(GJC_FACULTY_ROLE_ID) !== 'student') $fail('faculty must use the student portal');
if (gjc_sub_role_for_role_id(GJC_FACULTY_ROLE_ID) !== 'faculty') $fail('sub_role must be faculty');
if (gjc_audit_role('faculty') !== 'Student') $fail('audit role must fit the user_role ENUM');

gjc_ensure_faculty_schema($db); // DDL: before the transaction
gjc_ensure_school_year_schema($db); // adds student_info.graduated_at
$courseId = gjc_faculty_course_id($db);
$db->beginTransaction();
try {
    $db->prepare("INSERT INTO users (last_name, first_name, email, contact_number, roleID, password, profile_img)
                  VALUES ('Test', 'Faculty', ?, '0', ?, 'x', '')")
       ->execute(['faculty.test.' . bin2hex(random_bytes(4)) . '@example.test', GJC_FACULTY_ROLE_ID]);
    $uid = (int) $db->lastInsertId();
    $empId = 'EMP-TEST-' . $uid;
    [$yrCols, $yrMarks, $yrCount] = gjc_student_entry_year_write_columns($db);
    $db->prepare("INSERT INTO student_info (userID, studentID, {$yrCols}, courseID, department) VALUES (?, ?, {$yrMarks}, ?, 'Science')")
       ->execute(array_merge([$uid, $empId], array_fill(0, $yrCount, ''), [$courseId]));
    $wallet = gjc_student_wallet($db, $uid);
    if (empty($wallet['id'])) $fail('faculty wallet not created');

    if (gjc_student_wallet_state($db, $uid) !== 'active') $fail('faculty must never be enrollment-frozen');
    if (gjc_funds_in_block_reason($db, $uid) !== null) $fail('faculty must be able to receive funds');

    // Same lookup transfers and top-ups use.
    $find = $db->prepare("SELECT u.userID FROM users u JOIN student_info si ON si.userID = u.userID
                           WHERE si.studentID = ? AND u.roleID IN (1, " . GJC_FACULTY_ROLE_ID . ") LIMIT 1");
    $find->execute([$empId]);
    if ((int) $find->fetchColumn() !== $uid) $fail('faculty not found by Employee ID');

    if (gjc_record_maintenance_fee($db, $uid, 'FACULTY-TEST-' . $uid, 'payment', 1000.0) !== 0.0) {
        $fail('faculty must not accrue maintenance fees');
    }

    // Offboarded faculty (graduated_at stamped) close like graduates. New user: wallet state is cached per user.
    $db->prepare("INSERT INTO users (last_name, first_name, email, contact_number, roleID, password, profile_img)
                  VALUES ('Test', 'Leaver', ?, '0', ?, 'x', '')")
       ->execute(['faculty.leaver.' . bin2hex(random_bytes(4)) . '@example.test', GJC_FACULTY_ROLE_ID]);
    $leaver = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO student_info (userID, studentID, {$yrCols}, courseID, department, graduated_at) VALUES (?, ?, {$yrMarks}, ?, 'Science', NOW())")
       ->execute(array_merge([$leaver, 'EMP-LEAVER-' . $leaver], array_fill(0, $yrCount, ''), [$courseId]));
    gjc_student_wallet($db, $leaver);
    if (gjc_student_wallet_state($db, $leaver) !== 'frozen_exit') $fail('offboarded faculty must be frozen_exit');
    if (gjc_funds_in_block_reason($db, $leaver) === null) $fail('offboarded faculty must not receive funds');
} finally {
    $db->rollBack();
}
echo "PASS: faculty role maps to student portal, stays active, is found by Employee ID, pays no maintenance fee, and closes like a graduate when offboarded.\n";
