<?php
// Partial: merchant staff sidebar (reduced links). Requires $currentPage.
$currentPage = $currentPage ?? '';
?>
<aside class="sd-sidebar" id="sdSidebar">
    <div class="sd-brand">
        <div class="sd-brand-logo"><img src="<?= ICONS_URL ?>/gp_logo.png" alt="GenPay"></div>
        <div class="sd-brand-text">
            <h4>GenPay</h4>
            <span>Cashier Staff</span>
        </div>
    </div>

    <nav class="sd-menu">
        <a href="<?= DASHBOARD_URL ?>" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="fa-solid fa-gauge-high"></i>
            <span>Dashboard</span>
        </a>
        <a href="<?= MERCHANT_URL ?>/pos.php" class="<?= $currentPage === 'pos' ? 'active' : '' ?>">
            <i class="fa-solid fa-cash-register"></i>
            <span>POS / Transactions</span>
        </a>
        <a href="<?= MERCHANT_URL ?>/inventory.php" class="<?= $currentPage === 'inventory' ? 'active' : '' ?>">
            <i class="fa-solid fa-boxes-stacked"></i>
            <span>Inventory Stock</span>
        </a>
        <a href="<?= MERCHANT_URL ?>/profile.php" class="<?= $currentPage === 'profile' ? 'active' : '' ?>">
            <i class="fa-solid fa-user"></i>
            <span>My Profile</span>
        </a>
    </nav>

    <a href="<?= BASE_URL ?>/logout.php" class="sd-logout" onclick="openLogoutModal(event);">
        <i class="fa-solid fa-arrow-right-from-bracket"></i>
        <span>Logout</span>
    </a>
</aside>
<script>
/* Rail collapse, persisted across pages (key shared by student and merchant shells). */
function toggleSdSidebar() {
    var el = document.getElementById('sdSidebar');
    if (el) el.classList.toggle('collapsed');
}
(function () {
    var el = document.getElementById('sdSidebar');
    if (!el) return;
    var KEY = 'gjc.sdSidebarCollapsed';
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
<script>
document.addEventListener('click', function (e) {
    var link = e.target.closest('.sd-menu > a');
    if (!link || link.classList.contains('active')) return;
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var current = link.parentElement.querySelector(':scope > a.active');
    if (current) current.classList.remove('active');
    link.classList.add('active');
});
</script>
