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

// Fetch or create parents record
$pStmt = $db->prepare("SELECT * FROM parents WHERE user_id = ?");
$pStmt->execute([$parentUserId]);
$parentRow = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$parentRow) {
    $db->prepare("INSERT IGNORE INTO parents (user_id) VALUES (?)")->execute([$parentUserId]);
    $pStmt->execute([$parentUserId]);
    $parentRow = $pStmt->fetch(PDO::FETCH_ASSOC);
}
$parentId         = (int) $parentRow['id'];
$alertThreshold   = (float) $parentRow['low_balance_threshold'];

gjc_ensure_parent_wallet_schema($db);
$myWallet   = gjc_parent_wallet($db, $parentId);
$myBalance  = (float) $myWallet['balance'];
$myWalletId = (int) ($myWallet['id'] ?? 0);

// The parent's own Shop Cart purchases. Parents spend from their own wallet
// now, so those rows get their own panel here instead of only ever appearing
// mixed into the students' rows on activity.php — the same split the wallet
// filter over there draws.
//
// The student_wallet_id guard is what separates the two: a parent purchase
// (parent/api/checkout.php) sets parent_wallet_id alone, while an allowance
// (CirculationEngine::allowanceTransfer) sets BOTH wallet ids on one row.
// Without it an allowance the parent SENT would be listed here as a purchase
// — and as a green "+" at that, since gjc_student_txn_meta() counts the
// allowance type as incoming, which it is for the child, not the sender.
$myTxns = [];
if ($myWalletId > 0 && gjc_table_exists($db, 'transactions')) {
    $myTxnStmt = $db->prepare(
        "SELECT reference_no, transaction_type, amount, created_at
           FROM transactions
          WHERE parent_wallet_id = ?
            AND COALESCE(student_wallet_id, 0) = 0
          ORDER BY created_at DESC, id DESC
          LIMIT 5"
    );
    $myTxnStmt->execute([$myWalletId]);
    $myTxns = $myTxnStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch linked students
$linkedStmt = $db->prepare(
    "SELECT u.userID, u.first_name, u.last_name,
            si.studentID,
            sw.id AS wallet_id, sw.balance, sw.is_frozen, sw.daily_spend_limit
       FROM parent_student_links psl
       JOIN users u ON u.userID = psl.student_user_id
       LEFT JOIN student_info si ON si.userID = u.userID
       LEFT JOIN student_wallets sw ON sw.user_id = u.userID
      WHERE psl.parent_id = ?
      ORDER BY u.last_name, u.first_name"
);
$linkedStmt->execute([$parentId]);
$linkedStudents = $linkedStmt->fetchAll(PDO::FETCH_ASSOC);

// Alerts are fetched client-side via AJAX (parent/api/alerts.php)

// Handle link errors/success from redirect
$linkError   = $_GET['link_error'] ?? '';
$linkSuccess = $_GET['link_success'] ?? '';

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=24">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_dashboard.css?v=12">
</head>
<body class="gp-theme">
<div class="parent-layout">

    <?php require __DIR__ . '/../includes/partials/sidebar_parent.php'; ?>

    <main class="parent-main">

        <?php
        $topbarTitle = 'Parent Dashboard';
        $topbarSubtitle = "Monitor your child's GenPay wallet and set spending controls.";
        require __DIR__ . '/../includes/partials/topbar_parent.php';
        ?>

        <div class="parent-content parent-content--wide">

            <?php if ($linkSuccess): ?>
            <div class="flash-msg success"><i class="fa-solid fa-circle-check me-1"></i> <?= htmlspecialchars($linkSuccess) ?></div>
            <?php elseif ($linkError): ?>
            <div class="flash-msg error"><i class="fa-solid fa-circle-xmark me-1"></i> <?= htmlspecialchars($linkError) ?></div>
            <?php endif; ?>

            <div class="parent-grid">

                <div class="parent-grid-col">
                    <!-- My Wallet -->
                    <div class="parent-card pd-wallet-card">
                        <div class="parent-card-head">
                            <h5><i class="fa-solid fa-wallet me-2" style="color:var(--gp-green-700)"></i>My Wallet</h5>
                        </div>
                        <div class="pd-wallet-row">
                            <div>
                                <div class="pd-wallet-amount"><?= gjc_gc_amount($myBalance) ?><span>GC</span></div>
                                <div class="pd-wallet-php">&#8776; &#8369;<?= number_format($myBalance, 2) ?> &middot; &#8369;10 = 1 GC</div>
                            </div>
                            <a class="btn-link pd-wallet-cta" href="<?= PARENT_URL ?>/cart.php"><i class="fa-solid fa-cart-shopping me-1"></i>Shop Cart</a>
                        </div>
                    </div>

                    <!-- My Transactions — the parent's own wallet only. Their
                         children's spending lives in Linked Students and on
                         activity.php; the two are deliberately never mixed
                         in this panel. -->
                    <div class="parent-card">
                        <div class="parent-card-head">
                            <h5><i class="fa-solid fa-basket-shopping me-2" style="color:var(--gp-green-700)"></i>My Transactions</h5>
                            <a class="pd-viewall" href="<?= PARENT_URL ?>/activity.php?student=me">View all</a>
                        </div>
                        <?php if (empty($myTxns)): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-basket-shopping"></i>
                            <p>You haven't bought anything yet.<br>Scan a stall's Wallet QR from your <a href="<?= PARENT_URL ?>/cart.php">Shop Cart</a>.</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($myTxns as $t): $m = gjc_student_txn_meta((string) $t['transaction_type']); ?>
                        <div class="pd-txn">
                            <div class="pd-txn-icon <?= $m['incoming'] ? 'is-in' : '' ?>"><i class="fa-solid <?= htmlspecialchars($m['icon']) ?>"></i></div>
                            <div class="pd-txn-info">
                                <strong><?= htmlspecialchars($m['label']) ?></strong>
                                <small><?= htmlspecialchars($t['reference_no']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime((string) $t['created_at']))) ?></small>
                            </div>
                            <div class="pd-txn-amount <?= $m['incoming'] ? 'is-in' : '' ?>">
                                <?= $m['incoming'] ? '+' : '&minus;' ?>&#8369;<?= number_format((float) $t['amount'], 2) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Linked Students -->
                    <div class="parent-card">
                        <div class="parent-card-head">
                            <h5><i class="fa-solid fa-user-graduate me-2" style="color:var(--gp-green-700)"></i>Linked Students</h5>
                            <?php if (!empty($linkedStudents)): ?>
                            <a class="pd-viewall" href="<?= PARENT_URL ?>/activity.php">Their activity</a>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($linkedStudents)): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-user-graduate"></i>
                            <p>No students linked yet.<br>Use the <strong>Link a Student</strong> form to link your child's account.</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($linkedStudents as $s):
                            $balLow = (float)$s['balance'] < $alertThreshold && $alertThreshold > 0;
                            $balChipClass = $balLow ? 'low' : 'ok';
                        ?>
                        <div class="student-row">
                            <div class="student-avatar"><?= strtoupper(substr($s['first_name'], 0, 1)) ?></div>
                            <div class="student-info">
                                <strong><?= htmlspecialchars(trim($s['first_name'] . ' ' . $s['last_name'])) ?></strong>
                                <small><?= htmlspecialchars($s['studentID'] ?? 'N/A') ?></small>
                            </div>
                            <span class="balance-chip <?= $balChipClass ?>">&#8369;<?= number_format((float)$s['balance'], 2) ?></span>
                            <?php if ($s['is_frozen']): ?>
                            <span class="frozen-badge"><i class="fa-solid fa-lock me-1"></i>Frozen</span>
                            <?php endif; ?>
                            <div class="student-actions">
                                <a href="<?= PARENT_URL ?>/student.php?uid=<?= (int)$s['userID'] ?>" class="btn-view"><i class="fa-solid fa-eye me-1"></i>Ledger</a>
                                <a href="<?= PARENT_URL ?>/controls.php?uid=<?= (int)$s['userID'] ?>" class="btn-controls"><i class="fa-solid fa-sliders me-1"></i>Controls</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="parent-grid-col">
                    <!-- Link a Student -->
                    <div class="parent-card">
                        <div class="parent-card-head">
                            <h5><i class="fa-solid fa-link me-2" style="color:var(--gp-green-700)"></i>Link a Student</h5>
                        </div>
                        <p style="font-size:13px;color:var(--gp-muted);margin-bottom:12px;">Enter your child's school-issued student ID (e.g. <code>GJC2026-0001</code>) to link their wallet to your account.</p>
                        <form class="link-form" id="linkForm">
                            <input type="text" id="linkSchoolId" placeholder="Student School ID" maxlength="30" autocomplete="off">
                            <button type="submit" class="btn-link"><i class="fa-solid fa-link me-1"></i>Link Student</button>
                        </form>
                        <div id="linkMsg" style="margin-top:8px;font-size:13px;"></div>
                    </div>

                    <!-- Low Balance Alerts (AJAX) -->
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

                        <!-- Threshold setting -->
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

            </div>

        </div>

    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
<script>
const ALERTS_API  = '<?= PARENT_URL ?>/api/alerts.php';
const CONTROLS_API = '<?= PARENT_URL ?>/api/controls.php';
const LINK_API    = '<?= PARENT_URL ?>/api/link.php';

function toggleParentSidebar() {
    document.getElementById('parentSidebar').classList.toggle('collapsed');
}

/* ── Link student ───────────────────────────────────────────── */
document.getElementById('linkForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const sid = document.getElementById('linkSchoolId').value.trim();
    const msg = document.getElementById('linkMsg');
    const btn = this.querySelector('[type="submit"]');
    if (btn.disabled) return;
    if (!sid) { msg.innerHTML = '<span style="color:var(--gp-danger)">Please enter a school ID.</span>'; return; }
    btn.disabled = true;
    msg.textContent = '';
    let linked = false;
    try {
        gpBusy(btn, 'Checking student...');
        msg.textContent = 'Checking student...';
        const lookupRes = await fetch(LINK_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'lookup_student', school_id: sid})
        });
        const student = await lookupRes.json();
        if (!student.success) {
            msg.innerHTML = '<span style="color:var(--gp-danger)">' + gpEsc(student.error || 'Student lookup failed.') + '</span>';
            return;
        }
        msg.textContent = '';
        const confirmed = await gpConfirm({
            title: 'Link this student?',
            body: 'Please check the name and school ID before linking this student to your account.',
            detail: [['Student Name', student.student_name], ['Student School ID', student.school_id]],
            note: 'You will be able to view their wallet and transactions, send allowances, and manage spending controls.',
            confirmLabel: 'Link Student'
        });
        if (!confirmed) {
            document.getElementById('linkSchoolId').focus();
            return;
        }
        btn.textContent = 'Linking...';
        msg.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Linking...';
        const res = await fetch(LINK_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'link_student', school_id: student.school_id})
        });
        const data = await res.json();
        if (data.success) {
            linked = true;
            msg.innerHTML = '<span style="color:var(--gp-success)"><i class="fa-solid fa-check me-1"></i>' + gpEsc(data.message) + '</span>';
            setTimeout(() => location.reload(), 1000);
        } else {
            msg.innerHTML = '<span style="color:var(--gp-danger)">' + gpEsc(data.error || 'Failed.') + '</span>';
        }
    } catch(err) {
        msg.innerHTML = '<span style="color:var(--gp-danger)">Request failed.</span>';
    } finally {
        if (!linked) {
            gpBusyDone(btn);
            btn.disabled = false;
        }
    }
});

/* ── Alert threshold ────────────────────────────────────────── */
document.getElementById('thresholdForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const val  = parseFloat(document.getElementById('thresholdInput').value) || 0;
    const msg  = document.getElementById('thresholdMsg');
    try {
        const res  = await fetch(CONTROLS_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'set_alert_threshold', amount: val})
        });
        const data = await res.json();
        msg.textContent  = data.success ? 'Saved!' : (data.error || 'Error');
        msg.style.color  = data.success ? 'var(--gp-success)' : 'var(--gp-danger)';
        setTimeout(() => { msg.textContent = ''; }, 2500);
    } catch(err) { msg.textContent = 'Error'; }
});

/* ── AJAX Alerts ────────────────────────────────────────────── */
function fmtDate(str) {
    const d = new Date(str.replace(' ', 'T'));
    return d.toLocaleDateString('en-PH', {month:'short', day:'numeric', year:'numeric'})
        + ' ' + d.toLocaleTimeString('en-PH', {hour:'numeric', minute:'2-digit'});
}

let _prevUnread = 0;

function showToast(msg) {
    let t = document.getElementById('alertToast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'alertToast';
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--gp-green-950);color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.2);opacity:0;transition:opacity .25s;max-width:300px;';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.style.opacity = '0'; }, 4000);
}

function renderAlerts(data) {
    const badge     = document.getElementById('alertBadge');
    const markBtn   = document.getElementById('markReadBtn');
    const container = document.getElementById('alertsContainer');
    const ts        = document.getElementById('alertsTimestamp');

    const count = parseInt(data.unread_count) || 0;

    if (count > 0) {
        badge.textContent   = count;
        badge.style.display = 'inline';
        markBtn.style.display = 'inline';
        if (count > _prevUnread && _prevUnread !== -1) {
            const diff = count - _prevUnread;
            showToast(`${diff} new low-balance alert${diff > 1 ? 's' : ''}`);
        }
    } else {
        badge.style.display   = 'none';
        markBtn.style.display = 'none';
    }
    _prevUnread = count;

    const now = new Date();
    ts.textContent = 'Updated ' + now.toLocaleTimeString('en-PH', {hour:'numeric', minute:'2-digit', second:'2-digit'});

    if (!data.alerts || data.alerts.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:24px 16px;color:var(--gp-muted);">
                <i class="fa-solid fa-bell-slash" style="font-size:28px;margin-bottom:8px;display:block;"></i>
                <span style="font-size:13px;">No alerts yet. Alerts appear when a linked student's balance drops below your threshold.</span>
            </div>`;
        return;
    }

    container.innerHTML = data.alerts.map(a => {
        const name   = ((a.first_name || '') + ' ' + (a.last_name || '')).trim();
        const unread = parseInt(a.is_read) === 0;
        return `
        <div class="alert-row" style="${unread ? 'background:var(--gp-warning-bg);border-radius:8px;padding:10px 12px;margin-bottom:4px;' : ''}">
            <div class="alert-icon${unread ? '' : ' read'}">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="alert-text">
                <strong>${name}'s balance dropped to &#8369;${parseFloat(a.balance_at_alert).toFixed(2)}</strong>
                <small>${fmtDate(a.created_at)} &bull; Threshold: &#8369;${parseFloat(a.threshold).toFixed(2)}
                    ${unread ? '<span style="margin-left:6px;background:var(--gp-warning-bg);color:var(--gp-warning);padding:1px 6px;border-radius:8px;font-size:11px;font-weight:700;">NEW</span>' : ''}
                </small>
            </div>
        </div>`;
    }).join('');
}

function setRefreshSpinning(on) {
    const icon = document.getElementById('refreshIcon');
    if (!icon) return;
    if (on) icon.classList.add('fa-spin');
    else    icon.classList.remove('fa-spin');
}

async function fetchAlerts() {
    setRefreshSpinning(true);
    try {
        const res = await fetch(ALERTS_API, {credentials: 'same-origin'});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.success) {
            renderAlerts(data);
        } else {
            document.getElementById('alertsContainer').innerHTML =
                `<div style="text-align:center;padding:20px;color:var(--gp-danger);font-size:13px;"><i class="fa-solid fa-circle-xmark me-1"></i>${data.error || 'Could not load alerts.'}</div>`;
        }
    } catch(err) {
        const container = document.getElementById('alertsContainer');
        if (container.querySelector('.fa-spinner')) {
            container.innerHTML =
                `<div style="text-align:center;padding:20px;color:var(--gp-muted);font-size:13px;"><i class="fa-solid fa-wifi me-1"></i>Could not reach alerts service. Will retry shortly.</div>`;
        }
    } finally {
        setRefreshSpinning(false);
    }
}

async function markAlertsRead() {
    try {
        const res = await fetch(ALERTS_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({action: 'mark_read'})
        });
        const data = await res.json();
        if (data.success) {
            _prevUnread = -1;
            fetchAlerts();
        }
    } catch(err) {}
}

/* Load on page open, then poll every 30 s */
document.addEventListener('DOMContentLoaded', () => {
    _prevUnread = -1;
    fetchAlerts();
    setInterval(fetchAlerts, 30000);
});
</script>

<?php require __DIR__ . '/../includes/partials/bottom_nav_parent.php'; ?>
</body>
</html>
