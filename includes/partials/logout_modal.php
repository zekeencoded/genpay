<?php
// Partial: logout confirmation modal. Needs BASE_URL.
?>
<div class="logout-modal-overlay" id="logoutModalOverlay">
    <div class="logout-modal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle" onclick="event.stopPropagation();">
        <div class="logout-modal-icon"><i class="fa-solid fa-arrow-right-from-bracket"></i></div>
        <h3 class="logout-modal-title" id="logoutModalTitle">Log out?</h3>
        <p class="logout-modal-sub">You'll need to sign in again to access your account.</p>
        <div class="logout-modal-actions">
            <button type="button" class="logout-modal-cancel" onclick="closeLogoutModal()">Cancel</button>
            <a href="<?= BASE_URL ?>/logout.php" class="logout-modal-confirm">Log out</a>
        </div>
    </div>
</div>
<script>
function openLogoutModal(e) {
    if (e) e.preventDefault();
    var overlay = document.getElementById('logoutModalOverlay');
    if (!overlay) return;
    overlay.classList.add('is-open');
    document.addEventListener('keydown', logoutModalEscHandler);
}
function closeLogoutModal() {
    var overlay = document.getElementById('logoutModalOverlay');
    if (!overlay) return;
    overlay.classList.remove('is-open');
    document.removeEventListener('keydown', logoutModalEscHandler);
}
function logoutModalEscHandler(e) {
    if (e.key === 'Escape') closeLogoutModal();
}
// Mobile back-forward cache restores the page as it was (modal open, session gone)
// despite no-store; reload so the server can redirect to login.
window.addEventListener('pageshow', function (e) {
    if (e.persisted) location.reload();
});
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('logoutModalOverlay');
    if (overlay) {
        overlay.addEventListener('click', function () { closeLogoutModal(); });
    }
});

// Highlight the clicked nav item immediately.
document.addEventListener('click', function (e) {
    var link = e.target.closest('.sidebar-menu > a, .merchant-menu > a, .student-menu > a, .parent-menu > a');
    if (!link || link.classList.contains('active')) return;
    // Leave new-tab / middle-click / modified clicks to the browser.
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var nav = link.parentElement;
    var current = nav.querySelector(':scope > a.active');
    if (current) current.classList.remove('active');
    link.classList.add('active');
});
</script>
