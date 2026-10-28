<?php
session_start();
require_once __DIR__ . '/connection/config.php';
require_once __DIR__ . '/connection/pdo.php';
require_once __DIR__ . '/connection/app.php';

// Must be logged in
$userId = gjc_user_id();
if (!$userId) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!empty($_SESSION['force_change'])) {
    header('Location: ' . BASE_URL . '/change_password.php');
    exit;
}

$subRole = gjc_sub_role(); // student | super_admin | merchant_admin | merchant_staff

// Flag so role-specific includes know they're inside the unified layout
define('UNIFIED_DASHBOARD', true);

switch ($subRole) {
    case 'super_admin':
    case 'finance_staff':
        // Finance role (roleID=3 or 4; 8 = limited finance staff)
        require_once ADMIN_PATH . '/dashboard.php';
        break;

    case 'merchant_admin':
        require_once MERCHANT_PATH . '/dashboard.php';
        break;

    case 'merchant_staff':
        // Staff land directly on POS — no full dashboard view
        require_once MERCHANT_PATH . '/dashboard.php';
        break;

    case 'parent':
        require_once PARENT_PATH . '/dashboard.php';
        break;

    case 'student':
    default:
        require_once STUDENT_PATH . '/dashboard.php';
        break;
}
