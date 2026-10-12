<?php
// Finance header avatar: use the signed-in user's photo, matching the portals.
$__adminProfileImg = (string) ($currentUser['raw']['profile_img'] ?? '');
$__adminPhotoUrl = $__adminProfileImg !== ''
    ? BASE_URL . '/' . ltrim($__adminProfileImg, '/')
    : '';
?>
<a href="<?= htmlspecialchars(ADMIN_URL . '/settings.php', ENT_QUOTES, 'UTF-8') ?>" class="avatar" id="topbarAvatar" aria-label="View account settings" style="text-decoration:none;">
    <?php if ($__adminPhotoUrl !== ''): ?>
        <img id="topbarAvatarImg" src="<?= htmlspecialchars($__adminPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""
             style="width:100%;height:100%;object-fit:cover;display:block;">
    <?php else: ?>
        <i class="fa-solid fa-user-tie"></i>
    <?php endif; ?>
</a>
