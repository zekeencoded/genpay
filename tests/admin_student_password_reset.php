<?php
// CLI regression: all credential and remember-token changes are rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/student_password_reset.php';

function reset_expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

try {
    $db->beginTransaction();
    $before = $db->query("SELECT * FROM users WHERE roleID = 1 LIMIT 1 FOR UPDATE")->fetch();
    reset_expect((bool) $before, 'A student fixture is required.');
    $id = (int) $before['userID'];
    gjc_establish_login_session($before);
    $hasRemember = gjc_table_exists($db, 'auth_remember_tokens');
    if ($hasRemember) {
        $db->prepare('INSERT INTO auth_remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$id, bin2hex(random_bytes(12)), hash('sha256', random_bytes(32)), date('Y-m-d H:i:s', time() + 3600)]);
    }
    $temporary = gjc_reset_student_password($db, $id);
    $read = $db->prepare('SELECT * FROM users WHERE userID = ?');
    $read->execute([$id]);
    $after = $read->fetch();
    reset_expect(password_verify($temporary, $after['password']), 'Temporary password must authenticate against the saved hash.');
    reset_expect($after['password'] !== $before['password'] && ($after['temp_password'] ?? null) === null, 'Replace the password without storing plaintext.');
    reset_expect((int) $after['force_password_change'] === 1, 'Reset must force a password change.');
    reset_expect($after['status'] === $before['status'], 'Reset must preserve account restrictions.');
    reset_expect(!gjc_password_session_valid($after), 'Existing session must be rejected.');
    unset($_SESSION['password_fingerprint']);
    reset_expect(!gjc_password_session_valid($after), 'Legacy session must be rejected.');
    gjc_establish_login_session($after);
    $_SESSION['force_change'] = true;
    reset_expect(gjc_password_session_valid($after), 'A new temporary-password login must reach the change-password page.');
    if ($hasRemember) {
        $tokens = $db->prepare('SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id = ?');
        $tokens->execute([$id]);
        reset_expect((int) $tokens->fetchColumn() === 0, 'All saved logins must be revoked.');
    }

    // Another reset must invalidate even a session already changing its password.
    $second = gjc_reset_student_password($db, $id);
    $read->execute([$id]);
    $again = $read->fetch();
    reset_expect($second !== $temporary && !password_verify($temporary, $again['password']), 'Repeated resets must replace the temporary password.');
    reset_expect(!gjc_password_session_valid($again), 'Repeated reset must invalidate the previous temporary login.');
    $staleUpdate = $db->prepare('UPDATE users SET password = ? WHERE userID = ? AND SHA2(password, 256) = ?');
    $staleUpdate->execute([password_hash('Regression-password', PASSWORD_BCRYPT), $id, $_SESSION['password_fingerprint']]);
    reset_expect($staleUpdate->rowCount() === 0, 'A stale change-password submission must not overwrite a reset.');

    // Run the actual update used by the change-password page with a fresh login.
    gjc_establish_login_session($again);
    $userId = $id;
    $hash = password_hash('Regression-new-password', PASSWORD_BCRYPT);
    $pageSource = file_get_contents(__DIR__ . '/../change_password.php');
    $start = strpos($pageSource, '$update = $db->prepare(');
    $end = strpos($pageSource, 'if ($update->rowCount()', $start);
    reset_expect($start !== false && $end !== false, 'Change-password update must be present.');
    eval(substr($pageSource, $start, $end - $start));
    reset_expect($update->rowCount() === 1, 'A fresh temporary login must be able to choose a new password.');
    $read->execute([$id]);
    $changed = $read->fetch();
    reset_expect(password_verify('Regression-new-password', $changed['password'])
        && (int) $changed['force_password_change'] === 0, 'Change-password page must save the new password and clear reset flags.');
    unset($_SESSION['password_fingerprint']);
    reset_expect(!gjc_password_session_valid($changed), 'Legacy sessions must stay invalid after completing password recovery.');
    $_SESSION['password_fingerprint'] = hash('sha256', $hash);
    reset_expect(gjc_password_session_valid($changed), 'The refreshed session must remain valid after changing password.');

    // Capture email delivery without connecting to SMTP or emailing real users.
    $db->exec('SAVEPOINT email_checks');
    $db->prepare('UPDATE users SET email = ? WHERE userID = ?')->execute(['reset-test@example.com', $id]);
    $capturedEmail = null;
    $recipient = gjc_reset_student_password_with_email($db, $id,
        static function ($to, $name, $subject, $html, $plain) use (&$capturedEmail): bool {
            $capturedEmail = compact('to', 'name', 'subject', 'html', 'plain');
            return true;
        });
    $read->execute([$id]);
    $emailedUser = $read->fetch();
    reset_expect($recipient === 'reset-test@example.com' && $capturedEmail['to'] === $recipient, 'Delivery must use the registered student email.');
    reset_expect((bool) preg_match('/Temporary password: (GP-[a-f0-9]{16})/', $capturedEmail['plain'], $match), 'Email must include the generated temporary password.');
    reset_expect(password_verify($match[1], $emailedUser['password']) && str_contains($capturedEmail['html'], $match[1]), 'Both email formats must contain the saved temporary password.');
    reset_expect(str_contains($capturedEmail['plain'], BASE_URL . '/login.php') && str_contains($capturedEmail['plain'], 'must choose a new password'), 'Email must explain where to sign in and change password.');
    $db->exec('ROLLBACK TO SAVEPOINT email_checks');

    $db->prepare('UPDATE users SET email = ? WHERE userID = ?')->execute(['invalid-email', $id]);
    $deliveryAttempted = false;
    $rejected = false;
    try {
        gjc_reset_student_password_with_email($db, $id, static function () use (&$deliveryAttempted): bool {
            $deliveryAttempted = true;
            return true;
        });
    } catch (InvalidArgumentException $e) { $rejected = true; }
    $read->execute([$id]);
    reset_expect($rejected && !$deliveryAttempted && $read->fetch()['password'] === $changed['password'], 'Invalid email must be rejected before changing the password or attempting delivery.');
    $db->exec('ROLLBACK TO SAVEPOINT email_checks');

    $db->prepare('UPDATE users SET email = ? WHERE userID = ?')->execute(['reset-test@example.com', $id]);
    $rejected = false;
    try {
        gjc_reset_student_password_with_email($db, $id, static fn(): bool => false);
    } catch (InvalidArgumentException $e) { $rejected = true; }
    reset_expect($rejected, 'Email failure must stop the reset from committing.');
    // The endpoint rolls back its transaction on this failure; simulate that rollback.
    $db->exec('ROLLBACK TO SAVEPOINT email_checks');
    $read->execute([$id]);
    reset_expect($read->fetch()['password'] === $changed['password'], 'Failed email reset must restore the previous password.');

    $nonStudent = $db->query('SELECT userID FROM users WHERE roleID <> 1 LIMIT 1')->fetchColumn();
    reset_expect($nonStudent !== false, 'A non-student fixture is required.');
    foreach ([0, (int) $nonStudent] as $invalidId) {
        $rejected = false;
        try { gjc_reset_student_password($db, $invalidId); }
        catch (InvalidArgumentException $e) { $rejected = true; }
        reset_expect($rejected, 'Missing and non-student accounts must be rejected.');
    }
    $_SESSION = [];
    reset_expect(!gjc_csrf_verify('invalid'), 'Missing CSRF session token must fail.');
    $csrf = gjc_csrf_token();
    reset_expect(gjc_csrf_verify($csrf) && !gjc_csrf_verify('invalid'), 'Only the matching CSRF token may pass.');
    $db->rollBack();
    echo "PASS: hashed reset, forced change, session invalidation, saved-login revocation, registered-email delivery, email contents, invalid-email guard, email-failure rollback, stale password changes, student-only guard and CSRF (writes rolled back; no emails sent).\n";
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
}
