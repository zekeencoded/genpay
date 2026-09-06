<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['student']);
gjc_ensure_operational_tables($db);

$currentUser = gjc_current_user($db);
$wallet      = gjc_student_wallet($db, $currentUser['id']);
$studentName = $currentUser['name'];
$balance     = (float) $wallet['balance'];
$notice = '';
$error  = '';

// Is this wallet frozen by a parent/guardian? Frozen wallets cannot cash out.
$isFrozen = false;
if ($wallet['id'] > 0) {
    $fz = $db->prepare("SELECT is_frozen FROM student_wallets WHERE id = ?");
    $fz->execute([$wallet['id']]);
    $isFrozen = (int) $fz->fetchColumn() === 1;
}

// Graduation freezes the wallet too (mark_graduate sets is_frozen = 1), but
// withdraw is the one action a graduate is still allowed — it's how they get
// their remaining balance out. So graduation overrides the frozen block here;
// every other student page redirects graduates away via gjc_enforce_graduate_lock().
$isGraduated = gjc_student_graduated($db, (int) $currentUser['id']);
if ($isGraduated) {
    $isFrozen = false;
}

// Total already queued (pending) so we never let requests exceed the balance.
$pendingTotal = 0.0;
if ($wallet['id'] > 0) {
    $pStmt = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests
          WHERE user_id = ? AND status = 'pending'"
    );
    $pStmt->execute([$currentUser['id']]);
    $pendingTotal = (float) $pStmt->fetchColumn();
}
$withdrawable = max(0, $balance - $pendingTotal);
$minWithdraw = gjc_setting($db, 'withdraw_min_amount');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $amount = filter_var($_POST['amount'] ?? '', FILTER_VALIDATE_FLOAT);

    if (!gjc_csrf_verify()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif ($isFrozen) {
        $error = 'Your wallet is frozen by a parent or guardian. Withdrawals are disabled.';
    } elseif (!$amount || $amount <= 0) {
        $error = 'Enter a valid withdrawal amount.';
    } elseif ($amount < $minWithdraw) {
        $error = 'Minimum withdrawal amount is ' . gjc_money($minWithdraw) . '.';
    } elseif ($wallet['id'] <= 0) {
        $error = 'Your student wallet is not ready. Contact the finance office.';
    } elseif ($amount > $balance) {
        $error = 'Requested amount is higher than your available balance.';
    } elseif ($amount > $withdrawable) {
        $error = 'You already have ' . gjc_money($pendingTotal) . ' in pending withdrawals. '
               . 'Together they would exceed your balance. Available to request: ' . gjc_money($withdrawable) . '.';
    } else {
        $reference = gjc_reference('WTH');
        $stmt = $db->prepare(
            "INSERT INTO withdrawal_requests
                (user_id, student_wallet_id, amount, method, status, reference_no)
             VALUES (?, ?, ?, 'Cashier Release', 'pending', ?)"
        );
        $stmt->execute([$currentUser['id'], $wallet['id'], $amount, $reference]);
        $notice = "Withdrawal request {$reference} was submitted for cashier release.";

        // Refresh the pending/withdrawable figures after a successful submit.
        $pendingTotal += (float) $amount;
        $withdrawable = max(0, $balance - $pendingTotal);
    }
}

$stmt = $db->prepare(
    "SELECT reference_no, amount, method, status, created_at
       FROM withdrawal_requests
      WHERE user_id = ?
      ORDER BY created_at DESC
      LIMIT 8"
);
$stmt->execute([$currentUser['id']]);
$recentWithdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// pending → gold, released → green, rejected → red
function gjc_withdraw_pill_tone(string $status): string
{
    $status = strtolower($status);
    if (in_array($status, ['released', 'approved', 'completed'], true)) {
        return 'green';
    }
    if (in_array($status, ['rejected', 'declined', 'cancelled', 'failed'], true)) {
        return 'red';
    }
    if ($status === 'pending') {
        return 'gold';
    }
    return 'gray';
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'withdraw';
$csrfToken = gjc_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdraw | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=30">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_profile.css?v=13">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_topup.css?v=7">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_send.css?v=7">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=2">
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_student.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'Withdraw';
            $topbarSubtitle = 'Cash out your GenCoins at the cashier.';
            require __DIR__ . '/../includes/partials/topbar_student.php';
            ?>

            <div class="sd-content">

                <?php if ($notice): ?>
                <div class="pf-alert">
                    <i class="fa-solid fa-circle-check"></i>
                    <?= $e($notice) ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="pf-alert is-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= $e($error) ?>
                </div>
                <?php endif; ?>

                <!-- Balance card -->
                <section class="sd-balance">
                    <div>
                        <span class="sd-balance-label">Current Balance</span>
                        <div class="sd-balance-amount">
                            <span id="sdBalance"><?= gjc_gc_amount($balance) ?></span><span class="sd-unit">GC</span>
                        </div>
                        <div class="sd-gc-row">
                            <span class="sd-gc-badge">&#8776; &#8369;<span id="sdBalancePhp"><?= number_format($balance, 2) ?></span></span>
                            <span class="sd-gc-rate">&#8369;10 = 1 GC</span>
                        </div>
                        <div class="sd-balance-actions">
                            <a class="sd-btn-ghost" href="<?= STUDENT_URL ?>/transfer.php"><i class="fa-solid fa-paper-plane"></i>Send</a>
                        </div>
                    </div>
                    <div class="sd-balance-holder">
                        <strong><?= $e($studentName) ?></strong>
                        <span class="sd-holder-id"><?= $e($studentID) ?></span>
                        <span class="sd-role-badge">STUDENT</span>
                    </div>
                </section>

                <!-- Request form + recent withdrawals -->
                <section class="tu-grid">

                    <div class="sd-panel">
                        <div class="sd-panel-head">
                            <div>
                                <h3>Request a Withdrawal</h3>
                                <p>Submit a cash-out request. A cashier will review it and hand over the cash at the finance office.</p>
                            </div>
                        </div>

                        <?php if ($isGraduated): ?>
                        <div class="pf-alert" style="margin-top:14px">
                            <i class="fa-solid fa-graduation-cap"></i>
                            Your account is graduated and locked from all other features. You can still withdraw your remaining balance below.
                        </div>
                        <?php elseif ($isFrozen): ?>
                        <div class="pf-alert is-warn" style="margin-top:14px">
                            <i class="fa-solid fa-snowflake"></i>
                            Your wallet is currently frozen by a parent or guardian, so withdrawals are disabled.
                        </div>
                        <?php endif; ?>

                        <form method="POST" id="withdrawForm" autocomplete="off" class="pf-form" data-busy="Submitting…">
                            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

                            <div class="wd-stats">
                                <div class="wd-stat is-gold">
                                    <span>Pending Requests</span>
                                    <strong><?= gjc_gc_amount($pendingTotal) ?> GC</strong>
                                    <small>&#8776; <?= gjc_money($pendingTotal) ?></small>
                                </div>
                                <div class="wd-stat">
                                    <span>Free to Withdraw</span>
                                    <strong><?= gjc_gc_amount($withdrawable) ?> GC</strong>
                                    <small>&#8776; <?= gjc_money($withdrawable) ?></small>
                                </div>
                            </div>

                            <div class="pf-field">
                                <label for="amount">Amount to Withdraw</label>
                                <div class="tu-money-input">
                                    <span>&#8369;</span>
                                    <input type="number" id="amount" name="amount"
                                        min="1" max="<?= $withdrawable ?>" step="0.01" placeholder="0.00"
                                        <?= ($isFrozen || $withdrawable < 1) ? 'disabled' : '' ?>>
                                </div>
                                <div class="sg-equiv" id="wdEquiv"></div>
                            </div>

                            <div class="tu-quick">
                                <?php foreach ([50, 100, 200, 500] as $chip): ?>
                                    <?php if ($chip <= $withdrawable): ?>
                                    <button type="button" class="wd-chip" data-amt="<?= $chip ?>">&#8369;<?= $chip ?></button>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($withdrawable >= 1): ?>
                                <button type="button" class="wd-chip" data-amt="<?= $withdrawable ?>">Max (<?= gjc_money($withdrawable) ?>)</button>
                                <?php endif; ?>
                            </div>

                            <div class="pf-note">
                                <i class="fa-solid fa-circle-info me-1"></i>
                                Your balance is held until the cashier releases the cash. It is only deducted when the request
                                is marked <strong>Released</strong>. You can cancel by visiting the finance office before release.
                            </div>

                            <button type="submit" class="pf-btn pf-btn--block" id="wdSubmit"
                                <?= ($isFrozen || $withdrawable < 1) ? 'disabled' : '' ?>>
                                <i class="fa-solid fa-money-bill-wave me-1"></i>
                                <?= $withdrawable < 1 ? 'No Balance to Withdraw' : 'Submit Withdrawal Request' ?>
                            </button>
                        </form>
                    </div>

                    <div class="sd-panel">
                        <div class="sd-panel-head">
                            <div>
                                <h3>Recent Withdrawals</h3>
                                <p>Your latest cash-out requests and their status.</p>
                            </div>
                            <a href="<?= STUDENT_URL ?>/history.php" class="sd-viewall">Full History</a>
                        </div>

                        <?php if (empty($recentWithdrawals)): ?>
                        <div class="sd-empty">
                            <i class="fa-regular fa-folder-open"></i>
                            No withdrawals yet. Submit a request to cash out at the finance office.
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr style="font-size:12px;text-transform:uppercase;color:var(--sd-muted)">
                                        <th data-card="title">Reference</th>
                                        <th data-card="amount">Amount</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentWithdrawals as $w): ?>
                                    <tr>
                                        <td class="sg-ref"><?= $e($w['reference_no']) ?></td>
                                        <td><?= gjc_gc_price((float) $w['amount']) ?></td>
                                        <td><span class="pf-pill <?= gjc_withdraw_pill_tone((string) $w['status']) ?>"><?= $e(ucfirst($w['status'])) ?></span></td>
                                        <td style="font-size:12px;color:var(--sd-muted)"><?= $e(date('M d, h:i A', strtotime($w['created_at']))) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                </section>

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_student.php'; ?>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>
    <script src="<?= JS_URL ?>/gp_form_busy.js?v=1"></script>
    <script>
    const wdAmount = document.getElementById('amount');
    const wdEquiv  = document.getElementById('wdEquiv');
    const WD_MAX   = <?= json_encode((float) $withdrawable) ?>;
    const WD_MIN   = <?= json_encode((float) $minWithdraw) ?>;
    const PESOS_PER_GC = <?= GJC_PESOS_PER_GC ?>;

    function wdRender() {
        const php = parseFloat(wdAmount.value) || 0;
        wdEquiv.style.color = '';
        wdEquiv.textContent = php > 0
            ? '≈ ' + (php / PESOS_PER_GC).toLocaleString('en-PH', { maximumFractionDigits: 2 }) + ' GenCoin'
            : '';
    }
    if (wdAmount) {
        wdAmount.addEventListener('input', wdRender);
        document.querySelectorAll('.wd-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                wdAmount.value = parseFloat(chip.dataset.amt).toFixed(2);
                wdRender();
                wdAmount.focus();
            });
        });
        document.getElementById('withdrawForm').addEventListener('submit', e => {
            const php = parseFloat(wdAmount.value) || 0;
            if (php < WD_MIN || php > WD_MAX) {
                e.preventDefault();
                wdEquiv.textContent = 'Enter an amount between ₱' + WD_MIN.toLocaleString('en-PH', { minimumFractionDigits: 2 })
                    + ' and ₱' + WD_MAX.toLocaleString('en-PH', { minimumFractionDigits: 2 }) + '.';
                wdEquiv.style.color = 'var(--sd-red)';
            }
        });
    }
    </script>

</body>

</html>
