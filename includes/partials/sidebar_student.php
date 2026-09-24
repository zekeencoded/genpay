<?php
// Partial: student sidebar (forest-green dashboard shell). Including pages
// set $currentPage ('dashboard','cart','transfer','history',
// 'profile') and must link assets/css/student_dashboard.css,
// which carries the sd-* styles. Desktop only — hidden under 768px, where
// bottom_nav_student.php takes over.
$currentPage = $currentPage ?? '';
?>
<aside class="sd-sidebar" id="sdSidebar">
    <div class="sd-brand">
        <div class="sd-brand-logo"><img src="<?= ICONS_URL ?>/gp_logo.png" alt="GenPay"></div>
        <div class="sd-brand-text">
            <h4>GenPay</h4>
            <span>Student Portal</span>
        </div>
    </div>

    <nav class="sd-menu">
        <a href="<?= DASHBOARD_URL ?>" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="fa-solid fa-gauge-high"></i>
            <span>Dashboard</span>
        </a>
        <a href="<?= STUDENT_URL ?>/cart.php" class="<?= $currentPage === 'cart' ? 'active' : '' ?>">
            <i class="fa-solid fa-cart-shopping"></i>
            <span>Shop Cart</span>
        </a>
        <a href="<?= STUDENT_URL ?>/transfer.php" class="<?= $currentPage === 'transfer' ? 'active' : '' ?>">
            <i class="fa-solid fa-paper-plane"></i>
            <span>Send GenCoin</span>
        </a>
        <a href="<?= STUDENT_URL ?>/history.php" class="<?= $currentPage === 'history' ? 'active' : '' ?>">
            <i class="fa-solid fa-receipt"></i>
            <span>History</span>
        </a>
        <a href="<?= STUDENT_URL ?>/profile.php" class="<?= $currentPage === 'profile' ? 'active' : '' ?>">
            <i class="fa-solid fa-user"></i>
            <span>Profile</span>
        </a>
    </nav>

    <a href="<?= BASE_URL ?>/logout.php" class="sd-logout" onclick="openLogoutModal(event);">
        <i class="fa-solid fa-arrow-right-from-bracket"></i>
        <span>Logout</span>
    </a>
</aside>
<script>
/* Rail collapse. The state has to survive navigation — every item in the rail
   is a link, so without persisting it the sidebar sprang back open on the next
   page load. The class is restored inline right after the <aside> (before
   first paint, so there is no flash of the wide rail and no width transition),
   and re-saved by watching the element itself. One key across the student,
   merchant-admin and merchant-staff shells: it is the same component. */
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
// Instant gold highlight on click (the logout_modal.php handler only covers
// the legacy .student-menu sidebars).
document.addEventListener('click', function (e) {
    var link = e.target.closest('.sd-menu > a');
    if (!link || link.classList.contains('active')) return;
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var current = link.parentElement.querySelector(':scope > a.active');
    if (current) current.classList.remove('active');
    link.classList.add('active');
});
</script>
