<?php

// All app timestamps use Philippine time.
date_default_timezone_set('Asia/Manila');

// Session cookie hardening. Most entry points call session_start() before this
// file, and ini settings can't change on an active session, so close and reopen it.
$gjcSessionWasActive = session_status() === PHP_SESSION_ACTIVE;
if ($gjcSessionWasActive) {
    session_write_close();
}
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
if ($gjcSessionWasActive) {
    session_start();
}
unset($gjcSessionWasActive);

// Errors go to the server log, not the page.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('BASE_PATH', dirname(__DIR__));
define('GJC_APP_VERSION', '1.0.0');

$projectFolder = basename(BASE_PATH);
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';


// Trust X-Forwarded-Proto only from a local proxy.
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ? 'https' : 'http';
}

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', $protocol . '://' . $host . '/' . $projectFolder);




define('ASSETS_URL',     BASE_URL . '/assets');
define('CSS_URL',        BASE_URL . '/assets/css');
define('JS_URL',         BASE_URL . '/assets/js');
define('IMAGES_URL',     BASE_URL . '/assets/images');
define('ICONS_URL',      BASE_URL . '/assets/icons');

define('ADMIN_URL',      BASE_URL . '/admin');
define('MERCHANT_URL',   BASE_URL . '/merchant');
define('STUDENT_URL',    BASE_URL . '/student');
define('PARENT_URL',     BASE_URL . '/parent');
define('INCLUDES_URL',   BASE_URL . '/includes');
define('CONNECTION_URL', BASE_URL . '/connection');




define('ASSETS_PATH',     BASE_PATH . '/assets');
define('CSS_PATH',        BASE_PATH . '/assets/css');
define('JS_PATH',         BASE_PATH . '/assets/js');
define('IMAGES_PATH',     BASE_PATH . '/assets/images');
define('ICONS_PATH',      BASE_PATH . '/assets/icons');

define('ADMIN_PATH',      BASE_PATH . '/admin');
define('MERCHANT_PATH',   BASE_PATH . '/merchant');
define('STUDENT_PATH',    BASE_PATH . '/student');
define('PARENT_PATH',     BASE_PATH . '/parent');
define('INCLUDES_PATH',   BASE_PATH . '/includes');
define('CONNECTION_PATH', BASE_PATH . '/connection');

define('DASHBOARD_URL',  BASE_URL . '/dashboard.php');
define('DASHBOARD_PATH', BASE_PATH . '/dashboard.php');

/** Local setting: env var first, then local_config.php. No default. */
function gjc_local_config(string $key, string $envName): string
{
    static $file = null;
    $env = getenv($envName);
    if ($env !== false && $env !== '') {
        return $env;
    }
    if ($file === null) {
        $path = __DIR__ . '/local_config.php';
        $file = is_file($path) ? (array) require $path : [];
    }
    if (!isset($file[$key]) || $file[$key] === '') {
        error_log("[config] '$key' is not set — copy connection/local_config.example.php to local_config.php");
        throw new RuntimeException('Server is not configured.');
    }
    return (string) $file[$key];
}
