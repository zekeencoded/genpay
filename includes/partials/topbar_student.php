<?php
// Partial: student topbar.
// Required: $studentName, $topbarTitle, $topbarSubtitle (pre-escaped HTML).
// Optional: $topbarShowBell (default true), $topbarAvatarPhotoUrl,
//           $topbarSubtitleMobile (show the subtitle on phones; hidden by default).
$topbarShowBell = $topbarShowBell ?? true;
$topbarSubtitleMobile = $topbarSubtitleMobile ?? false;
$topbarAvatarPhotoUrl = $topbarAvatarPhotoUrl ?? (
    !empty($currentUser['raw']['profile_img'])
        ? BASE_URL . '/' . ltrim((string) $currentUser['raw']['profile_img'], '/')
        : ''
);
$topbarAvatarInitial = strtoupper(substr((string) $studentName, 0, 1));
$__topbar_e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$__topbar_schoolYear = (isset($db) && $db instanceof PDO && function_exists('gjc_active_school_year_name'))
    ? gjc_active_school_year_name($db)
    : null;
?>
<header class="sd-topbar<?= $topbarSubtitleMobile ? ' is-subtitle-mobile' : '' ?>">
    <!-- Desktop rail toggle (toggleSdSidebar() is in the sidebar partial). -->
    <button type="button" class="sd-menu-btn" aria-label="Toggle navigation" onclick="toggleSdSidebar()">
        <i class="fa-solid fa-bars"></i>
    </button>
    <div class="sd-topbar-greet">
        <h1><?= $topbarTitle ?>
            <?php if ($__topbar_schoolYear): ?>
            <span class="sd-sy-chip" title="Active school year"><?= $__topbar_e('SY ' . $__topbar_schoolYear) ?></span>
            <?php endif; ?>
        </h1>
        <p><?= $topbarSubtitle ?></p>
    </div>
    <div class="sd-topbar-tools">
        <?php if ($topbarShowBell) {
            $notifApiUrl = STUDENT_URL . '/api/notifications.php';
            require __DIR__ . '/notif_bell.php';
        } ?>
        <a href="<?= $__topbar_e(STUDENT_URL . '/profile.php') ?>" class="sd-avatar" id="topbarAvatar" aria-label="View my profile" style="text-decoration:none;<?= $topbarAvatarPhotoUrl ? 'overflow:hidden;' : '' ?>">
            <?php if ($topbarAvatarPhotoUrl): ?>
                <img id="topbarAvatarImg" src="<?= $__topbar_e($topbarAvatarPhotoUrl) ?>" alt=""
                     style="width:100%;height:100%;object-fit:cover;display:block;">
            <?php else: ?>
                <?= $__topbar_e($topbarAvatarInitial) ?>
            <?php endif; ?>
        </a>
    </div>
</header>
<?php require __DIR__ . '/csrf_bootstrap.php'; ?>
