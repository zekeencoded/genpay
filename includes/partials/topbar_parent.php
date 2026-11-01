<?php
// Partial: parent topbar.
// Required: $currentUser, $topbarTitle, $topbarSubtitle (HTML).
// Optional: $topbarSubtitleMobile (show the subtitle on phones; hidden by default).
$topbarSubtitleMobile = $topbarSubtitleMobile ?? false;
$__topbar_e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<?php require __DIR__ . '/csrf_bootstrap.php'; ?>
<!-- Title and subtitle are direct children so the grid can move the subtitle on phones. -->
<header class="parent-topbar<?= $topbarSubtitleMobile ? ' is-subtitle-mobile' : '' ?>">
    <button class="parent-menu-btn" aria-label="Toggle navigation" onclick="toggleParentSidebar()">
        <i class="fa-solid fa-bars"></i>
    </button>
    <h1><?= $topbarTitle ?></h1>
    <p><?= $topbarSubtitle ?></p>
    <div class="parent-user">
        <span class="parent-user-name"><?= $__topbar_e($currentUser['name']) ?></span>
        <?php
        // Same bell as the student topbar; low-balance alerts land here too.
        $notifApiUrl = PARENT_URL . '/api/notifications.php';
        require __DIR__ . '/notif_bell.php';
        ?>
        <?php require __DIR__ . '/avatar_parent.php'; ?>
    </div>
</header>
