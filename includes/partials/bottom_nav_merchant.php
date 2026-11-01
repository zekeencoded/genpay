<?php
// Partial: mobile bottom nav for merchant pages (<768px). Needs $currentPage and student_dashboard.css.
// Place just before </body>.
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
