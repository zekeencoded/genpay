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

$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour <= 18 ? 'Good Afternoon' : 'Good Evening');
$parentFirstName = trim((string) ($currentUser['raw']['first_name'] ?? '')) ?: (preg_split('/\s+/', trim((string) $currentUser['name']))[0] ?? '');
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Parent's own purchases. student_wallet_id IS NULL excludes allowances (which set both wallets).
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

// Link requests the student has not answered yet
$pendingStmt = $db->prepare(
    "SELECT u.first_name, u.last_name
       FROM parent_link_requests r
       JOIN users u ON u.userID = r.student_user_id
      WHERE r.parent_id = ?
      ORDER BY r.requested_at"
);
$pendingStmt->execute([$parentId]);
$pendingLinks = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Alerts are fetched client-side via AJAX (parent/api/alerts.php)

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
    <title>Guardian Dashboard | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_shell.css?v=27">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/wallet_widgets.css?v=1">
    <link rel="stylesheet" href="<?= CSS_URL ?>/parent_dashboard.css?v=22">
</head>
<body class="gp-theme">
<div class="parent-layout">

    <?php require __DIR__ . '/../includes/partials/sidebar_parent.php'; ?>

    <main class="parent-main">

        <?php
        $topbarTitle = $e($greeting) . ', ' . $e($parentFirstName);
        $topbarSubtitle = "Here's what's happening with your family's wallets today.";
        // Keep the greeting on phones, same as the student dashboard.
        $topbarSubtitleMobile = true;
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
                    <!-- Balance card (same markup as student/dashboard.php) -->
                    <section class="sd-balance">
                        <div>
                            <span class="sd-balance-label">Available Balance</span>
                            <div class="sd-balance-amount">
                                <span><?= gjc_gc_amount($myBalance) ?></span><span class="sd-unit">GC</span>
                            </div>
                            <div class="sd-gc-row">
                                <span class="sd-gc-badge">&#8776; &#8369;<?= number_format($myBalance, 2) ?></span>
                                <span class="sd-gc-rate">&#8369;10 = 1 GC</span>
                            </div>
                        </div>
                        <div class="sd-balance-holder">
                            <strong><?= $e($currentUser['name']) ?></strong>
                            <span class="sd-role-badge">PARENT</span>
                        </div>
                    </section>

                    <!-- Phone quick-access squares, each opening its own page. -->
                    <nav class="parent-quick" aria-label="Quick access">
                        <a href="<?= PARENT_URL ?>/students.php" class="parent-quick-tile">
                            <span class="parent-quick-icon parent-quick-icon--students"><i class="fa-solid fa-user-graduate"></i></span>
                            <span class="parent-quick-label">Students</span>
                        </a>
                        <a href="<?= PARENT_URL ?>/alerts.php" class="parent-quick-tile">
                            <span class="parent-quick-icon parent-quick-icon--alerts"><i class="fa-solid fa-bell"></i></span>
                            <span class="parent-quick-label">Alerts</span>
                            <span class="parent-quick-count" id="alertQuickCount" hidden></span>
                        </a>
                        <a href="<?= PARENT_URL ?>/link_student.php" class="parent-quick-tile">
                            <span class="parent-quick-icon parent-quick-icon--link"><i class="fa-solid fa-link"></i></span>
                            <span class="parent-quick-label">Link student</span>
                        </a>
                    </nav>

                    <!-- Parent's own wallet only (same markup as the student's Recent Transactions). -->
                    <section class="sd-panel">
                        <div class="sd-panel-head">
                            <div>
                                <h3>Recent Transactions</h3>
                                <p>Your own wallet activity</p>
                            </div>
                            <a href="<?= PARENT_URL ?>/activity.php?student=me" class="sd-viewall">View all</a>
                        </div>
                        <ul class="sd-txn-list">
                            <?php foreach ($myTxns as $t): $m = gjc_student_txn_meta((string) $t['transaction_type']); ?>
                            <li class="sd-txn sd-txn--<?= $e($m['slug']) ?>">
                                <div class="sd-txn-icon"><i class="fa-solid <?= $e($m['icon']) ?>"></i></div>
                                <div class="sd-txn-info">
                                    <div class="sd-txn-ref"><?= $e($t['reference_no']) ?></div>
                                    <div class="sd-txn-type"><?= $e($m['label']) ?></div>
                                </div>
                                <div class="sd-txn-right">
                                    <div class="sd-txn-amount<?= $m['incoming'] ? ' is-in' : '' ?>">
                                        <?= $m['incoming'] ? '+' : '&minus;' ?><?= gjc_gc_amount((float) $t['amount']) ?> GC
                                    </div>
                                    <div class="sd-txn-php">&#8776; &#8369;<?= number_format((float) $t['amount'], 2) ?></div>
                                    <div class="sd-txn-date"><?= $e(date('M j', strtotime((string) $t['created_at']))) ?><span class="sd-txn-year"><?= $e(date(', Y', strtotime((string) $t['created_at']))) ?></span></div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (!$myTxns): ?>
                        <div class="sd-empty">
                            <i class="fa-regular fa-folder-open"></i>
                            No transactions yet. Scan a stall's Wallet QR from your <a href="<?= PARENT_URL ?>/cart.php">Shop Cart</a>.
                        </div>
                        <?php endif; ?>
                    </section>

                    <!-- Linked Students. Hidden on phones: those live on students.php. -->
                    <div class="parent-card dash-desk-only">
                        <div class="parent-card-head">
                            <h5><i class="fa-solid fa-user-graduate me-2" style="color:var(--gp-green-700)"></i>Linked Students</h5>
                            <?php if (!empty($linkedStudents)): ?>
                            <a class="sd-viewall" href="<?= PARENT_URL ?>/activity.php">Their activity</a>
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

                        <!-- Link a Student, kept inside the list it adds to -->
                        <div class="link-student">
                            <h6><i class="fa-solid fa-link me-2" style="color:var(--gp-green-700)"></i>Link a Student</h6>
                            <p>Enter your child's school-issued student ID (e.g. <code>GJC2026-0001</code>) to link their wallet to your account.</p>
                            <form class="link-form" id="linkForm">
                                <input type="text" id="linkSchoolId" placeholder="Student School ID" maxlength="30" autocomplete="off">
                                <button type="submit" class="btn-link"><i class="fa-solid fa-link me-1"></i>Link Student</button>
                            </form>
                            <div id="linkMsg" style="margin-top:8px;font-size:13px;"></div>
                            <?php foreach ($pendingLinks as $pl): ?>
                            <p style="font-size:13px;color:var(--gp-muted);margin:8px 0 0;"><i class="fa-solid fa-hourglass-half me-1"></i>Waiting for <strong><?= htmlspecialchars(trim($pl['first_name'] . ' ' . $pl['last_name'])) ?></strong> to approve</p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="parent-grid-col">
                    <!-- Low Balance Alerts (AJAX). Hidden on phones: those live on alerts.php. -->
                    <div class="parent-card dash-desk-only">
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
