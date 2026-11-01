<?php
// Partial: make the browser Back button return to the dashboard (emitted once per page).
// Requires DASHBOARD_URL.
if (defined('GJC_BACK_GUARD_EMITTED')) {
    return;
}
define('GJC_BACK_GUARD_EMITTED', true);
?>
<script>
(function () {
    "use strict";

    var DASHBOARD_URL = <?= json_encode(DASHBOARD_URL, JSON_UNESCAPED_SLASHES) ?>;

    // Normalise a URL's path so "/genpay/dashboard" and
    // "/genpay/dashboard.php" compare equal (.htaccess strips the .php).
    function normPath(url) {
        try {
            var p = new URL(url, window.location.href).pathname;
            return p.replace(/\.php$/i, "").replace(/\/+$/, "");
        } catch (e) {
            return url;
        }
    }

    var onDashboard = normPath(window.location.href) === normPath(DASHBOARD_URL);

    // Seed a history entry so the first Back press fires popstate on this
    // page instead of unwinding out of the app.
    history.pushState(null, document.title, window.location.href);

    window.addEventListener("popstate", function () {
        if (onDashboard) {
            // Already home: re-seed so Back can't reach the pre-auth/login page.
            history.pushState(null, document.title, window.location.href);
        } else {
            // replace() (not assign) so a subsequent Back doesn't bounce
            // between the page and the dashboard.
            window.location.replace(DASHBOARD_URL);
        }
    });
})();
</script>
