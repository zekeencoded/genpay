<?php
// ============================================================
//  merchant/rent.php — the tenant's own view of their rent
//  Read-only by design: rent is handed over at the finance
//  office and recorded there, so there is nothing to submit
//  here. This is where a merchant checks when the next charge
//  falls, what is still owed, and the reference number of any
//  payment already logged against them.
//  Owner-only, same rule as encash.php — a stall's rent is the
//  proprietor's obligation, not their staff's business.
// ============================================================
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/MerchantTenantDirectory.php';

gjc_require_role(['merchant']);
if (gjc_is_merchant_staff()) {
    header('Location: ' . MERCHANT_URL . '/dashboard.php');
    exit;
}

$currentUser  = gjc_current_user($db);
$ownerMerchId = gjc_merchant_owner_id($db, (int) $currentUser['id']);
$directory    = new MerchantTenantDirectory($db);

// Loading this page is also a chance to fire the "rent due soon" reminder —
// same lazy trigger the dashboard uses. See dispatchRentReminders().
$directory->dispatchRentReminders($ownerMerchId);

// activeLease() resolves from the owner's own user id, so a merchant can only
// ever reach their own lease — there is no id parameter to tamper with.
$lease    = $directory->activeLease($ownerMerchId);
$account  = $lease['account'] ?? null;
$schedule = $lease['schedule'] ?? [];

$payments = $lease
    ? $directory->pagedRentPayments((int) $lease['id'], '', '', max(1, (int) ($_GET['page'] ?? 1)), 10)
    : ['rows' => [], 'page' => 1, 'total' => 0, 'total_pages' => 1];

$today = date('Y-m-d');

function rent_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Long terms are noise; show what has been charged plus the next few months. */
function rent_visible_rows(array $schedule, bool $showAll): array
{
    if ($showAll) {
        return $schedule;
    }

    $lastDue = -1;
    foreach ($schedule as $i => $row) {
        if ($row['is_due']) {
            $lastDue = $i;
        }
    }

    return array_slice($schedule, 0, min(count($schedule), $lastDue + 4));
}

$showAll  = (int) ($_GET['all'] ?? 0) === 1;
$rows     = rent_visible_rows($schedule, $showAll);
$hiddenNo = count($schedule) - count($rows);

$periodState = [
    'paid'         => ['Paid',        'is-ok'],
    'overpaid'     => ['Overpaid',    'is-ahead'],
    'partial'      => ['Part-paid',   'is-partial'],
    'unpaid'       => ['Unpaid',      'is-late'],
    'upcoming'     => ['Not due yet', 'is-muted'],
    'advance'      => ['Paid ahead',  'is-ahead'],
    'no_charge'    => ['No charge',   'is-muted'],
    'off_contract' => ['Outside term','is-partial'],
];

$stateTone = [
    'overdue' => 'is-late',
    'settled' => 'is-ok',
    'ahead'   => 'is-ahead',
    'pending' => 'is-pending',
    'closed'  => 'is-closed',
];

$currentPage = 'rent';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rent &amp; Lease | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=54">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=30">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=2">

    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
</head>

<body class="gp-theme">

    <div class="merchant-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_merchant_admin.php'; ?>

        <main class="merchant-main">

            <?php
            $topbarTitle = 'Rent &amp; Lease';
            $topbarSubtitle = 'When your next rent falls due, and every payment logged against your stall.';
            require __DIR__ . '/../includes/partials/topbar_merchant.php';
            ?>

            <?php if (!$lease): ?>

                <section class="merchant-premium-panel">
                    <div class="gp-rent-empty">
                        <i class="fa-solid fa-file-signature"></i>
                        <strong>No lease on file yet.</strong>
                        <p>
                            Once the finance office records your stall lease, your rent schedule and
                            payment history will appear here. If you are already trading, ask them to
                            set up your contract.
                        </p>
                    </div>
                </section>

            <?php else: ?>

                <!-- ── Where you stand ──────────────────────────────────── -->
                <section class="gp-rent-hero <?= rent_e($stateTone[$account['state']] ?? '') ?> mb-4">
                    <div>
                        <?php
                            // Label the headline number for what it actually is — a big
                            // figure under a vague "rent standing" reads as money owed
                            // even when the tenant is paid up.
                            if ($account['outstanding'] > 0.005) {
                                $heroLabel  = 'Unpaid rent';
                                $heroAmount = gjc_money($account['outstanding']);
                            } elseif ($account['upcoming_due']) {
                                $heroLabel  = 'Next payment';
                                $heroAmount = gjc_money($account['upcoming_amount']);
                            } else {
                                $heroLabel  = 'Rent standing';
                                $heroAmount = 'Settled';
                            }
                        ?>
                        <span class="gp-rent-hero-label"><?= rent_e($heroLabel) ?></span>
                        <h2 class="gp-rent-hero-amount"><?= $heroAmount ?></h2>
                        <p class="gp-rent-hero-note"><?= rent_e($account['summary']) ?></p>
                    </div>
                    <span class="gp-rent-hero-badge"><?= rent_e($account['state_label']) ?></span>
                </section>

                <!-- ── The numbers ──────────────────────────────────────── -->
                <section class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="merchant-metric-card h-100">
                            <span>Next charge</span>
                            <h2 style="font-size:1.15rem">
                                <?= $account['upcoming_due'] ? rent_e(date('M j, Y', strtotime($account['upcoming_due']))) : '&mdash;' ?>
                            </h2>
                            <p>
                                <?php if ($account['upcoming_due'] === null): ?>
                                    Nothing left to bill
                                <?php elseif ($account['upcoming_days'] === 0): ?>
                                    <?= gjc_money($account['upcoming_amount']) ?> &middot; today
                                <?php elseif ($account['upcoming_days'] === 1): ?>
                                    <?= gjc_money($account['upcoming_amount']) ?> &middot; tomorrow
                                <?php else: ?>
                                    <?= gjc_money($account['upcoming_amount']) ?> &middot; in <?= (int) $account['upcoming_days'] ?> days
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="merchant-metric-card h-100">
                            <span>Unpaid now</span>
                            <h2 style="font-size:1.15rem"><?= gjc_money($account['outstanding']) ?></h2>
                            <p>
                                <?php if ($account['months_behind'] > 0): ?>
                                    Across <?= (int) $account['months_behind'] ?> month<?= $account['months_behind'] === 1 ? '' : 's' ?>
                                <?php else: ?>
                                    Nothing overdue
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="merchant-metric-card h-100">
                            <span>Paid to date</span>
                            <h2 style="font-size:1.15rem"><?= gjc_money($account['collected']) ?></h2>
                            <p><?= (int) $account['months_billed'] ?> of <?= (int) $account['term_months'] ?> months charged</p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="merchant-metric-card h-100">
                            <span>Monthly rent</span>
                            <h2 style="font-size:1.15rem"><?= gjc_money($lease['monthly_rent']) ?></h2>
                            <p>Every <?= rent_e(date('jS', strtotime($lease['lease_start']))) ?> of the month</p>
                        </div>
                    </div>
                </section>

                <!-- ── Month by month ───────────────────────────────────── -->
                <section class="merchant-premium-panel mb-4">
                    <div class="merchant-panel-header">
                        <div>
                            <h3><i class="fa-solid fa-calendar-days" style="margin-right:6px"></i>Month by month</h3>
                            <p>
                                One rent charge per month for the whole of your term
                                (<?= rent_e(date('M j, Y', strtotime($lease['lease_start']))) ?>
                                &ndash; <?= rent_e(date('M j, Y', strtotime($lease['lease_end']))) ?>).
                            </p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table merchant-premium-table align-middle gp-rent-schedule">
                            <thead>
                                <tr>
                                    <th data-card="title">Month</th>
                                    <th>Due on</th>
                                    <th class="text-end" data-card="hide">Rent</th>
                                    <th class="text-end" data-card="hide">Paid</th>
                                    <th class="text-end" data-card="amount">Balance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                        [$label, $tone] = $periodState[$row['state']] ?? [$row['state'], ''];
                                        $balance = $row['charged'] - $row['paid'];
                                        $isLate  = $row['is_due'] && $balance > 0.005;
                                    ?>
                                    <tr class="<?= $isLate ? 'is-late-row' : '' ?>">
                                        <td><strong><?= rent_e(date('F Y', strtotime($row['period'] . '-01'))) ?></strong></td>
                                        <td>
                                            <?= $row['state'] === 'off_contract'
                                                ? '<span class="text-muted">not in term</span>'
                                                : rent_e(date('M j, Y', strtotime($row['due_date']))) ?>
                                        </td>
                                        <td class="text-end">
                                            <?= $row['charged'] > 0 ? gjc_money($row['charged']) : '<span class="text-muted">&mdash;</span>' ?>
                                        </td>
                                        <td class="text-end">
                                            <?= $row['paid'] > 0 ? gjc_money($row['paid']) : '<span class="text-muted">&mdash;</span>' ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($balance > 0.005): ?>
                                                <span class="<?= $row['is_due'] ? 'gp-rent-owed' : 'text-muted' ?>"><?= gjc_money($balance) ?></span>
                                            <?php elseif ($balance < -0.005): ?>
                                                <span class="gp-rent-credit"><?= gjc_money(-$balance) ?> over</span>
                                            <?php else: ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="gp-rent-chip <?= rent_e($tone) ?>"><?= rent_e($label) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($hiddenNo > 0 || $showAll): ?>
                        <div class="text-center mt-2">
                            <?php if ($showAll): ?>
                                <a class="merchant-view-btn" href="<?= MERCHANT_URL ?>/rent.php">Show fewer months</a>
                            <?php else: ?>
                                <a class="merchant-view-btn" href="<?= MERCHANT_URL ?>/rent.php?all=1">
                                    Show all <?= count($schedule) ?> months (<?= $hiddenNo ?> more)
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- ── Receipts ─────────────────────────────────────────── -->
                <section class="merchant-premium-panel">
                    <div class="merchant-panel-header">
                        <div>
                            <h3><i class="fa-solid fa-receipt" style="margin-right:6px"></i>Payments recorded</h3>
                            <p>Every rent payment the finance office has logged against your stall.</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table merchant-premium-table align-middle">
                            <thead>
                                <tr>
                                    <th data-card="title">Reference</th>
                                    <th>Covers</th>
                                    <th>Received</th>
                                    <th class="text-end" data-card="amount">Amount</th>
                                    <th data-card="hide">Method</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$payments['rows']): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            No rent payments recorded yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($payments['rows'] as $p): ?>
                                    <tr>
                                        <td><code><?= rent_e($p['reference_no']) ?></code></td>
                                        <td><?= rent_e(date('F Y', strtotime($p['period_covered'] . '-01'))) ?></td>
                                        <td><?= rent_e(date('M j, Y', strtotime($p['payment_date']))) ?></td>
                                        <td class="text-end"><?= gjc_money($p['amount_paid']) ?></td>
                                        <td><?= rent_e(ucfirst(str_replace('_', ' ', $p['payment_method'] ?? 'cash'))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ((int) $payments['total_pages'] > 1): ?>
                        <nav class="d-flex justify-content-between align-items-center mt-3">
                            <span class="text-muted small">
                                <?= (int) $payments['total'] ?> receipt<?= (int) $payments['total'] === 1 ? '' : 's' ?>,
                                page <?= (int) $payments['page'] ?> of <?= (int) $payments['total_pages'] ?>
                            </span>
                            <span class="d-flex gap-2">
                                <?php if ((int) $payments['page'] > 1): ?>
                                    <a class="merchant-view-btn" href="?page=<?= (int) $payments['page'] - 1 ?><?= $showAll ? '&all=1' : '' ?>">Previous</a>
                                <?php endif; ?>
                                <?php if ((int) $payments['page'] < (int) $payments['total_pages']): ?>
                                    <a class="merchant-view-btn" href="?page=<?= (int) $payments['page'] + 1 ?><?= $showAll ? '&all=1' : '' ?>">Next</a>
                                <?php endif; ?>
                            </span>
                        </nav>
                    <?php endif; ?>

                    <div class="merchant-note mt-3">
                        Rent is settled at the finance office &mdash; there is nothing to pay here.
                        Once they record your payment it shows up on this page and in your notifications,
                        with a reference number you can quote back. If something looks wrong, bring the
                        reference to the finance office.
                    </div>
                </section>

            <?php endif; ?>

        </main>
    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>

</html>
