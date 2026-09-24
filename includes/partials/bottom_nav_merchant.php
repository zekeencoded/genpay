<?php
// Partial: fixed mobile bottom nav for merchant pages (visible under 768px).
// Same $currentPage contract as sidebar_merchant_admin.php /
// sidebar_merchant_staff.php; needs assets/css/student_dashboard.css for the
// sd-bottomnav/sd-scan-fab styles (same partial student pages use). Place
// just before </body>. Curated per role, same idea as bottom_nav_student.php:
// a short list of the most-used links plus one raised FAB for the primary
// action, rather than every sidebar link crammed into a scroll strip.
$currentPage = $currentPage ?? '';
$__bnavIsStaff = function_exists('gjc_is_merchant_staff') && gjc_is_merchant_staff();
?>
<nav class="sd-bottomnav">
    <a href="<?= DASHBOARD_URL ?>" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
    </a>
    <?php if ($__bnavIsStaff): ?>
    <a href="<?= MERCHANT_URL ?>/pos.php" class="<?= $currentPage === 'pos' ? 'active' : '' ?>">
        <i class="fa-solid fa-cash-register"></i>
        <span>POS</span>
    </a>
    <a href="<?= MERCHANT_URL ?>/inventory.php" class="<?= $currentPage === 'inventory' ? 'active' : '' ?>">
        <i class="fa-solid fa-boxes-stacked"></i>
        <span>Inventory</span>
    </a>
    <a href="<?= MERCHANT_URL ?>/profile.php" class="<?= $currentPage === 'profile' ? 'active' : '' ?>">
        <i class="fa-solid fa-user"></i>
        <span>Profile</span>
    </a>
    <?php else: ?>
    <a href="<?= MERCHANT_URL ?>/inventory.php" class="<?= $currentPage === 'inventory' ? 'active' : '' ?>">
        <i class="fa-solid fa-boxes-stacked"></i>
        <span>Inventory</span>
    </a>
    <a href="<?= MERCHANT_URL ?>/pos.php" class="sd-scan-fab" aria-label="POS Terminal">
        <i class="fa-solid fa-cash-register"></i>
    </a>
    <a href="<?= MERCHANT_URL ?>/history.php" class="<?= $currentPage === 'history' ? 'active' : '' ?>">
        <i class="fa-solid fa-receipt"></i>
        <span>History</span>
    </a>
    <a href="<?= MERCHANT_URL ?>/settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">
        <i class="fa-solid fa-gear"></i>
        <span>Settings</span>
    </a>
    <?php endif; ?>
</nav>
<?php require __DIR__ . '/back_to_dashboard.php'; ?>
