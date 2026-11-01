<?php

/**
 * Audit DB credentials. Copy to connection/audit_config.php (gitignored);
 * audit_hardening.php writes it for you. No fallback to the app DB user.
 * AUDIT_DB_HOST/PORT/NAME/USER/PASS env vars override this file.
 */

return [
    'host' => 'localhost',
    'port' => '3306',
    'name' => 'ewallet',
    'user' => 'gjc_audit_writer',
    'pass' => 'REPLACE_WITH_THE_AUDIT_WRITER_PASSWORD',
];
