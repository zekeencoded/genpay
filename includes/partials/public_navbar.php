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
    <button class="nav-toggle" type="button" aria-controls="public-nav-menu" aria-expanded="false" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
    <div class="nav-actions" id="public-nav-menu">
        <a href="<?= BASE_URL ?>/stalls" class="btn btn-primary-custom btn-outline-custom"<?= $publicNavCurrent === 'stalls' ? ' aria-current="page"' : '' ?>>View Stalls</a>
        <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary-custom btn-login">Login<span class="d-none d-sm-inline"> to Portal</span></a>
    </div>
</nav>
<script>
    // Mobile hamburger: shows/hides the link menu under the navbar.
    (function () {
        var toggle = document.querySelector('.nav-toggle');
        var menu = document.getElementById('public-nav-menu');
        function setOpen(open) {
            menu.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        toggle.addEventListener('click', function () {
            setOpen(!menu.classList.contains('is-open'));
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setOpen(false);
        });
    })();
</script>
