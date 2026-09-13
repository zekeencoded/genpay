<?php
// Partial: Admin sidebar — included by dashboard.php
// Requires: $currentPage string (e.g. 'dashboard', 'users', 'leases', etc.)
$currentPage = $currentPage ?? ""; ?>
<aside class="admin-sidebar" id="sidebar">
    <div class="brand-box">
        <div class="brand-logo">
            <img src="<?= ICONS_URL ?>/gp_logo.png" alt="Logo">
        </div>
        <div class="brand-text">
            <h4>GenPay</h4>
            <span>Finance</span>
        </div>
    </div>
    <nav class="sidebar-menu">
        <a href="<?= DASHBOARD_URL ?>" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="fa-solid fa-gauge-high nav-icon"></i>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="<?= ADMIN_URL ?>/users.php" class="<?= $currentPage === 'users' ? 'active' : '' ?>">
            <i class="fa-solid fa-users nav-icon"></i>
            <span class="nav-text">Users</span>
        </a>
        <a href="<?= ADMIN_URL ?>/leases.php" class="<?= $currentPage === 'leases' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-signature nav-icon"></i>
            <span class="nav-text">Leases &amp; Rent</span>
        </a>
        <a href="<?= ADMIN_URL ?>/topups.php" class="<?= $currentPage === 'topups' ? 'active' : '' ?>">
            <i class="fa-solid fa-money-bill-transfer nav-icon"></i>
            <span class="nav-text">Top-ups</span>
        </a>
        <a href="<?= ADMIN_URL ?>/encashments.php" class="<?= $currentPage === 'encashments' ? 'active' : '' ?>">
            <i class="fa-solid fa-money-check-dollar nav-icon"></i>
            <span class="nav-text">Encashments</span>
        </a>
        <a href="<?= ADMIN_URL ?>/transactions.php" class="<?= $currentPage === 'transactions' ? 'active' : '' ?>">
            <i class="fa-solid fa-receipt nav-icon"></i>
            <span class="nav-text">Transactions</span>
        </a>
        <a href="<?= ADMIN_URL ?>/economy.php" class="<?= $currentPage === 'economy' ? 'active' : '' ?>">
            <i class="fa-solid fa-coins nav-icon"></i>
            <span class="nav-text">Economy</span>
        </a>
        <a href="<?= ADMIN_URL ?>/stall_applications.php" class="<?= $currentPage === 'stall_applications' ? 'active' : '' ?>">
            <i class="fa-solid fa-store nav-icon"></i>
            <span class="nav-text">Stall Applications</span>
            <?php
            try {
                $__pendingCount = $db->query("SELECT COUNT(*) FROM stall_applications WHERE status='pending_verification'")->fetchColumn();
                if ($__pendingCount > 0): ?>
                <span style="background:var(--gp-red);color:#fff;font-size:11px;font-weight:700;border-radius:50px;padding:1px 7px;margin-left:auto;"><?= (int)$__pendingCount ?></span>
            <?php endif;
            } catch (Throwable $__e) {}
            ?>
        </a>
        <a href="<?= ADMIN_URL ?>/school_years.php" class="<?= $currentPage === 'school_years' ? 'active' : '' ?>">
            <i class="fa-solid fa-graduation-cap nav-icon"></i>
            <span class="nav-text">School Years</span>
        </a>
        <?php if (function_exists('gjc_sub_role') && gjc_sub_role() === 'super_admin'): ?>
        <!-- Super-admin only, matching the gate on the page itself — otherwise
             every cashier sees a link that just bounces them. -->
        <a href="<?= ADMIN_URL ?>/audit_log.php" class="<?= $currentPage === 'audit_log' ? 'active' : '' ?>">
            <i class="fa-solid fa-clipboard-list nav-icon"></i>
            <span class="nav-text">Audit Log</span>
        </a>
        <?php endif; ?>
        <?php if (function_exists('gjc_current_role') && gjc_current_role() === 'finance'): ?>
        <a href="<?= ADMIN_URL ?>/maintenance.php" class="<?= $currentPage === 'maintenance' ? 'active' : '' ?>">
            <i class="fa-solid fa-screwdriver-wrench nav-icon"></i>
            <span class="nav-text">Maintenance</span>
        </a>
        <?php endif; ?>
        <a href="<?= ADMIN_URL ?>/settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">
            <i class="fa-solid fa-gear nav-icon"></i>
            <span class="nav-text">Settings</span>
        </a>
    </nav>
    <a href="<?= BASE_URL ?>/logout.php" class="logout-btn"
       onclick="openLogoutModal(event);">
        <i class="fa-solid fa-arrow-right-from-bracket logout-icon"></i>
        <span>Logout</span>
    </a>
</aside>
<script>
/* The collapsed rail has to survive navigation: every item in it is a link,
   so without this the sidebar sprang back open on the next page load. The
   class is restored inline right after the <aside> (before first paint, so
   there is no flash of the wide rail and no width transition), and re-saved
   by watching the element itself — that catches toggleSidebar() as well as the
   pages that flip the class straight from an onclick attribute. */
(function () {
    var el = document.getElementById('sidebar');
    if (!el) return;
    var KEY = 'gjc.adminSidebarCollapsed';
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
