<?php
// CLI regression: wrong passwords typed by email and by student ID count toward the
// same account, so an account gets five tries in total, not five per identifier.
// Calls the real sign-in method against temporary users, student_info and
// login_attempts tables, inside a transaction whose audit entries are discarded.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../record.php';

function lock_expect(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException("FAIL: {$label}"); }
    echo "PASS: {$label}\n";
}

$db->exec('CREATE TEMPORARY TABLE users (
    userID INT PRIMARY KEY, email VARCHAR(255), password VARCHAR(255), roleID INT, status VARCHAR(20))');
$db->exec('CREATE TEMPORARY TABLE student_info (userID INT PRIMARY KEY, studentID VARCHAR(30))');
$db->exec('CREATE TEMPORARY TABLE login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, login_key CHAR(64) NOT NULL,
    failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (login_key, failed_at)) ENGINE=InnoDB');
// Runs the schema DDL now, outside the test transaction (DDL would commit it).
gjc_ensure_login_attempts_schema($db);

$db->prepare('INSERT INTO users VALUES (501, ?, ?, 1, ?)')
    ->execute(['lock-test@example.invalid', password_hash('right-password', PASSWORD_BCRYPT), 'Active']);
$db->exec("INSERT INTO student_info VALUES (501, 'LT-0001')");
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';

$signIn = static function (string $identifier, string $password) use ($db): string {
    $_POST = ['login' => '', 'email' => $identifier, 'password' => $password];
    return (string) (new \Classes\Record($db))->loginUser();
};
$wrong = 'Invalid email/ID or password.';
$locked = 'Too many failed sign-in attempts. Try again in 15 minutes.';

// A successful sign-in redirects and calls exit, which would end this script
// quietly. Treat that as a failure: it means a locked account was let in.
$finished = false;
register_shutdown_function(static function () use (&$finished): void {
    if (!$finished) {
        echo "FAIL: sign-in ended the script before the checks finished (a locked account got in?)\n";
        exit(1);
    }
});

$db->beginTransaction();
try {
    for ($i = 1; $i <= 3; $i++) {
        lock_expect($signIn('lock-test@example.invalid', 'nope') === $wrong, "wrong password by email, try {$i}");
    }
    for ($i = 4; $i <= 5; $i++) {
        lock_expect($signIn('LT-0001', 'nope') === $wrong, "wrong password by student ID, try {$i}");
    }
    lock_expect($signIn('lock-test@example.invalid', 'right-password') === $locked,
        'after 5 mixed tries, the email is locked even with the right password');
    lock_expect($signIn('LT-0001', 'right-password') === $locked,
        'the student ID is locked too: both count toward one account');
    lock_expect($signIn('nobody@example.invalid', 'nope') === $wrong,
        'other identifiers are unaffected');
    echo "Account lock checks passed.\n";
} finally {
    $finished = true; // exit() skips finally, so this only runs if no sign-in got through
    $db->rollBack();
    $queues = &gjc_audit_queue();
    unset($queues[spl_object_id($db)]);
    $db->exec('DROP TEMPORARY TABLE IF EXISTS login_attempts');
    $db->exec('DROP TEMPORARY TABLE IF EXISTS student_info');
    $db->exec('DROP TEMPORARY TABLE IF EXISTS users');
}
