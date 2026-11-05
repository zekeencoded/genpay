<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);

$parentUserId = gjc_user_id();
$currentUser  = gjc_current_user($db);

$pStmt = $db->prepare("SELECT id, low_balance_threshold FROM parents WHERE user_id = ?");
$pStmt->execute([$parentUserId]);
$parentRow = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$parentRow) {
    header('Location: ' . PARENT_URL . '/dashboard.php');
    exit;
}
$alertThreshold = (float) $parentRow['low_balance_threshold'];

// Alerts load client-side via parent/api/alerts.php, same as the dashboard.
$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Low Balance Alerts | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=27">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_dashboard.css?v=22">
</head>
<body class="gp-theme">
<div class="parent-layout">

    <?php require __DIR__ . '/../includes/partials/sidebar_parent.php'; ?>

    <main class="parent-main">

        <?php
        $topbarTitle = 'Low Balance Alerts';
        $topbarSubtitle = "We tell you when a linked student's balance drops below your threshold.";
        $topbarSubtitleMobile = true;
        require __DIR__ . '/../includes/partials/topbar_parent.php';
        ?>

        <div class="parent-content parent-content--wide">

            <a href="<?= PARENT_URL ?>/dashboard.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
            </a>

            <div class="parent-card">
                <div class="parent-card-head">
                    <h5>
                        <i class="fa-solid fa-bell me-2" style="color:var(--gp-warning)"></i>Low Balance Alerts
                        <span class="alert-badge" id="alertBadge" style="display:none;"></span>
                    </h5>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <button id="markReadBtn" onclick="markAlertsRead()" style="display:none;background:none;border:none;font-size:12px;color:var(--gp-green-700);font-weight:600;cursor:pointer;padding:0;">Mark all read</button>
                        <button onclick="fetchAlerts()" title="Refresh alerts" style="background:none;border:none;color:var(--gp-muted);cursor:pointer;padding:2px 4px;font-size:13px;line-height:1;" id="refreshBtn">
                            <i class="fa-solid fa-rotate-right" id="refreshIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="alert-settings-box">
                    <p>Alert Settings</p>
                    <form class="threshold-form" id="thresholdForm">
                        <div>
                            <label>Alert me when balance drops below (₱)</label>
                            <input type="number" id="thresholdInput" min="0" step="1" value="<?= number_format($alertThreshold, 2, '.', '') ?>" placeholder="50.00">
                        </div>
                        <button type="submit" class="btn-save">Save</button>
                        <span id="thresholdMsg" style="font-size:12px;color:var(--gp-success);align-self:center;"></span>
                    </form>
                </div>

                <div id="alertsContainer">
                    <div style="text-align:center;padding:20px 0;">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:22px;color:var(--gp-line);"></i>
                        <p style="margin-top:8px;color:var(--gp-muted);font-size:13px;">Loading alerts...</p>
                    </div>
                </div>
                <p id="alertsTimestamp" style="font-size:11px;color:var(--gp-muted);text-align:right;margin:8px 0 0;"></p>
            </div>

        </div>

    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
<script>
const ALERTS_API   = '<?= PARENT_URL ?>/api/alerts.php';
const CONTROLS_API = '<?= PARENT_URL ?>/api/controls.php';
const LINK_API     = '<?= PARENT_URL ?>/api/link.php';
</script>
<script src="<?= JS_URL ?>/parent_dashboard.js?v=1"></script>

<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
