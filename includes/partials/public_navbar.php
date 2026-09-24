<?php
// Shared top bar for the public pages (index.php, stalls.php). Styled by
// index.css/stalls.css + gjc-clear.css; keep both pages on this partial so
// the landing chrome can't drift again. Set $publicNavCurrent = 'stalls'
// before including to mark the current link for screen readers.
$publicNavCurrent = $publicNavCurrent ?? '';
?>
<nav class="navbar">
    <a class="navbar-brand" href="<?= BASE_URL ?>/">
        <img src="<?= ICONS_URL ?>/gp_logo.png" alt="GenPay Logo">
        GenPay
    </a>
    <div class="nav-actions">
        <a href="<?= BASE_URL ?>/stalls" class="btn btn-primary-custom btn-outline-custom"<?= $publicNavCurrent === 'stalls' ? ' aria-current="page"' : '' ?>>View Stalls</a>
        <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary-custom btn-login">Login<span class="d-none d-sm-inline"> to Portal</span></a>
    </div>
</nav>
