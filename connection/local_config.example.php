<?php

/**
 * Machine-local secrets: the application database login and the key that signs
 * "view record" tokens.
 *
 * Copy this file to `connection/local_config.php` and fill it in. The real file
 * is gitignored — it holds a live database password. Nothing here has a
 * built-in default on purpose: a default would have to be committed, and a
 * committed credential is a leaked credential.
 *
 * The GJC_DB_HOST / GJC_DB_PORT / GJC_DB_NAME / GJC_DB_USER / GJC_DB_PASS and
 * GJC_VIEW_TOKEN_SECRET environment variables override anything set here, for
 * deployments that would rather keep secrets out of the filesystem.
 *
 * Rotating `view_token_secret` only invalidates outstanding view links and
 * downloaded CSV templates; nothing is stored against it.
 */

return [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => 'ewallet',
    'db_user' => 'root',
    'db_pass' => 'REPLACE_WITH_THE_DATABASE_PASSWORD',

    'view_token_secret' => 'REPLACE_WITH_A_LONG_RANDOM_STRING',
];
