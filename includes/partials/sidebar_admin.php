<?php
// Partial: Admin sidebar — included by dashboard.php
// Requires: $currentPage string (e.g. 'dashboard', 'users', 'leases', etc.)
$currentPage = $currentPage ?? "";

// Pending work, so the queues that need clearing are visible from anywhere
// rather than only after opening each page. Same source as the dashboard's
// "Needs your attention" strip, so a badge and a tile can never disagree.
$__queues = function_exists('gjc_finance_queue_counts') && isset($db)
    ? gjc_finance_queue_counts($db)
    : ['encashments' => 0, 'stall_applications' => 0];

/** The badge that was already on Stall Applications, now available to any item. */
$__badge = static function (int $count, string $noun): string {
    if ($count < 1) {
        return '';
    }
    $label = $count . ' ' . $noun . ($count === 1 ? '' : 's') . ' waiting';
    return '<span class="nav-badge" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
        . ($count > 99 ? '99+' : $count) . '</span>';
};
?>
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
        <a href="<?= ADMIN_URL ?>/cash_management.php" class="<?= $currentPage === 'cash' ? 'active' : '' ?>">
            <i class="fa-solid fa-money-bill-transfer nav-icon"></i>
            <span class="nav-text">Cash Management</span>
            <?= $__badge($__queues['encashments'], 'payout') ?>
        </a>
        <a href="<?= ADMIN_URL ?>/transactions.php" class="<?= $currentPage === 'transactions' ? 'active' : '' ?>">
            <i class="fa-solid fa-receipt nav-icon"></i>
            <span class="nav-text">Transactions</span>
        </a>
        <a href="<?= ADMIN_URL ?>/economy.php" class="<?= $currentPage === 'economy' ? 'active' : '' ?>">
            <i class="fa-solid fa-coins nav-icon"></i>
            <span class="nav-text">Economy</span>
        </a>
        <a href="<?= ADMIN_URL ?>/wallet.php" class="<?= $currentPage === 'wallet' ? 'active' : '' ?>">
            <i class="fa-solid fa-wallet nav-icon"></i>
            <span class="nav-text">Admin Wallet</span>
        </a>
        <a href="<?= ADMIN_URL ?>/stall_applications.php" class="<?= $currentPage === 'stall_applications' ? 'active' : '' ?>">
            <i class="fa-solid fa-store nav-icon"></i>
            <span class="nav-text">Stall Applications</span>
            <?= $__badge($__queues['stall_applications'], 'application') ?>
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
