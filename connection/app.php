<?php

require_once __DIR__ . "/config.php";
// Pulled in unconditionally so logAudit() is available everywhere app.php is —
// which is every page. gjc_require_role() below records a signed-in user being
// turned away from another role's area, and without this that would only be
// logged on the handful of pages that happened to require audit_logger.php for
// their own reasons. The file defines functions and constants only; including
// it has no side effects and it requires nothing back, so there is no cycle.
require_once __DIR__ . "/audit_logger.php";

if (PHP_SAPI !== "cli" && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// GenCoin conversion rate: ₱10 = 1 GC (same rate the POS wallet-load flow uses).
define('GJC_PESOS_PER_GC', 10);

// Restricted-product strike policy DEFAULTS: warn the merchant at 3 blocked
// attempts; hitting 5 auto-suspends the whole stall (owner + staff logins,
// student purchases) for 3 days and restarts the count. Finance can lift a
// suspension early from admin/restricted_products.php.
//
// These are the fallbacks only — the live values are the 'violation_*' keys in
// app_settings, editable from admin/settings.php. Read them at runtime with
// gjc_setting_int(), not these constants.
define('GJC_VIOLATION_WARN_AT', 3);
define('GJC_VIOLATION_RISK_AT', 5);
define('GJC_VIOLATION_SUSPEND_DAYS', 3);

// Server-side key used to sign opaque "view record" tokens (see
// gjc_make_view_token()). This is not a user secret and nothing is stored per
// row — it only proves a token was minted by the app. Rotating it simply
// invalidates any outstanding view links.
if (!defined('GJC_VIEW_TOKEN_SECRET')) {
    define('GJC_VIEW_TOKEN_SECRET', gjc_local_config('view_token_secret', 'GJC_VIEW_TOKEN_SECRET'));
}

// The helpers live in connection/app/, one file per area. Each file only
// defines functions and constants, so the order below doesn't matter.
require_once __DIR__ . "/app/format.php";
require_once __DIR__ . "/app/schema.php";
require_once __DIR__ . "/app/restrictions.php";
require_once __DIR__ . "/app/violations.php";
require_once __DIR__ . "/app/suspensions.php";
require_once __DIR__ . "/app/stall_applications.php";
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
