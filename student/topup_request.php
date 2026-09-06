<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';

gjc_require_role(['student']);
gjc_enforce_graduate_lock($db);
gjc_ensure_operational_tables($db);

$currentUser = gjc_current_user($db);
$wallet = gjc_student_wallet($db, $currentUser['id']);
$studentName = $currentUser['name'];
$currentBalance = (float) $wallet['balance'];
$notice = '';
$error = '';

$maxTopup = gjc_setting($db, 'topup_max_per_request');

$isGraduated = gjc_student_graduated($db, (int) $currentUser['id']);
$isFrozen = false;
if ($wallet['id'] > 0) {
    $fz = $db->prepare("SELECT is_frozen FROM student_wallets WHERE id = ?");
    $fz->execute([$wallet['id']]);
    $isFrozen = (int) $fz->fetchColumn() === 1;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $amount = filter_var($_POST['amount'] ?? '', FILTER_VALIDATE_FLOAT);
    $method = trim((string) ($_POST['payment_method'] ?? 'Cash at Cashier'));

    if (!gjc_csrf_verify()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif ($isGraduated) {
        $error = 'Account locked: graduated.';
    } elseif ($isFrozen) {
        $error = 'Your wallet is frozen by a parent or guardian. Top-up requests are disabled.';
    } elseif (!$amount || $amount <= 0) {
        $error = 'Enter a valid top-up amount.';
    } elseif ($amount > $maxTopup) {
        $error = 'Maximum top-up per request is ' . gjc_money($maxTopup) . '.';
    } elseif ($wallet['id'] <= 0) {
        $error = 'Your student wallet is not ready. Contact the finance office.';
    } else {
        $reference = gjc_reference('TOP');
        $stmt = $db->prepare(
            "INSERT INTO topup_requests
                (user_id, student_wallet_id, amount, payment_method, status, reference_no)
             VALUES (?, ?, ?, ?, 'pending', ?)"
        );
        $stmt->execute([$currentUser['id'], $wallet['id'], $amount, $method, $reference]);
        $notice = "Top-up request {$reference} was submitted for cashier approval.";
    }
}

$stmt = $db->prepare(
    "SELECT reference_no, amount, payment_method, status, created_at
       FROM topup_requests
      WHERE user_id = ?
      ORDER BY created_at DESC
      LIMIT 8"
);
$stmt->execute([$currentUser['id']]);
$recentTopups = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// pending → gold, approved/completed → green, rejected/declined → red
function gjc_topup_pill_tone(string $status): string
{
    $status = strtolower($status);
    if (in_array($status, ['approved', 'completed', 'success'], true)) {
        return 'green';
    }
    if (in_array($status, ['rejected', 'declined', 'failed', 'cancelled'], true)) {
        return 'red';
    }
    if ($status === 'pending') {
        return 'gold';
    }
    return 'gray';
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'topup';
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
    <title>Top-Up Wallet | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=30">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_profile.css?v=13">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_topup.css?v=7">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=2">
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_student.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'Top-Up Wallet';
            $topbarSubtitle = 'Submit a request to add funds to your student wallet.';
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
                            <span id="sdBalance"><?= gjc_gc_amount($currentBalance) ?></span><span class="sd-unit">GC</span>
                        </div>
                        <div class="sd-gc-row">
                            <span class="sd-gc-badge">&#8776; &#8369;<span id="sdBalancePhp"><?= number_format($currentBalance, 2) ?></span></span>
                            <span class="sd-gc-rate">&#8369;10 = 1 GC</span>
                        </div>
                        <div class="sd-balance-actions">
                            <a class="sd-btn-ghost" href="<?= STUDENT_URL ?>/transfer.php"><i class="fa-solid fa-paper-plane"></i>Send</a>
                            <a class="sd-btn-ghost" href="<?= STUDENT_URL ?>/withdraw.php"><i class="fa-solid fa-money-bill-wave"></i>Withdraw</a>
                        </div>
                    </div>
                    <div class="sd-balance-holder">
                        <strong><?= $e($studentName) ?></strong>
                        <span class="sd-holder-id"><?= $e($studentID) ?></span>
                        <span class="sd-role-badge">STUDENT</span>
                    </div>
                </section>

                <!-- Request form + guide -->
                <section class="tu-grid">

                    <div class="sd-panel">
                        <div class="sd-panel-head">
                            <div>
                                <h3>Request Top-Up</h3>
                                <p>Enter your desired amount and choose a payment method.</p>
                            </div>
                        </div>

                        <?php if ($isGraduated): ?>
                        <div class="pf-alert is-error" style="margin-bottom:14px">
                            <i class="fa-solid fa-graduation-cap"></i>
                            Account locked: graduated. Top-up requests are disabled.
                        </div>
                        <?php elseif ($isFrozen): ?>
                        <div class="pf-alert is-error" style="margin-bottom:14px">
                            <i class="fa-solid fa-snowflake"></i>
                            Your wallet is currently frozen by a parent or guardian, so top-up requests are disabled.
                        </div>
                        <?php endif; ?>

                        <form method="POST" class="pf-form" data-busy="Submitting…">
                            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

                            <div class="pf-field">
                                <label>Amount (&#8369;)</label>
                                <div class="tu-money-input">
                                    <span>&#8369;</span>
                                    <input type="number" name="amount" id="topupAmount" placeholder="0.00" min="1"
                                        step="0.01" <?= ($isGraduated || $isFrozen) ? 'disabled' : '' ?> required>
                                </div>
                                <div class="tu-equiv" id="tuEquiv"></div>
                            </div>

                            <div class="tu-quick">
                                <button type="button" onclick="setTopupAmount(100)">&#8369;100</button>
                                <button type="button" onclick="setTopupAmount(200)">&#8369;200</button>
                                <button type="button" onclick="setTopupAmount(500)">&#8369;500</button>
                                <button type="button" onclick="setTopupAmount(1000)">&#8369;1,000</button>
                                <button type="button" onclick="setTopupAmount(2000)">&#8369;2,000</button>
                            </div>

                            <div class="pf-field">
                                <label>Payment Method</label>
                                <div class="tu-methods">

                                    <label class="tu-method selected">
                                        <input type="radio" name="payment_method" value="Cash at Cashier" checked>
                                        <div class="tu-method-icon">
                                            <img src="<?= ICONS_URL ?>/add_cash.png" alt="">
                                        </div>
                                        <div>
                                            <strong>Cash at Cashier</strong>
                                            <span>Bring cash to the Accountancy Office</span>
                                        </div>
                                    </label>

                                    <label class="tu-method">
                                        <input type="radio" name="payment_method" value="GCash">
                                        <div class="tu-method-icon">
                                            <img src="<?= ICONS_URL ?>/gcash.png" alt="">
                                        </div>
                                        <div>
                                            <strong>GCash</strong>
                                            <span>09XX Campus GCash number</span>
                                        </div>
                                    </label>

                                    <label class="tu-method">
                                        <input type="radio" name="payment_method" value="Maya">
                                        <div class="tu-method-icon">
                                            <img src="<?= ICONS_URL ?>/maya.png" alt="">
                                        </div>
                                        <div>
                                            <strong>Maya</strong>
                                            <span>Maya linked campus account</span>
                                        </div>
                                    </label>

                                </div>
                            </div>

                            <button type="submit" class="pf-btn" <?= ($isGraduated || $isFrozen) ? 'disabled' : '' ?>>
                                <i class="fa-solid fa-circle-plus me-1"></i> Submit Top-Up Request
                            </button>
                        </form>
                    </div>

                    <div class="sd-panel">
                        <div class="sd-panel-head">
                            <div>
                                <h3>Top-Up Guide</h3>
                                <p>How your wallet top-up request works.</p>
                            </div>
                        </div>

                        <div class="tu-steps">
                            <div class="tu-step">
                                <strong>1</strong>
                                <span>Enter the amount you want to add.</span>
                            </div>
                            <div class="tu-step">
                                <strong>2</strong>
                                <span>Select your preferred payment method.</span>
                            </div>
                            <div class="tu-step">
                                <strong>3</strong>
                                <span>Submit your request for cashier verification.</span>
                            </div>
                            <div class="tu-step">
                                <strong>4</strong>
                                <span>Your wallet balance updates after approval.</span>
                            </div>
                        </div>

                        <div class="tu-limit">
                            <span>Daily Top-Up Limit</span>
                            <strong>&#8369;5,000</strong>
                            <p>= 500 GC. Requests above the limit may require manual approval.</p>
                        </div>
                    </div>

                </section>

                <!-- Recent requests -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>Recent Top-Up Requests</h3>
                            <p>Track your latest wallet top-up submissions.</p>
                        </div>
                        <a href="<?= STUDENT_URL ?>/history.php" class="sd-viewall">Full History</a>
                    </div>

                    <?php if (empty($recentTopups)): ?>
                    <div class="sd-empty">
                        <i class="fa-regular fa-folder-open"></i>
                        No top-up requests yet. Submit your first top-up request to add funds to your wallet.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle js-datatable" id="studentTopupRequestsTable" data-page-length="8">
                            <thead>
                                <tr>
                                    <th data-card="title">Reference</th>
                                    <th data-card="amount">Amount</th>
                                    <th data-card="hide">Method</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($recentTopups as $topup): ?>
                                <tr>
                                    <td><?= $e($topup['reference_no']) ?></td>
                                    <td><?= gjc_gc_price((float) $topup['amount']) ?></td>
                                    <td><span class="pf-pill gray"><?= $e($topup['payment_method']) ?></span></td>
                                    <td><span class="pf-pill <?= gjc_topup_pill_tone((string) $topup['status']) ?>"><?= $e(ucfirst($topup['status'])) ?></span></td>
                                    <td><?= $e(date('M d, Y h:i A', strtotime($topup['created_at']))) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_student.php'; ?>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <script src="<?= JS_URL ?>/gp_form_busy.js?v=1"></script>
    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>

    <script>
    const PESOS_PER_GC = <?= GJC_PESOS_PER_GC ?>;
    // Service fee is added ON TOP of the requested amount — the student is
    // credited what they type and pays that plus the fee at the cashier.
    const TU_FEE_RATE = <?= json_encode(CirculationEngine::FEE_SYSTEM_RATE) ?>;
    const TU_FEE_PCT  = <?= json_encode(CirculationEngine::ratePct(CirculationEngine::FEE_SYSTEM_RATE)) ?>;
    const topupAmountEl = document.getElementById("topupAmount");
    const tuEquiv = document.getElementById("tuEquiv");

    const tuPeso = n => "₱" + n.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Live "you receive X GC — pay ₱Y at the cashier" line under the ₱ input.
    function tuRenderEquiv() {
        const php = parseFloat(topupAmountEl.value) || 0;
        if (php <= 0) { tuEquiv.textContent = ""; return; }
        const fee   = Math.round(php * TU_FEE_RATE * 100) / 100;
        const total = Math.round((php + fee) * 100) / 100;
        tuEquiv.textContent =
            "You receive ≈ " + (+((php / PESOS_PER_GC).toFixed(2))).toLocaleString("en-PH", { maximumFractionDigits: 2 }) + " GC"
            + " — pay " + tuPeso(total) + " at the cashier (" + tuPeso(fee) + " service fee, " + TU_FEE_PCT + ").";
    }
    topupAmountEl.addEventListener("input", tuRenderEquiv);

    function setTopupAmount(amount) {
        topupAmountEl.value = amount;
        tuRenderEquiv();
    }

    document.querySelectorAll(".tu-method").forEach(function(card) {
        card.addEventListener("click", function() {
            document.querySelectorAll(".tu-method").forEach(function(item) {
                item.classList.remove("selected");
            });

            card.classList.add("selected");
            card.querySelector("input").checked = true;
        });
    });
    </script>

</body>

</html>
