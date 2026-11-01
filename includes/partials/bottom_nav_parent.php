<?php
// Partial: mobile bottom nav for parent pages (<768px), same as the student one.
// Needs $currentPage and parent_shell.css. Place just before </body>.
$currentPage = $currentPage ?? '';
?>
<nav class="parent-bottomnav">
    <a href="<?= PARENT_URL ?>/dashboard.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
    </a>
    <a href="<?= PARENT_URL ?>/allowance.php" class="<?= $currentPage === 'allowance' ? 'active' : '' ?>">
        <i class="fa-solid fa-paper-plane"></i>
        <span>Send</span>
    </a>
    <a href="<?= PARENT_URL ?>/cart.php" class="parent-cart-fab" aria-label="Shop Cart">
        <i class="fa-solid fa-qrcode"></i>
    </a>
    <a href="<?= PARENT_URL ?>/activity.php" class="<?= $currentPage === 'activity' ? 'active' : '' ?>">
        <i class="fa-solid fa-receipt"></i>
        <span>Activity</span>
    </a>
    <a href="<?= PARENT_URL ?>/profile.php" class="<?= $currentPage === 'profile' ? 'active' : '' ?>">
        <i class="fa-solid fa-user"></i>
        <span>Profile</span>
    </a>
</nav>
