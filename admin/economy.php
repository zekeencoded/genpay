<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

// Every other admin page gates here and this one did not, so the vault balance,
// every wallet-pool total, the minting report and the full wallet-user list were
// readable by anyone who knew the URL. The mint *form* was correctly hidden
// behind sub_role and admin/api/mint.php enforces it server-side, but the read
// surface was open. circulation_widget.php is included only by this page, so
// gating here covers it.
gjc_require_role(['finance']);

$currentUser = gjc_current_user($db);

// Circulation figures (cap/vault/distributed/drift) are computed once,
// inside includes/circulation_widget.php below - this page used to compute
// them a second time just to render a duplicate set of summary cards.
$currentPage = 'economy';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>System Economy | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/circulation_widget.css?v=8">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
</head>

<body class="gp-theme">

    <div class="admin-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

        <main class="admin-main">

            <header class="topbar">
                <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>

                <div>
                    <h1>System Economy</h1>
                    <p>Track the closed-loop GenPay money supply, vault reserve, wallet pools, and minting controls.</p>
                </div>

                <div class="admin-user">
                    <span><?= htmlspecialchars($currentUser['name'] ?? 'Finance', ENT_QUOTES, 'UTF-8') ?></span>
                    <?php require __DIR__ . '/../includes/partials/avatar_admin.php'; ?>
                </div>
            </header>

            <?php require INCLUDES_PATH . '/circulation_widget.php'; ?>

        </main>

    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>

    <script>
        function toggleSidebar() {
            document.getElementById("sidebar").classList.toggle("collapsed");
        }
    </script>

    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
    <?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
    <script>
        (function () {
            const modal = document.getElementById('walletUsersModal');
            if (!modal) return;
            const dt = () => (window.jQuery && jQuery.fn.dataTable.isDataTable('#walletUsersTable'))
                ? jQuery('#walletUsersTable').DataTable() : null;

            // Filter the table by the pool card that opened the modal:
            // "" = all, "Student", or "Merchant" (Type is column index 1).
            modal.addEventListener('show.bs.modal', function (ev) {
                const card = ev.relatedTarget;
                const filter = (card && card.dataset.walletFilter) || '';
                const title = (card && card.dataset.walletTitle) || 'All Wallet Users';
                const label = document.getElementById('walletUsersModalText');
                if (label) label.textContent = title;
                const t = dt();
                if (t) t.column(1).search(filter).draw();
            });

            // A DataTable initialised inside a hidden modal mis-measures column widths.
            modal.addEventListener('shown.bs.modal', function () {
                const t = dt();
                if (t) t.columns.adjust();
            });
        })();
    </script>

</body>

</html>

