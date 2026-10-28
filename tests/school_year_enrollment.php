<?php
// Connection-local fixtures shadow production tables. No real student records change.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/school_year_enrollment.php';
function sy_expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$db->exec('CREATE TEMPORARY TABLE users (userID INT PRIMARY KEY, roleID INT)');
$db->exec("ALTER TABLE users ADD status VARCHAR(20) DEFAULT 'Active', ADD suspended_until DATETIME NULL, ADD suspension_reason TEXT NULL, ADD suspended_at DATETIME NULL, ADD suspended_by INT NULL, ADD suspension_prev_status VARCHAR(20) NULL, ADD suspension_froze_wallet TINYINT DEFAULT 0, ADD lockout_kind VARCHAR(20) NULL");
$db->exec('CREATE TEMPORARY TABLE user_suspensions (user_id INT PRIMARY KEY, suspended_until DATETIME NULL, suspension_reason TEXT NULL, suspended_at DATETIME NULL, suspended_by INT NULL, suspension_prev_status VARCHAR(20) NULL, suspension_froze_wallet TINYINT DEFAULT 0, lockout_kind VARCHAR(20) NULL)');
$db->exec('CREATE TEMPORARY TABLE school_years (id INT PRIMARY KEY, is_active TINYINT)');
$db->exec('CREATE TEMPORARY TABLE student_info (userID INT PRIMARY KEY, graduated_at DATETIME NULL)');
$db->exec('CREATE TEMPORARY TABLE student_wallets (user_id INT PRIMARY KEY, balance DECIMAL(12,2), is_frozen TINYINT)');
$db->exec("CREATE TEMPORARY TABLE student_enrollments (student_user_id INT, school_year_id INT, status VARCHAR(30), yr_lvl VARCHAR(20), source VARCHAR(20), status_changed_at DATETIME, changed_by INT, PRIMARY KEY(student_user_id, school_year_id))");
$db->exec('CREATE TEMPORARY TABLE enrollment_roster_staging (school_year_id INT, matched_user_id INT NULL, yr_lvl VARCHAR(20), status VARCHAR(30), match_status VARCHAR(20))');
$db->exec('INSERT INTO school_years VALUES (901, 1)');
for ($id = 1; $id <= 6; $id++) {
    $db->exec("INSERT INTO users (userID, roleID) VALUES ($id, 1)");
    $db->exec("INSERT INTO student_info VALUES ($id, NULL)");
    $db->exec("INSERT INTO student_wallets VALUES ($id, 250.75, 0)");
}
$db->exec("UPDATE student_wallets SET is_frozen = 1 WHERE user_id = 1");
$db->exec("INSERT INTO enrollment_roster_staging VALUES (901,1,'1','enrolled','matched'), (901,2,'2','transferred','matched'), (901,3,'3','dropped','matched'), (901,4,'4','graduated','matched'), (901,NULL,'1','enrolled','unmatched')");
$db->beginTransaction();
try {
    sy_expect(gjc_apply_school_year_roster($db, 901, 99) === 4, 'Only matched students are promoted.');
    $statuses = $db->query('SELECT student_user_id, status FROM student_enrollments WHERE school_year_id = 901')->fetchAll(PDO::FETCH_KEY_PAIR);
    sy_expect($statuses === [1=>'enrolled',2=>'transferred',3=>'dropped',4=>'graduated',5=>'not_enrolled',6=>'not_enrolled'], 'Roster statuses and missing students must be recorded correctly.');
    sy_expect(gjc_student_wallet_state($db, 1) === 'active', 'Enrolled student has active enrollment.');
    foreach ([2,3,4,5] as $id) sy_expect(gjc_student_wallet_state($db, $id) !== 'active', 'Every other status must freeze wallet use.');
    foreach ([1,2,3,4,5] as $id) sy_expect(gjc_funds_in_block_reason($db, $id) !== null, 'Frozen wallets must reject incoming funds, including parent freezes.');
    sy_expect(!gjc_set_enrollment_status($db, 1, 901, 'on_leave', 'manual', 99, null, false), 'On Leave must be rejected.');
    sy_expect(gjc_set_enrollment_status($db, 6, 901, 'enrolled', 'manual', 99, null, false), 'Midyear enrollment can be corrected.');
    sy_expect(gjc_student_wallet_state($db, 6) === 'active', 'Corrected enrollment restores eligibility.');
    sy_expect(gjc_funds_in_block_reason($db, 6) === null, 'Active student wallets can receive funds.');
    sy_expect((int) $db->query('SELECT is_frozen FROM student_wallets WHERE user_id = 1')->fetchColumn() === 1, 'Enrollment must preserve an unrelated parent freeze.');
    sy_expect(gjc_set_enrollment_status($db, 4, 901, 'enrolled', 'manual', 99, null, false), 'Graduation can be reversed through the shared write path.');
    sy_expect($db->query('SELECT graduated_at FROM student_info WHERE userID = 4')->fetchColumn() === null, 'Graduation reversal clears the exit flag.');
    sy_expect((int) $db->query('SELECT COUNT(*) FROM student_wallets WHERE balance <> 250.75')->fetchColumn() === 0, 'No balance may change during roster activation or manual corrections.');
    $db->rollBack();
    echo "School year enrollment checks passed. No real records changed.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
