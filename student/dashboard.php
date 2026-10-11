<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['student']);
gjc_enforce_student_lock($db);

if (isset($_SESSION['force_change'])) {
    header('Location: ' . BASE_URL . '/change_password.php');
    exit();
}

$currentUser = gjc_current_user($db);
$wallet      = gjc_student_wallet($db, (int) $currentUser['id']);
$studentName = $currentUser['name'];
$studentFirstName = trim((string) ($currentUser['raw']['first_name'] ?? ''));
if ($studentFirstName === '') {
    $studentFirstName = preg_split('/\s+/', trim($studentName))[0];
}
$balance     = (float) $wallet['balance'];

// Not enrolled for the active school year. gjc_enforce_student_lock() has
// already turned away the money-moving pages and sent them here; the balance
// stays on screen because it is still theirs and is not going anywhere.
$walletState  = gjc_student_wallet_state($db, (int) $currentUser['id']);
$isUnenrolled = $walletState === 'frozen_unenrolled';
// Graduated / transferred out: the wallet is closed and the remaining balance
// is paid out by finance as a cheque, off-system.
$isExited     = $walletState === 'frozen_exit';
$activeYearName = gjc_active_school_year_name($db);

// Real school-issued ID (GJC2026-0001); the padded userID is only a fallback
// for accounts that never got a student_info row.
$studentID = 'GJC-' . str_pad((string) $currentUser['id'], 5, '0', STR_PAD_LEFT);
if (gjc_table_exists($db, 'student_info')) {
    $sidStmt = $db->prepare("SELECT studentID FROM student_info WHERE userID = ? LIMIT 1");
    $sidStmt->execute([(int) $currentUser['id']]);
    $realID = trim((string) $sidStmt->fetchColumn());
    if ($realID !== '') {
        $studentID = $realID;
    }
}

$totalSpent   = 0.0;
$totalTxns    = 0;
$transactions = [];

if ($wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
    $spentStmt = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM transactions
          WHERE student_wallet_id = ?
            AND transaction_type IN ('payment', 'voucher_payment')
            AND status = 'completed'"
    );
    $spentStmt->execute([$wallet['id']]);
    $totalSpent = (float) $spentStmt->fetchColumn();

    $countStmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE student_wallet_id = ?");
    $countStmt->execute([$wallet['id']]);
    $totalTxns = (int) $countStmt->fetchColumn();

    $txnStmt = $db->prepare(
        "SELECT reference_no, transaction_type, amount, created_at
           FROM transactions
          WHERE student_wallet_id = ?
          ORDER BY created_at DESC, id DESC
          LIMIT 5"
    );
    $txnStmt->execute([$wallet['id']]);
    $transactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Frozen wallets (parent controls) trump the users.status 'Active' — an
// Inactive account can't reach this page at all (gjc_require_role logs it out).
$isFrozen = false;
if ($wallet['id'] > 0 && $wallet['source'] === 'student_wallets') {
    $fzStmt = $db->prepare("SELECT is_frozen FROM student_wallets WHERE id = ?");
    $fzStmt->execute([$wallet['id']]);
    $isFrozen = (int) $fzStmt->fetchColumn() === 1;
}
$accountStatus = $isFrozen ? 'Frozen' : 'Active';

$hour = (int) date('H');
// $greeting = $hour < 12 ? 'Good morning'  : ($hour > 18 ? 'Good afternoon' : 'Good evening');

if ($hour < 12){
    $greeting = "Good Morning";
}
elseif ($hour <= 18 ){
    $greeting = "Good Afternoon";
}else{
    $greeting = "Good Evening";
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <style>
        /* Not-enrolled notice. Reversible state, so it reads as information,
           not alarm — warning tokens, never the danger scale. */
        .sd-enroll-notice { display: flex; gap: 14px; align-items: flex-start; background: var(--gp-warning-bg); border: 1px solid var(--gp-warning); border-radius: 16px; padding: 16px 18px; margin-bottom: 20px; }
        .sd-enroll-notice i { color: var(--gp-warning); font-size: 18px; line-height: 1.4; }
        .sd-enroll-notice h3 { font-size: 15px; font-weight: 700; color: var(--gp-warning); margin: 0 0 4px; }
        .sd-enroll-notice p { font-size: 13px; color: var(--gp-ink, #111); margin: 0; line-height: 1.55; }
    </style>
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_student.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = $e($greeting) . ', ' . $e($studentFirstName);
            $topbarSubtitle = "Here's what's happening with your wallet today.";
            // Kept on phones: this one is the greeting, not a caption
            // restating the title. It is the only page where the bar is
            // saying something to the student rather than labelling itself.
            $topbarSubtitleMobile = true;
            $topbarShowBell = true;
            require __DIR__ . '/../includes/partials/topbar_student.php';
            ?>

            <div class="sd-content">

                <?php if ($isExited): ?>
                <!-- Graduated / transferred out -->
                <div class="sd-enroll-notice">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <div>
                        <h3>Your wallet is closed</h3>
                        <p>
                            Your account is locked because you have graduated or transferred out. Your remaining
                            balance below is safe &mdash; please visit the finance office to collect it as a cheque.
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isUnenrolled): ?>
                <!-- Not enrolled for the active school year -->
                <div class="sd-enroll-notice">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        <h3>You are not enrolled for <?= $activeYearName ? $e($activeYearName) : 'the current school year' ?></h3>
                        <p>
                            Your balance below is intact and stays preserved &mdash; nothing has been taken from your
                            wallet. Paying at the canteen, sending GenCoin, and requesting a top-up are paused until
                            your enrollment is recorded. Please contact the registrar to sort this out.
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Balance card -->
                <section class="sd-balance">
                    <div>
                        <span class="sd-balance-label">Available Balance</span>
                        <div class="sd-balance-amount">
                            <span id="sdBalance"><?= gjc_gc_amount($balance) ?></span><span class="sd-unit">GC</span>
                        </div>
                        <div class="sd-gc-row">
                            <span class="sd-gc-badge">&#8776; &#8369;<span id="sdBalancePhp"><?= number_format($balance, 2) ?></span></span>
                            <span class="sd-gc-rate">&#8369;10 = 1 GC</span>
                        </div>
                    </div>
                    <div class="sd-balance-holder">
                        <strong><?= $e($studentName) ?></strong>
                        <span class="sd-holder-id"><?= $e($studentID) ?></span>
                        <span class="sd-role-badge">STUDENT</span>
                    </div>
                </section>

                <!-- Stat cards -->
                <section class="sd-stats">
                    <div class="sd-stat">
                        <div class="sd-stat-top">
                            <span>Total Spent</span>
                            <span class="sd-stat-icon is-spent"><i class="fa-solid fa-arrow-trend-up"></i></span>
                        </div>
                        <h2 class="sd-num" id="sdTotalSpent"><?= gjc_gc_amount($totalSpent) ?> GC</h2>
                        <p id="sdTotalSpentPhp">&#8776; &#8369;<?= number_format($totalSpent, 2) ?> in successful payments</p>
                    </div>
                    <div class="sd-stat">
                        <div class="sd-stat-top">
                            <span>Transactions</span>
                            <span class="sd-stat-icon is-txns"><i class="fa-solid fa-list-ul"></i></span>
                        </div>
                        <h2 class="sd-num" id="sdTotalTxns"><?= $totalTxns ?></h2>
                        <p>Wallet activity count</p>
                    </div>
                    <div class="sd-stat sd-stat--status">
                        <div class="sd-stat-top">
                            <span>Account Status</span>
                            <span class="sd-stat-icon is-status<?= $isFrozen ? ' is-frozen' : '' ?>" id="sdStatusIcon">
                                <i class="fa-solid <?= $isFrozen ? 'fa-snowflake' : 'fa-circle-check' ?>" id="sdStatusIconGlyph"></i>
                            </span>
                        </div>
                        <h2 id="sdStatus" class="<?= $isFrozen ? 'is-frozen-text' : '' ?>"><?= $e($accountStatus) ?></h2>
                        <p>Student wallet access</p>
                    </div>
                </section>

                <!-- Recent transactions -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>Recent Transactions</h3>
                            <p>Latest wallet activity</p>
                        </div>
                        <a href="<?= STUDENT_URL ?>/history.php" class="sd-viewall">View all</a>
                    </div>

                    <ul class="sd-txn-list" id="sdTxnList">
                        <?php foreach ($transactions as $t): $m = gjc_student_txn_meta((string) $t['transaction_type']); ?>
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

                    <div class="sd-empty" id="sdTxnEmpty" <?= $transactions ? 'hidden' : '' ?>>
                        <i class="fa-regular fa-folder-open"></i>
                        No transactions yet. Top up your wallet or scan a merchant QR to get started.
                    </div>
                </section>

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_student.php'; ?>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <script>
    // Keep balance, stats, and the recent list fresh (e.g. after a top-up
    // gets approved or a POS charge lands) without a full reload.
    const SD_API = "<?= STUDENT_URL ?>/api/wallet_summary.php";
    const PESOS_PER_GC = <?= GJC_PESOS_PER_GC ?>;

    const sdMoney = n => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    // Smart GC formatting: whole numbers stay whole ("2"), otherwise up to 2 decimals ("14.59").
    const sdGc = pesos => (+((pesos / PESOS_PER_GC).toFixed(2))).toLocaleString(undefined, { maximumFractionDigits: 2 });

    function sdTxnRow(t) {
        const li = document.createElement("li");
        li.className = "sd-txn sd-txn--" + t.slug;

        const icon = document.createElement("div");
        icon.className = "sd-txn-icon";
        const glyph = document.createElement("i");
        glyph.className = "fa-solid " + t.icon;
        icon.appendChild(glyph);

        const info = document.createElement("div");
        info.className = "sd-txn-info";
        const ref = document.createElement("div");
        ref.className = "sd-txn-ref";
        ref.textContent = t.ref;
        const type = document.createElement("div");
        type.className = "sd-txn-type";
        type.textContent = t.label;
        info.append(ref, type);

        const right = document.createElement("div");
        right.className = "sd-txn-right";
        const amount = document.createElement("div");
        amount.className = "sd-txn-amount" + (t.incoming ? " is-in" : "");
        amount.textContent = (t.incoming ? "+" : "−") + sdGc(t.amount) + " GC";
        const php = document.createElement("div");
        php.className = "sd-txn-php";
        php.textContent = "≈ ₱" + sdMoney(t.amount);
        const date = document.createElement("div");
        date.className = "sd-txn-date";
        date.textContent = t.date;
        // Year in its own span so the phone layout can drop it (see CSS).
        const year = document.createElement("span");
        year.className = "sd-txn-year";
        year.textContent = t.year || "";
        date.append(year);
        right.append(amount, php, date);

        li.append(icon, info, right);
        return li;
    }

    async function sdRefresh() {
        try {
            const res  = await fetch(SD_API);
            const data = await res.json();
            if (!data.success) return;

            document.getElementById("sdBalance").textContent      = sdGc(data.balance);
            document.getElementById("sdBalancePhp").textContent   = sdMoney(data.balance);
            document.getElementById("sdTotalSpent").textContent   = sdGc(data.total_spent) + " GC";
            document.getElementById("sdTotalSpentPhp").textContent = "≈ ₱" + sdMoney(data.total_spent) + " in successful payments";
            document.getElementById("sdTotalTxns").textContent    = data.total_txns;

            const frozen = data.account_status === "Frozen";
            const status = document.getElementById("sdStatus");
            status.textContent = data.account_status;
            status.classList.toggle("is-frozen-text", frozen);
            document.getElementById("sdStatusIcon").classList.toggle("is-frozen", frozen);
            document.getElementById("sdStatusIconGlyph").className = "fa-solid " + (frozen ? "fa-snowflake" : "fa-circle-check");

            const list  = document.getElementById("sdTxnList");
            const empty = document.getElementById("sdTxnEmpty");
            list.replaceChildren(...data.transactions.map(sdTxnRow));
            empty.hidden = data.transactions.length > 0;
        } catch (error) {
            // Keep the last known values on a transient network error.
        }
    }
    setInterval(sdRefresh, 15000);
    </script>

</body>

</html>
