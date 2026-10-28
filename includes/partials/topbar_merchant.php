<?php
// Partial: merchant topbar (shared header for all merchant pages).
// Exact copy of includes/partials/topbar_student.php — same markup, classes,
// ids, and JS shape. Only the merchant-specific bits differ:
//   - $currentUser['name'] stands in for student's $studentName (every
//     merchant page already sets $currentUser = gjc_current_user($db)).
//   - NOTIF_API points at MERCHANT_URL instead of STUDENT_URL.
//   - The ICONS map is keyed to the notification types merchants actually
//     receive (sale/topup/encashment/compliance/rent) instead of student's
//     (welcome/transfer_in/transfer_out/withdraw/payment/fee_waiver).
// Including pages must set, before requiring this file:
//   $currentUser    (array)  - ['name'] used for the greeting/avatar initial
//   $topbarTitle    (string) - final <h1> HTML (pre-escaped by the caller)
//   $topbarSubtitle (string) - final <p> HTML (pre-escaped by the caller)
// Optional:
//   $topbarShowBell        (bool)   - show the notification bell (defaults to true — every merchant page)
//   $topbarAvatarPhotoUrl  (string) - profile photo URL; falls back to the initial avatar
//   $topbarSubtitleMobile  (bool)   - keep the subtitle on a phone. It is hidden
//       there by default: it restates what the title and the page itself
//       already say, and it cost a line above the fold. Set it only where the
//       subtitle is the sole on-screen home of something.
$topbarShowBell = $topbarShowBell ?? true;
$topbarSubtitleMobile = $topbarSubtitleMobile ?? false;
$topbarAvatarPhotoUrl = $topbarAvatarPhotoUrl ?? (
    !empty($currentUser['raw']['profile_img'])
        ? BASE_URL . '/' . ltrim((string) $currentUser['raw']['profile_img'], '/')
        : ''
);
$topbarAvatarInitial = strtoupper(substr((string) ($currentUser['name'] ?? ''), 0, 1));
$__topbar_e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$__topbar_csrf = function_exists('gjc_csrf_token') ? gjc_csrf_token() : '';
$__topbar_schoolYear = (isset($db) && $db instanceof PDO && function_exists('gjc_active_school_year_name'))
    ? gjc_active_school_year_name($db)
    : null;
?>
<header class="sd-topbar<?= $topbarSubtitleMobile ? ' is-subtitle-mobile' : '' ?>">
    <!-- Collapses the desktop rail (toggleSdSidebar() lives in the sidebar
         partial, next to the element it owns). Hidden under 768px, where the
         rail is display:none and the bottom nav takes over. -->
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
        <?php if ($topbarShowBell): ?>
        <div class="sd-notif" id="sdNotif">
            <button type="button" class="sd-bell" id="sdNotifBtn" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
                <i class="fa-regular fa-bell"></i>
                <span class="sd-notif-badge" id="sdNotifBadge" style="display:none">0</span>
            </button>
            <div class="sd-notif-panel" id="sdNotifPanel">
                <div class="sd-notif-head">
                    <span>Notifications</span>
                    <button type="button" id="sdNotifMarkAll">Mark all read</button>
                </div>
                <div class="sd-notif-list" id="sdNotifList">
                    <div class="sd-notif-empty">Loading&hellip;</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <a href="<?= $__topbar_e(MERCHANT_URL . '/profile.php') ?>" class="sd-avatar" id="topbarAvatar" aria-label="View my profile" style="text-decoration:none;<?= $topbarAvatarPhotoUrl ? 'overflow:hidden;' : '' ?>">
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
<?php if ($topbarShowBell): ?>
<script>
(function () {
    const NOTIF_API = <?= json_encode(rtrim((defined('MERCHANT_URL') ? MERCHANT_URL : ''), '/') . '/api/notifications.php') ?>;
    const CSRF = <?= json_encode($__topbar_csrf) ?>;
    const ICONS = {
        sale: 'fa-cart-shopping', topup: 'fa-circle-plus', encashment: 'fa-money-bill-wave',
        compliance: 'fa-triangle-exclamation', rent: 'fa-receipt', general: 'fa-bell',
    };

    const btn   = document.getElementById('sdNotifBtn');
    const panel = document.getElementById('sdNotifPanel');
    const list  = document.getElementById('sdNotifList');
    const badge = document.getElementById('sdNotifBadge');
    const markAllBtn = document.getElementById('sdNotifMarkAll');
    let open = false;
    let loaded = false;

    function positionPanel() {
        if (window.innerWidth <= 640) {
            const r = btn.getBoundingClientRect();
            panel.style.position = 'fixed';
            panel.style.top = (r.bottom + 10) + 'px';
            panel.style.left = '12px';
            panel.style.right = '12px';
            panel.style.width = 'auto';
            panel.style.maxWidth = 'none';
            list.style.maxHeight = Math.max(200, window.innerHeight - r.bottom - 90) + 'px';
        } else {
            panel.style.position = '';
            panel.style.top = '';
            panel.style.left = '';
            panel.style.right = '';
            panel.style.width = '';
            panel.style.maxWidth = '';
            list.style.maxHeight = '';
        }
    }

    function timeAgo(iso) {
        const d = new Date(iso.replace(' ', 'T'));
        const secs = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
        if (secs < 60) return 'Just now';
        if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
        if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
        if (secs < 604800) return Math.floor(secs / 86400) + 'd ago';
        return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
    }

    function setBadge(count) {
        if (count > 0) {
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function render(items) {
        if (!items.length) {
            list.innerHTML = '<div class="sd-notif-empty">You&rsquo;re all caught up.</div>';
            return;
        }
        list.innerHTML = items.map(function (n) {
            const icon = ICONS[n.type] || ICONS.general;
            return '<button type="button" class="sd-notif-item' + (n.is_read ? '' : ' is-unread') + '" data-id="' + esc(n.id) + '" data-link="' + esc(n.link || '') + '">'
                + '<span class="sd-notif-item-icon"><i class="fa-solid ' + icon + '"></i></span>'
                + '<span class="sd-notif-item-body">'
                +   '<strong>' + esc(n.title) + '</strong>'
                +   '<span>' + esc(n.message) + '</span>'
                +   '<em>' + timeAgo(n.created_at) + '</em>'
                + '</span>'
                + '</button>';
        }).join('');
    }

    async function fetchList() {
        try {
            const f = new FormData();
            f.append('action', 'list');
            const d = await (await fetch(NOTIF_API, { method: 'POST', body: f, credentials: 'same-origin' })).json();
            if (d.success) {
                setBadge(d.unread_count);
                render(d.notifications);
            }
        } catch (e) {
            list.innerHTML = '<div class="sd-notif-empty">Couldn&rsquo;t load notifications.</div>';
        }
    }

    async function refreshCount() {
        try {
            const f = new FormData();
            f.append('action', 'unread_count');
            const d = await (await fetch(NOTIF_API, { method: 'POST', body: f, credentials: 'same-origin' })).json();
            if (d.success) setBadge(d.unread_count);
        } catch (e) { /* silent — badge just stays as-is */ }
    }

    async function markRead(id) {
        const f = new FormData();
        f.append('action', 'mark_read');
        f.append('csrf_token', CSRF);
        if (id) f.append('id', id);
        try {
            const d = await (await fetch(NOTIF_API, { method: 'POST', body: f, credentials: 'same-origin' })).json();
            if (d.success) setBadge(d.unread_count);
        } catch (e) { /* non-fatal */ }
    }

    function closePanel() {
        open = false;
        panel.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        open = !open;
        if (open) positionPanel();
        panel.classList.toggle('is-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && !loaded) { loaded = true; fetchList(); }
        else if (open) { fetchList(); }
    });

    window.addEventListener('resize', function () { if (open) positionPanel(); });

    document.addEventListener('click', function (e) {
        if (open && !panel.contains(e.target) && !btn.contains(e.target)) closePanel();
    });

    // The panel is fixed-positioned (on mobile) from where the bell was at
    // open-time — the topbar isn't sticky, so scrolling the page leaves it
    // visually stranded instead of tracking the bell. Close on page scroll
    // instead of trying to re-track it; scrolling *inside* the notification
    // list itself (its own overflow-y:auto) must not trigger this.
    window.addEventListener('scroll', function (e) {
        if (open && e.target !== list && !list.contains(e.target)) closePanel();
    }, true);

    list.addEventListener('click', function (e) {
        const item = e.target.closest('.sd-notif-item');
        if (!item) return;
        const id = item.getAttribute('data-id');
        const link = item.getAttribute('data-link');
        if (item.classList.contains('is-unread')) {
            item.classList.remove('is-unread');
            markRead(id);
        }
        if (link) window.location.href = link;
    });

    markAllBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        list.querySelectorAll('.sd-notif-item.is-unread').forEach(function (el) { el.classList.remove('is-unread'); });
        markRead(null);
    });

    // While the dropdown is open, keep its contents live too, not just the badge.
    function poll() { open ? fetchList() : refreshCount(); }

    refreshCount();
    setInterval(poll, 10000);

    // Catch up immediately when the tab regains focus (e.g. after switching
    // back from another tab where a sale/top-up just landed).
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') poll();
    });

    // Lets other pages trigger an instant refresh right after their own
    // action, instead of waiting for the poll.
    window.gjcRefreshNotifications = poll;
})();
</script>
<?php endif; ?>
