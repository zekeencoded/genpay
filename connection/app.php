<?php

require_once __DIR__ . "/config.php";
// Loaded everywhere so logAudit() is always available.
require_once __DIR__ . "/audit_logger.php";

if (PHP_SAPI !== "cli" && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// GenCoin conversion rate: ₱10 = 1 GC (same rate the POS wallet-load flow uses).
define('GJC_PESOS_PER_GC', 10);

// Restricted-product strike defaults: warn at 3, suspend the stall for 3 days at 5.
// Live values are the 'violation_*' app settings; read them with gjc_setting_int().
define('GJC_VIOLATION_WARN_AT', 3);
define('GJC_VIOLATION_RISK_AT', 5);
define('GJC_VIOLATION_SUSPEND_DAYS', 3);

// Signs view-record tokens. Rotating it only invalidates outstanding links.
if (!defined('GJC_VIEW_TOKEN_SECRET')) {
    define('GJC_VIEW_TOKEN_SECRET', gjc_local_config('view_token_secret', 'GJC_VIEW_TOKEN_SECRET'));
}

// Helpers in connection/app/, one file per area (definitions only, any order).
require_once __DIR__ . "/app/format.php";
require_once __DIR__ . "/app/schema.php";
require_once __DIR__ . "/app/restrictions.php";
require_once __DIR__ . "/app/violations.php";
require_once __DIR__ . "/app/suspensions.php";
require_once __DIR__ . "/app/stall_applications.php";
require_once __DIR__ . "/app/recaptcha.php";
require_once __DIR__ . "/app/stalls.php";
require_once __DIR__ . "/app/users.php";
require_once __DIR__ . "/app/settings.php";
require_once __DIR__ . "/app/contracts.php";
require_once __DIR__ . "/app/orders.php";
require_once __DIR__ . "/app/wallets.php";
require_once __DIR__ . "/app/merchant.php";
require_once __DIR__ . "/app/transactions.php";
require_once __DIR__ . "/app/admin_dashboard.php";
require_once __DIR__ . "/app/parent.php";
require_once __DIR__ . "/app/fees.php";
require_once __DIR__ . "/app/notifications.php";
require_once __DIR__ . "/app/school_year.php";
require_once __DIR__ . "/app/backup.php";

gjc_backup_block_during_restore();
