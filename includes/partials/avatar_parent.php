<?php
// Always resolve the avatar from the signed-in parent, not page-specific data.
$__parentPhoto = (string) ($currentUser['raw']['profile_img'] ?? '');
$__parentPhotoUrl = $__parentPhoto !== '' ? BASE_URL . '/' . ltrim($__parentPhoto, '/') : '';
$__parentInitial = strtoupper(substr((string) ($currentUser['name'] ?? ''), 0, 1));
$__parentAvatarE = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<a href="<?= $__parentAvatarE(PARENT_URL . '/profile.php') ?>" class="parent-avatar" id="topbarAvatar" aria-label="View my profile" style="text-decoration:none;overflow:hidden;<?= isset($parentAvatarStyle) ? $__parentAvatarE($parentAvatarStyle) : '' ?>">
    <?php if ($__parentPhotoUrl !== ''): ?>
        <img id="topbarAvatarImg" src="<?= $__parentAvatarE($__parentPhotoUrl) ?>" alt=""
             style="width:100%;height:100%;object-fit:cover;display:block;">
    <?php else: ?>
        <span id="topbarAvatarInitial"><?= $__parentAvatarE($__parentInitial) ?></span>
    <?php endif; ?>
</a>
