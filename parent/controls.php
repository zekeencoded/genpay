<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);

$parentUserId    = gjc_user_id();
$currentUser     = gjc_current_user($db);
$profileImg      = (string) ($currentUser['raw']['profile_img'] ?? '');
$profilePhotoUrl = ($profileImg !== '') ? (BASE_URL . '/' . ltrim($profileImg, '/')) : '';

$pStmt = $db->prepare("SELECT id FROM parents WHERE user_id = ?");
$pStmt->execute([$parentUserId]);
$parentRow = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$parentRow) {
    header('Location: ' . PARENT_URL . '/dashboard.php');
    exit;
}
$parentId = (int) $parentRow['id'];

$targetUid = (int) ($_GET['uid'] ?? 0);
if (!$targetUid) {
    header('Location: ' . PARENT_URL . '/dashboard.php');
    exit;
}

$linkChk = $db->prepare("SELECT 1 FROM parent_student_links WHERE parent_id = ? AND student_user_id = ?");
$linkChk->execute([$parentId, $targetUid]);
if (!$linkChk->fetch()) {
    header('Location: ' . PARENT_URL . '/dashboard.php');
    exit;
}

$sStmt = $db->prepare(
    "SELECT u.first_name, u.last_name, si.studentID,
            sw.id AS wallet_id, sw.balance, sw.is_frozen, sw.daily_spend_limit
       FROM users u
       LEFT JOIN student_info si ON si.userID = u.userID
       LEFT JOIN student_wallets sw ON sw.user_id = u.userID
      WHERE u.userID = ?"
);
$sStmt->execute([$targetUid]);
$student = $sStmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    header('Location: ' . PARENT_URL . '/dashboard.php');
    exit;
}

$studentName = trim($student['first_name'] . ' ' . $student['last_name']);
$currentPage = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wallet Controls — <?= htmlspecialchars($studentName) ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=24">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_controls.css?v=7">
</head>
<body class="gp-theme">
<div class="parent-layout">

    <?php require __DIR__ . '/../includes/partials/sidebar_parent.php'; ?>

    <main class="parent-main">

        <?php
        $topbarTitle = 'Wallet Controls';
        $topbarSubtitle = "Spending controls for " . htmlspecialchars($studentName) . "'s wallet.";
        // Kept on phones: this is the only place the page says whose wallet
        // these controls belong to, and a parent may have several children.
        $topbarSubtitleMobile = true;
        require __DIR__ . '/../includes/partials/topbar_parent.php';
        ?>

        <div class="parent-content parent-content--wide">

            <a href="<?= PARENT_URL ?>/dashboard.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
            </a>

            <div id="globalMsg"></div>

            <!-- Wallet stats -->
            <div class="status-bar">
                <div class="stat-pill">
                    <strong>&#8369;<?= number_format((float)$student['balance'], 2) ?></strong>
                    <span>Current Balance</span>
                </div>
                <div class="stat-pill">
                    <strong><?= htmlspecialchars($student['studentID'] ?? 'N/A') ?></strong>
                    <span>School ID</span>
                </div>
                <div class="stat-pill">
                    <strong id="statusPillText"><?= (int)$student['is_frozen'] ? 'Frozen' : 'Active' ?></strong>
                    <span>Wallet Status</span>
                </div>
            </div>

            <div class="parent-grid parent-grid--even">

                <div class="parent-grid-col">
                    <!-- Freeze toggle -->
                    <div class="control-card">
                        <h5><i class="fa-solid fa-lock me-2" style="color:var(--gp-danger)"></i>Freeze Wallet</h5>
                        <p>When frozen, the student cannot make any POS purchases or token transfers. Cash top-ups by Finance are still allowed.</p>
                        <div class="control-row">
                            <div class="toggle-wrap">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="freezeToggle" <?= $student['is_frozen'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <span class="toggle-label" id="freezeLabel"><?= $student['is_frozen'] ? 'Wallet is Frozen' : 'Wallet is Active' ?></span>
                                    <span class="toggle-sublabel" id="freezeSub"><?= $student['is_frozen'] ? 'Toggle off to unfreeze.' : 'Toggle on to freeze immediately.' ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Daily spending limit -->
                    <div class="control-card">
                        <h5><i class="fa-solid fa-gauge me-2" style="color:var(--gp-green-700)"></i>Daily Spending Limit</h5>
                        <p>Limits how much the student can spend in a single day across POS purchases and token transfers. Set to ₱0.00 to disable the limit.</p>
                        <form class="limit-form" id="limitForm">
                            <div>
                                <label for="limitInput">Daily limit (₱)</label>
                                <input type="number" id="limitInput" min="0" step="1" placeholder="0.00"
                                       value="<?= number_format((float)$student['daily_spend_limit'], 2, '.', '') ?>">
                            </div>
                            <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk me-1"></i>Save Limit</button>
                        </form>
                        <p style="margin-top:10px;margin-bottom:0;font-size:12px;color:var(--gp-muted);">
                            Today's spending is tracked in real time. The limit resets at midnight.
                        </p>
                    </div>
                </div>

                <div class="parent-grid-col">
                    <!-- View ledger link -->
                    <div class="control-card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                        <div>
                            <h5 style="margin-bottom:4px;"><i class="fa-solid fa-receipt me-2" style="color:var(--gp-green-700)"></i>Transaction Ledger</h5>
                            <p style="margin:0;">View the full read-only transaction history for this wallet.</p>
                        </div>
                        <a href="<?= PARENT_URL ?>/student.php?uid=<?= $targetUid ?>" class="btn-save" style="text-decoration:none;padding:9px 18px;font-size:13px;display:inline-block;">
                            <i class="fa-solid fa-eye me-1"></i>View Ledger
                        </a>
                    </div>

                    <!-- Unlink student -->
                    <div class="control-card" style="border-color:#f0b8b2;">
                        <h5 style="color:var(--gp-danger);"><i class="fa-solid fa-user-minus me-2"></i>Unlink Student</h5>
                        <p>Remove your link to this student. You will no longer be able to view their wallet or apply controls. <strong>Existing controls (freeze, daily limit) will remain set</strong> on the wallet until a Finance administrator changes them.</p>
                        <button class="unlink-btn" onclick="unlinkStudent()"><i class="fa-solid fa-trash me-1"></i>Unlink <?= htmlspecialchars($student['first_name']) ?></button>
                    </div>
                </div>

            </div>

        </div>
    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
<script>
function toggleParentSidebar() {
    document.getElementById('parentSidebar').classList.toggle('collapsed');
}

function showMsg(text, type) {
    const el = document.getElementById('globalMsg');
    el.innerHTML = `<div class="flash-msg ${type}">${text}</div>`;
    setTimeout(() => { el.innerHTML = ''; }, 3000);
}

async function apiPost(action, extra) {
    const res = await fetch('<?= PARENT_URL ?>/api/controls.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action, student_user_id: <?= $targetUid ?>, ...extra})
    });
    return res.json();
}

document.getElementById('freezeToggle').addEventListener('change', async function() {
    const toggle = this;
    const frozen = toggle.checked ? 1 : 0;
    toggle.disabled = true;

    try {
        const data = await apiPost('set_frozen', {value: frozen});
        if (data.success) {
            document.getElementById('freezeLabel').textContent    = frozen ? 'Wallet is Frozen' : 'Wallet is Active';
            document.getElementById('freezeSub').textContent      = frozen ? 'Toggle off to unfreeze.' : 'Toggle on to freeze immediately.';
            document.getElementById('statusPillText').textContent = frozen ? 'Frozen' : 'Active';
            showMsg(frozen ? 'Wallet frozen successfully.' : 'Wallet unfrozen.', 'success');
        } else {
            toggle.checked = !toggle.checked;
            showMsg(data.error || 'Failed to update.', 'error');
        }
    } catch(err) {
        toggle.checked = !toggle.checked;
        showMsg('Request failed. Check your connection.', 'error');
    } finally {
        toggle.disabled = false;
    }
});

document.getElementById('limitForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn    = this.querySelector('button[type="submit"]');
    const amount = parseFloat(document.getElementById('limitInput').value) || 0;
    const orig   = btn.innerHTML;
    btn.disabled  = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';
    try {
        const data = await apiPost('set_daily_limit', {amount});
        showMsg(data.success ? 'Daily limit saved.' : (data.error || 'Failed.'), data.success ? 'success' : 'error');
    } catch(err) {
        showMsg('Request failed. Check your connection.', 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = orig;
    }
});

async function unlinkStudent() {
    const confirmed = await gpConfirm({
        title: 'Unlink this student?',
        body: 'You will no longer be able to view this wallet, send allowances, or apply controls.',
        detail: [['Student', <?= json_encode($studentName, JSON_HEX_TAG | JSON_HEX_AMP) ?>]],
        note: 'Existing controls (freeze, daily limit) stay on the wallet until a Finance administrator changes them. To link again, the student will have to approve a new request.',
        danger: true,
        confirmLabel: 'Unlink'
    });
    if (!confirmed) return;
    const data = await apiPost('unlink_student', {});
    if (data.success) {
        window.location.href = '<?= PARENT_URL ?>/dashboard.php';
    } else {
        showMsg(data.error || 'Failed to unlink.', 'error');
    }
}
</script>

<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
