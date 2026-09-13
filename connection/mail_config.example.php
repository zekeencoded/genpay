<?php

/**
 * Gmail SMTP credentials for connection/mailer.php.
 *
 * Copy this file to `connection/mail_config.php` and fill in both values.
 * The real file is gitignored — it holds a live app password.
 *
 * `pass` is a Google App Password, not the account's normal password. Create
 * one at https://myaccount.google.com/apppasswords (the account needs 2-Step
 * Verification on). Emails are sent from `user`.
 *
 * The MAIL_USER / MAIL_PASS environment variables override anything set here,
 * for deployments that would rather keep secrets out of the filesystem.
 */

return [
    'user' => 'your-account@gmail.com',
    'pass' => 'REPLACE_WITH_THE_APP_PASSWORD',
];
