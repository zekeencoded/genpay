<?php
// Partial: topbar notification bell + dropdown (styles in notif_bell.css).
// Required: $notifApiUrl (an endpoint like student/api/notifications.php).
// Tapping an item opens the page it's about (the endpoint resolves `url`).
?>
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
<script>
(function () {
    const NOTIF_API = <?= json_encode($notifApiUrl) ?>;
    const CSRF = <?= json_encode(function_exists('gjc_csrf_token') ? gjc_csrf_token() : '') ?>;
    const ICONS = {
        welcome: 'fa-hand-sparkles', transfer_in: 'fa-arrow-down', transfer_out: 'fa-paper-plane',
        topup: 'fa-circle-plus', withdraw: 'fa-money-bill-wave', payment: 'fa-cart-shopping',
        fee_waiver: 'fa-hand-holding-dollar', low_balance: 'fa-triangle-exclamation', general: 'fa-bell',
    };

    const btn   = document.getElementById('sdNotifBtn');
    const panel = document.getElementById('sdNotifPanel');
    const list  = document.getElementById('sdNotifList');
    const badge = document.getElementById('sdNotifBadge');
    const markAllBtn = document.getElementById('sdNotifMarkAll');
    let open = false;
    let loaded = false;
    let items = {};

    // On narrow screens, center the panel in the viewport.
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

    function iconFor(n) {
        // Unmapped types fall back to the icon stored on the row.
        return ICONS[n.type] || (n.icon ? 'fa-' + n.icon : ICONS.general);
    }

    function render(rows) {
        items = {};
        rows.forEach(function (n) { items[n.id] = n; });
        if (!rows.length) {
            list.innerHTML = '<div class="sd-notif-empty">You&rsquo;re all caught up.</div>';
            return;
        }
        list.innerHTML = rows.map(function (n) {
            return '<button type="button" class="sd-notif-item' + (n.is_read ? '' : ' is-unread') + '" data-id="' + esc(n.id) + '">'
                + '<span class="sd-notif-item-icon"><i class="fa-solid ' + esc(iconFor(n)) + '"></i></span>'
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
            // keepalive: the tap may navigate away before this finishes.
            const d = await (await fetch(NOTIF_API, { method: 'POST', body: f, credentials: 'same-origin', keepalive: true })).json();
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

    // Close the panel on page scroll (not on scrolling inside the list).
    window.addEventListener('scroll', function (e) {
        if (open && e.target !== list && !list.contains(e.target)) closePanel();
    }, true);

    list.addEventListener('click', function (e) {
        const item = e.target.closest('.sd-notif-item');
        if (!item) return;
        const n = items[item.getAttribute('data-id')];
        if (!n) return;
        if (item.classList.contains('is-unread')) {
            item.classList.remove('is-unread');
            markRead(n.id);
        }
        if (n.url) window.location.href = n.url;
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
    // back from another tab where a transfer/payment just landed).
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') poll();
    });

    // Lets other pages (transfer.php, cart.php, ...) trigger an instant
    // refresh right after their own action, instead of waiting for the poll.
    window.gjcRefreshNotifications = poll;
})();
</script>
