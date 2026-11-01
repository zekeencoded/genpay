<?php
$currentPage = $currentPage ?? '';
?>
<aside class="parent-sidebar" id="parentSidebar">
    <div class="parent-brand">
        <div class="parent-brand-logo">
            <img src="<?= ICONS_URL ?>/gp_logo.png" alt="GenPay Logo">
        </div>
        <div class="parent-brand-text">
            <h4>GenPay</h4>
            <span>Parent Portal</span>
        </div>
    </div>
    <nav class="parent-menu">
        <a href="<?= PARENT_URL ?>/dashboard.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="fa-solid fa-gauge-high parent-nav-icon"></i>
            <span class="parent-nav-text">Dashboard</span>
        </a>
        <a href="<?= PARENT_URL ?>/cart.php" class="<?= $currentPage === 'cart' ? 'active' : '' ?>">
            <i class="fa-solid fa-cart-shopping parent-nav-icon"></i>
            <span class="parent-nav-text">Shop Cart</span>
        </a>
        <a href="<?= PARENT_URL ?>/allowance.php" class="<?= $currentPage === 'allowance' ? 'active' : '' ?>">
            <i class="fa-solid fa-hand-holding-dollar parent-nav-icon"></i>
            <span class="parent-nav-text">Send Allowance</span>
        </a>
        <a href="<?= PARENT_URL ?>/activity.php" class="<?= $currentPage === 'activity' ? 'active' : '' ?>">
            <i class="fa-solid fa-list-check parent-nav-icon"></i>
            <span class="parent-nav-text">Activity Trail</span>
        </a>
        <a href="<?= PARENT_URL ?>/profile.php" class="<?= $currentPage === 'profile' ? 'active' : '' ?>">
            <i class="fa-solid fa-user parent-nav-icon"></i>
            <span class="parent-nav-text">Profile</span>
        </a>
    </nav>
    <a href="<?= BASE_URL ?>/logout.php" class="parent-logout"
       onclick="openLogoutModal(event);">
        <i class="fa-solid fa-arrow-right-from-bracket parent-logout-icon"></i>
        <span>Logout</span>
    </a>
</aside>
<script>
/* Persist the collapsed rail across pages (restored before first paint). */
(function () {
    var el = document.getElementById('parentSidebar');
    if (!el) return;
    var KEY = 'gjc.parentSidebarCollapsed';
    try {
        if (localStorage.getItem(KEY) === '1') el.classList.add('collapsed');
    } catch (e) {}
    new MutationObserver(function () {
        try {
            localStorage.setItem(KEY, el.classList.contains('collapsed') ? '1' : '0');
        } catch (e) {}
    }).observe(el, { attributes: true, attributeFilter: ['class'] });
})();
</script>
<?php require __DIR__ . '/logout_modal.php'; ?>
<?php require __DIR__ . '/back_to_dashboard.php'; ?>
