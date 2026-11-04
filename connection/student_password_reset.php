<?php

require_once __DIR__ . '/mailer.php';

/** Caller owns the transaction; no temporary password is stored in plaintext. */
function gjc_reset_student_password(PDO $db, int $userId): string
{
    if (!$db->inTransaction()) {
        throw new LogicException('Password resets require a transaction.');
    }
    $read = $db->prepare('SELECT roleID FROM users WHERE userID = ? FOR UPDATE');
    $read->execute([$userId]);
    $user = $read->fetch(PDO::FETCH_ASSOC);
    if (!$user || !in_array((int) $user['roleID'], [1, 9], true)) { // 9 = faculty
        throw new InvalidArgumentException('Only student and faculty accounts can have their password reset here.');
    }

    $temporaryPassword = 'GP-' . bin2hex(random_bytes(8));
    $db->prepare(
        'UPDATE users SET password = ?, force_password_change = 1
         WHERE userID = ?'
    )->execute([password_hash($temporaryPassword, PASSWORD_BCRYPT), $userId]);
    if (gjc_table_exists($db, 'auth_remember_tokens')) {
        $db->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ?')->execute([$userId]);
    }
    return $temporaryPassword;
}

/** Caller commits only after SMTP accepts the email, or rolls back on failure. */
function gjc_reset_student_password_with_email(PDO $db, int $userId, ?callable $sendEmail = null): string
{
    if (!$db->inTransaction()) {
        throw new LogicException('Password resets require a transaction.');
    }
    $read = $db->prepare('SELECT email, first_name, last_name, roleID FROM users WHERE userID = ? FOR UPDATE');
    $read->execute([$userId]);
    $user = $read->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new InvalidArgumentException('That account no longer exists.');
    }
    $email = trim((string) $user['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('This account needs a valid email address before its password can be reset.');
    }

    $temporaryPassword = gjc_reset_student_password($db, $userId);
    $name = trim($user['first_name'] . ' ' . $user['last_name']);
    $isFaculty = (int) $user['roleID'] === GJC_FACULTY_ROLE_ID;
    $greeting = $name !== '' ? $name : ($isFaculty ? 'Faculty member' : 'Student');
    $idLabel = $isFaculty ? 'Employee ID' : 'student ID';
    $loginUrl = BASE_URL . '/login.php';
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $body = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px">'
        . '<h2>GenPay Password Reset</h2><p>Hello ' . $escape($greeting) . ',</p>'
        . '<p>The finance office has reset your GenPay password.</p>'
        . '<p><strong>Temporary password:</strong> <code>' . $escape($temporaryPassword) . '</code></p>'
        . '<p><a href="' . $escape($loginUrl) . '">Sign in to GenPay</a> using your ' . $idLabel . ' or registered email and this temporary password.</p>'
        . '<p>You must choose a new password when you sign in. Keep this password private.</p>'
        . '<p>If you did not request this reset, contact the finance office immediately.</p>'
        . '<p>GenPay Team</p></div>';
    $altBody = "Hello {$greeting},\n\n"
        . "The finance office has reset your GenPay password.\n"
        . "Temporary password: {$temporaryPassword}\n\n"
        . "Sign in at {$loginUrl} using your {$idLabel} or registered email and this temporary password.\n"
        . "You must choose a new password when you sign in. Keep this password private.\n\n"
        . "If you did not request this reset, contact the finance office immediately.\n\nGenPay Team";
    $sendEmail ??= 'gjc_send_now';
    if (!$sendEmail($email, $name, 'GenPay - ' . ($isFaculty ? 'Faculty' : 'Student') . ' Password Reset', $body, $altBody)) {
        throw new InvalidArgumentException('The reset email could not be sent. The password has not been changed. Please try again or check the email service.');
    }
    return $email;
}
