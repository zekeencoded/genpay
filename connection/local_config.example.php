<?php

/**
 * Machine-local secrets (DB login, view token key). Copy to connection/local_config.php (gitignored).
 * GJC_DB_HOST/PORT/NAME/USER/PASS and GJC_VIEW_TOKEN_SECRET env vars override this file.
 */

return [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => 'ewallet',
    'db_user' => 'root',
    'db_pass' => 'REPLACE_WITH_THE_DATABASE_PASSWORD',

    'view_token_secret' => 'REPLACE_WITH_A_LONG_RANDOM_STRING',

    // reCAPTCHA v2 secret key for the stall application form (the site key is in connection/app/recaptcha.php).
    'recaptcha_secret_key' => 'REPLACE_WITH_THE_RECAPTCHA_V2_SECRET_KEY',
];
