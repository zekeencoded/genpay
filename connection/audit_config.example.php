<?php

/**
 * Credentials for the audit-trail database connection.
 *
 * Copy this file to `connection/audit_config.php` and fill in the password.
 * The real file is gitignored — it holds a live database password.
 *
 * `database/migrations/audit_hardening.php` writes this file for you the first
 * time it runs: it generates a strong password, creates the `gjc_audit_writer`
 * MySQL account with INSERT + SELECT on `systemic_audit_trail` and nothing else,
 * and drops the credentials here. You only need to edit it by hand when moving
 * the app to another host.
 *
 * There is deliberately no fallback to the application's own database user.
 * The whole point of a separate account is that a compromised app connection
 * cannot rewrite the audit log — falling back to `root` would hand that back.
 * If this file is missing, audit logging stops and says so in the PHP error log.
 *
 * The AUDIT_DB_HOST / AUDIT_DB_PORT / AUDIT_DB_NAME / AUDIT_DB_USER /
 * AUDIT_DB_PASS environment variables override anything set here, for
 * deployments that would rather keep secrets out of the filesystem.
 */

return [
    'host' => 'localhost',
    'port' => '3306',
    'name' => 'ewallet',
    'user' => 'gjc_audit_writer',
    'pass' => 'REPLACE_WITH_THE_AUDIT_WRITER_PASSWORD',
];
