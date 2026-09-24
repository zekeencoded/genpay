<?php
// Partial: parent portal topbar (shared header for all parent pages).
// Including pages must set, before requiring this file:
//   $currentUser    (array) - from gjc_current_user($db); ['name'] is used
//   $topbarTitle    (string) - final <h1> HTML
//   $topbarSubtitle (string) - final <p> HTML
// Optional:
//   $profilePhotoUrl (string) - profile photo URL; falls back to the initial avatar
//   $topbarSubtitleMobile (bool) - keep the subtitle on a phone. It is hidden
//       there by default: on every page but one it restates what the title and
//       the page itself already say, and it cost two lines above the fold.
//       Set it only where the subtitle is the sole on-screen home of something
//       — controls.php names which student's wallet is being edited, and
//       nothing else on that page does.
$profilePhotoUrl = $profilePhotoUrl ?? '';
$topbarSubtitleMobile = $topbarSubtitleMobile ?? false;
$topbarInitial = strtoupper(substr((string) $currentUser['name'], 0, 1));
$__topbar_e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!-- The title and subtitle are DIRECT children of the header, not wrapped in
     a div: parent_shell.css lays the bar out as a named grid, and on a phone
     the subtitle drops onto its own full-width row under the title/avatar
     row. A wrapper would trap it in the title column. -->
<header class="parent-topbar<?= $topbarSubtitleMobile ? ' is-subtitle-mobile' : '' ?>">
    <button class="parent-menu-btn" aria-label="Toggle navigation" onclick="toggleParentSidebar()">
        <i class="fa-solid fa-bars"></i>
    </button>
    <h1><?= $topbarTitle ?></h1>
    <p><?= $topbarSubtitle ?></p>
    <div class="parent-user">
        <span class="parent-user-name"><?= $__topbar_e($currentUser['name']) ?></span>
        <div class="parent-avatar" id="topbarAvatar" style="<?= $profilePhotoUrl ? 'overflow:hidden;' : '' ?>">
            <?php if ($profilePhotoUrl): ?>
                <img id="topbarAvatarImg" src="<?= $__topbar_e($profilePhotoUrl) ?>" alt=""
                     style="width:100%;height:100%;object-fit:cover;display:block;">
            <?php else: ?>
                <span id="topbarAvatarInitial"><?= $__topbar_e($topbarInitial) ?></span>
            <?php endif; ?>
        </div>
        <?php /* Phone only (hidden by CSS elsewhere): the sidebar's Logout link
                 is display:none under 768px and the bottom nav has no free
                 slot. Same modal as the sidebar (logout_modal.php). */ ?>
        <a href="<?= BASE_URL ?>/logout.php" class="parent-logout-btn" aria-label="Log out"
           onclick="openLogoutModal(event);">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
    </div>
</header>
