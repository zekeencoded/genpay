<?php
// Shared top bar for index.php and stalls.php. Set $publicNavCurrent = 'stalls' to mark the link.
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
        <a href="<?= BASE_URL ?>/#faq" class="btn btn-primary-custom btn-outline-custom"><i class="fa-regular fa-circle-question" aria-hidden="true"></i>FAQ</a>
        <a href="<?= BASE_URL ?>/stalls" class="btn btn-primary-custom btn-outline-custom"<?= $publicNavCurrent === 'stalls' ? ' aria-current="page"' : '' ?>><i class="fa-solid fa-store" aria-hidden="true"></i>View Stalls</a>
        <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary-custom btn-login">Login<i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i></a>
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
        // Same-page links (FAQ) don't reload, so close the menu ourselves.
        menu.addEventListener('click', function (e) {
            if (e.target.closest('a')) setOpen(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setOpen(false);
        });
    })();
</script>
