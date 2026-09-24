<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['merchant']);
if (gjc_is_merchant_staff()) {
    header('Location: ' . MERCHANT_URL . '/dashboard.php');
    exit;
}
gjc_ensure_operational_tables($db);

$currentUser = gjc_current_user($db);
$ownerMerchId = gjc_merchant_owner_id($db, (int) $currentUser['id']);
$wallet = gjc_merchant_wallet($db, $ownerMerchId);
$availableBalance = $wallet['balance'];
$merchantName = $currentUser['name'];
$notice = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);

    if (!$amount || $amount <= 0) {
        $error = 'Enter a valid encashment amount.';
    } elseif ($wallet['id'] <= 0) {
        $error = 'Your merchant wallet is not ready. Contact the finance office.';
    } elseif ($amount > $availableBalance) {
        $error = 'Requested amount is higher than your available balance.';
    } else {
        $reference = gjc_reference('ENC');
        $stmt = $db->prepare(
            "INSERT INTO encashment_requests
                (user_id, merchant_wallet_id, amount, method, status, reference_no)
             VALUES (?, ?, ?, 'Cashier Release', 'pending', ?)"
        );
        $stmt->execute([$ownerMerchId, $wallet['id'], $amount, $reference]);
        $notice = "Encashment request {$reference} was submitted for finance review.";
    }
}

$stmt = $db->prepare(
    "SELECT reference_no, amount, status, released_by, created_at
       FROM encashment_requests
      WHERE user_id = ?
      ORDER BY created_at DESC
      LIMIT 8"
);
$stmt->execute([$ownerMerchId]);
$encashHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currentPage = 'encash';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Encashment | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=62">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
</head>

<body class="gp-theme">

    <div class="merchant-layout">

        <?php require __DIR__ . '/../includes/partials/' . (gjc_is_merchant_staff() ? 'sidebar_merchant_staff.php' : 'sidebar_merchant_admin.php'); ?>

        <main class="merchant-main">

            <?php
            $topbarTitle = 'Request Encashment';
            $topbarSubtitle = 'Withdraw available merchant earnings through the Accountancy Office.';
            require __DIR__ . '/../includes/partials/topbar_merchant.php';
            ?>

            <section class="sd-balance mb-4">
                <div>
                    <span class="sd-balance-label">Available to Encash</span>
                    <h2 class="sd-balance-amount"><?php echo gjc_money($availableBalance); ?></h2>
                    <p class="sd-gc-rate"><?php echo gjc_e($merchantName); ?> &middot; Digital earnings wallet</p>
                </div>

                <div class="sd-gc-badge encash-hero-badge">
                    <?php echo $availableBalance > 0 ? 'Ready for Request' : 'No Balance to Encash'; ?>
                </div>
            </section>

            <section class="encash-layout-grid mb-4">

                <div class="merchant-premium-panel encash-form-panel">
                    <div class="merchant-panel-header">
                        <div>
                            <h3>New Encashment Request</h3>
                            <p>Enter the amount you want to withdraw from your merchant balance.</p>
                        </div>
                    </div>

                    <?php if ($notice): ?>
                    <div class="alert alert-success"><?php echo gjc_e($notice); ?></div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo gjc_e($error); ?></div>
                    <?php endif; ?>

                    <form method="POST" class="encash-form" data-busy="Submitting…">

                        <div class="encash-field">
                            <label>Encashment Amount (&#8369;)</label>

                            <div class="encash-money-input">
                                <span>&#8369;</span>
                                <input type="number" name="amount" placeholder="0.00" min="1"
                                    max="<?php echo $availableBalance; ?>" step="0.01" required>
                            </div>

                            <small>Maximum available amount: <?php echo gjc_money($availableBalance); ?></small>
                        </div>

                        <button type="button" class="encash-withdraw-all-btn"
                            onclick="document.querySelector('input[name=amount]').value='<?php echo $availableBalance; ?>'">
                            Withdraw All (<?php echo gjc_money($availableBalance); ?>)
                        </button>

                        <button type="submit" class="encash-submit-btn">
                            Submit Request
                        </button>

                    </form>
                </div>

                <div class="merchant-premium-panel encash-info-panel">
                    <div class="merchant-panel-header">
                        <div>
                            <h3>Request Guidelines</h3>
                            <p>Keep these reminders before submitting an encashment.</p>
                        </div>
                    </div>

                    <div class="encash-guidelines">
                        <div>
                            <strong>1</strong>
                            <span>Submit your request with the correct amount.</span>
                        </div>

                        <div>
                            <strong>2</strong>
                            <span>Bring your ID to the Accountancy Office.</span>
                        </div>

                        <div>
                            <strong>3</strong>
                            <span>Cashier verifies your request and releases cash.</span>
                        </div>
                    </div>

                    <div class="encash-note">
                        After submitting, your request will be reviewed by the cashier or finance staff before
                        disbursement.
                    </div>
                </div>

            </section>

            <section class="merchant-premium-panel">

                <div class="merchant-panel-header d-flex justify-content-between align-items-center">
                    <div>
                        <h3>Encashment History</h3>
                        <p>Track previous and pending merchant withdrawal requests.</p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table merchant-premium-table align-middle js-datatable" id="merchantEncashHistoryTable" data-page-length="8">
                        <thead>
                            <tr>
                                <th data-card="title">Amount</th>
                                <th>Status</th>
                                <th data-card="hide">Processed By</th>
                                <th>Date</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($encashHistory)): ?>
                            <tr>
                                <td colspan="4" class="encash-empty-state">
                                    No encashment history.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($encashHistory as $row): ?>
                            <tr>
                                <td><?php echo gjc_money($row["amount"]); ?></td>
                                <td><span class="merchant-type-pill encash-status <?php echo gjc_e(strtolower($row["status"])); ?>"><?php echo gjc_e(ucfirst($row["status"])); ?></span></td>
                                <td><?php echo $row["released_by"] ? 'Finance Office' : 'Pending'; ?></td>
                                <td><?php echo gjc_e(date('M d, Y h:i A', strtotime($row["created_at"]))); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </section>

        </main>

    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <script src="<?= JS_URL ?>/gp_form_busy.js?v=1"></script>
    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>

</html>
