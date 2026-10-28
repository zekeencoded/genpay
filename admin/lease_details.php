<?php
// ============================================================
//  admin/lease_details.php — one lease, in full
//  The rent roll's "Details" / "Record payment" actions used to
//  open a modal; this is that ledger as a real page, so it can be
//  linked, bookmarked, paged and printed. Everything is rendered
//  server-side from MerchantTenantDirectory (same source the roll
//  uses); the three write actions still go through
//  admin/api/leases.php over fetch, then reload the page.
// ============================================================
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/MerchantTenantDirectory.php';

gjc_require_role(['finance']);
$currentUser = gjc_current_user($db);
$currentPage = 'leases';
$directory   = new MerchantTenantDirectory($db);

// Same signed-token addressing as the other single-record pages: the row id
// never appears in the URL and a hand-edited link fails verification.
$leaseId = gjc_verify_view_token($_GET['token'] ?? null, 'lease');
$lease   = $leaseId !== null ? $directory->leaseById($leaseId) : null;

if (!$lease) {
    http_response_code(404);
}

function lease_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// "Back to rent roll" returns to the filter the row was opened from. Only the
// three known keys are carried over, rebuilt here rather than echoed back.
$retRaw = (string) ($_GET['ret'] ?? '');
parse_str($retRaw, $retParams);
$backQs  = http_build_query(array_intersect_key(
    is_array($retParams) ? $retParams : [],
    array_flip(['q', 'view', 'page'])
));
$backUrl = ADMIN_URL . '/leases.php' . ($backQs !== '' ? '?' . $backQs : '');

if ($lease) {
    $account  = $lease['account'];
    $schedule = $lease['schedule'];

    $merchantStmt = $db->prepare("SELECT first_name, last_name, email FROM users WHERE userID = ? LIMIT 1");
    $merchantStmt->execute([$lease['merchant_user_id']]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $tenant   = trim((string) (($merchant['first_name'] ?? '') . ' ' . ($merchant['last_name'] ?? '')))
        ?: (string) ($merchant['email'] ?? 'Unassigned');

    $receiptPage = max(1, (int) ($_GET['page'] ?? 1));
    $payments    = $directory->pagedRentPayments((int) $lease['id'], '', '', $receiptPage, 10);

    // Long terms collapse to what is actionable — everything billed so far plus
    // the next few months — exactly as merchant/rent.php does it.
    $showAllMonths = (int) ($_GET['all'] ?? 0) === 1;
    $lastDue = -1;
    foreach ($schedule as $i => $row) {
        if ($row['is_due']) {
            $lastDue = $i;
        }
    }
    $visibleMonths = $showAllMonths
        ? $schedule
        : array_slice($schedule, 0, min(count($schedule), $lastDue + 4));
    $hiddenMonths = count($schedule) - count($visibleMonths);

    // Only months this contract actually bills can be paid against.
    $billable = array_values(array_filter(
        $schedule,
        static fn (array $r): bool => $r['state'] !== 'off_contract'
    ));

    // Default the picker to the month we actually want money for.
    $targetPeriod = null;
    foreach ($billable as $row) {
        if ($row['is_due'] && $row['charged'] - $row['paid'] > 0.005) {
            $targetPeriod = $row['period'];
            break;
        }
    }
    if ($targetPeriod === null) {
        foreach ($billable as $row) {
            if (!$row['is_due'] && $row['charged'] - $row['paid'] > 0.005) {
                $targetPeriod = $row['period'];
                break;
            }
        }
    }
    if ($targetPeriod === null && $billable) {
        $targetPeriod = $billable[count($billable) - 1]['period'];
    }

    $stateClass = [
        'overdue' => 'is-late',
        'settled' => 'is-ok',
        'ahead'   => 'is-ahead',
        'pending' => 'is-pending',
        'closed'  => 'is-closed',
    ][$account['state']] ?? '';

    $periodState = [
        'paid'         => ['Paid',         'is-ok'],
        'overpaid'     => ['Overpaid',     'is-ahead'],
        'partial'      => ['Part-paid',    'is-partial'],
        'unpaid'       => ['Unpaid',       'is-late'],
        'upcoming'     => ['Not due yet',  'is-muted'],
        'advance'      => ['Paid ahead',   'is-ahead'],
        'no_charge'    => ['No charge',    'is-muted'],
        'off_contract' => ['Outside term', 'is-partial'],
    ];

    // ── Which section is on screen ───────────────────────────────────────
    // Addressed in the URL rather than switched in the browser, so paging the
    // receipts keeps you on Receipts, and the rent roll can link straight to
    // the section its button means.
    $tabs = [
        'collect'  => ['Record payment', 'fa-solid fa-hand-holding-dollar'],
        'receipts' => ['Receipts',       'fa-solid fa-receipt'],
        'contract' => ['Contract',       'fa-solid fa-file-signature'],
    ];
    $tab = (string) ($_GET['tab'] ?? 'collect');
    if (!isset($tabs[$tab])) {
        $tab = 'collect';
    }

    // Every link back to this same page keeps the token, the tab and the
    // return trail.
    $selfBase = ADMIN_URL . '/lease_details.php?token=' . rawurlencode((string) ($_GET['token'] ?? ''))
        . ($retRaw !== '' ? '&ret=' . rawurlencode($retRaw) : '');
    $selfUrl = static function (array $overrides = []) use ($selfBase, $tab, $receiptPage, $showAllMonths): string {
        $params = array_filter([
            'tab'  => $overrides['tab']  ?? $tab,
            'page' => $overrides['page'] ?? ($receiptPage > 1 ? $receiptPage : ''),
            'all'  => array_key_exists('all', $overrides) ? $overrides['all'] : ($showAllMonths ? 1 : ''),
        ], static fn ($v) => $v !== '' && $v !== null);

        return htmlspecialchars($selfBase . ($params ? '&' . http_build_query($params) : ''), ENT_QUOTES);
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $lease ? lease_e($lease['stall_name']) . ' — Lease' : 'Content Not Available' ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="gp-theme">
<div class="admin-layout">

    <!-- ── Sidebar ──────────────────────────────────────────────────────── -->
    <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

    <!-- ── Main ─────────────────────────────────────────────────────────── -->
    <main class="admin-main">

        <header class="topbar">
            <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
            <div>
                <h1><?= $lease ? lease_e($lease['stall_name']) : 'Lease' ?></h1>
                <p>
                    <?php if ($lease): ?>
                        Stall <?= lease_e($lease['stall_number']) ?> &middot; <?= lease_e($tenant) ?>
                        &middot; <?= lease_e(date('M j, Y', strtotime($lease['lease_start']))) ?>
                        to <?= lease_e(date('M j, Y', strtotime($lease['lease_end']))) ?>
                    <?php else: ?>
                        Lease record
                    <?php endif; ?>
                </p>
            </div>
            <div class="admin-user">
                <span><?= lease_e($currentUser['name']) ?></span>
                <?php require __DIR__ . '/../includes/partials/avatar_admin.php'; ?>
            </div>
        </header>

        <div class="gp-detail-back mb-3">
            <a href="<?= lease_e($backUrl) ?>"><i class="fa-solid fa-arrow-left"></i> Back to rent roll</a>
        </div>

        <?php if (!$lease): ?>

            <section class="premium-panel">
                <div class="gp-empty">
                    <i class="fa-regular fa-circle-question"></i>
                    <strong>This lease isn't available.</strong>
                    <p>The link you followed may be broken, or the contract may have been removed.</p>
                    <a href="<?= ADMIN_URL ?>/leases.php" class="view-btn mt-3 d-inline-block">Go to Leases &amp; Rent</a>
                </div>
            </section>

        <?php else: ?>

            <div id="leaseAlert"></div>

            <!-- ── Where this lease stands ──────────────────────────────── -->
            <section class="gp-ledger-summary <?= lease_e($stateClass) ?>">
                <span class="gp-rent-badge <?= lease_e($stateClass) ?>"><?= lease_e($account['state_label']) ?></span>
                <p><?= lease_e($account['summary']) ?></p>
            </section>

            <section class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="detail-stat h-100">
                        <span>Unpaid rent</span>
                        <?php if ($account['outstanding'] > 0.005): ?>
                            <strong class="gp-amount-due"><?= gjc_money($account['outstanding']) ?></strong>
                        <?php elseif ($account['advance'] > 0.005): ?>
                            <strong class="gp-amount-credit"><?= gjc_money($account['advance']) ?> ahead</strong>
                        <?php else: ?>
                            <strong>Nothing owed</strong>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="detail-stat h-100">
                        <span>Charged so far</span>
                        <strong><?= gjc_money($account['billed_to_date']) ?></strong>
                        <small><?= (int) $account['months_billed'] ?> of <?= (int) $account['term_months'] ?> months</small>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="detail-stat h-100">
                        <span>Collected</span>
                        <strong><?= gjc_money($account['collected']) ?></strong>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="detail-stat h-100">
                        <span>Next charge due</span>
                        <strong><?= $account['next_due_date']
                            ? lease_e(date('M j, Y', strtotime($account['next_due_date'])))
                            : 'Nothing left to bill' ?></strong>
                    </div>
                </div>
            </section>

            <!-- ── Section switcher ─────────────────────────────────────── -->
            <nav class="gp-tabs mb-4" aria-label="Lease sections">
                <?php foreach ($tabs as $key => [$label, $icon]): ?>
                    <a class="gp-tab <?= $tab === $key ? 'is-active' : '' ?>"
                       href="<?= $selfUrl(['tab' => $key]) ?>"
                       <?= $tab === $key ? 'aria-current="page"' : '' ?>>
                        <i class="<?= lease_e($icon) ?>"></i>
                        <span><?= lease_e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'collect'): ?>
            <!-- ── Record a payment, against a specific month ───────────── -->
            <section class="premium-panel mb-4" id="record">
                <div class="panel-header">
                    <div>
                        <h3>Record payment</h3>
                        <p>
                            Pick the month the tenant is paying for, then the amount. Clicking a month
                            in the schedule below fills this in for you.
                        </p>
                    </div>
                </div>

                <form class="row g-3 gp-collect-form" id="leasePaymentForm">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="lease_id" value="<?= (int) $lease['id'] ?>">
                    <div class="col-md-3">
                        <label class="form-label">Period covered</label>
                        <select class="form-select" name="period_covered" id="leasePayPeriod" required>
                            <?php if (!$billable): ?>
                                <option value="">No billable months</option>
                            <?php endif; ?>
                            <?php foreach ($billable as $row): ?>
                                <?php
                                    $owed = max(0.0, round($row['charged'] - $row['paid'], 2));
                                    $tail = $row['is_due']
                                        ? ($owed > 0 ? '— ' . gjc_money_plain($owed) . ' unpaid' : '— settled')
                                        : '— not due yet';
                                ?>
                                <option value="<?= lease_e($row['period']) ?>" data-owed="<?= $owed ?>"
                                        <?= $row['period'] === $targetPeriod ? 'selected' : '' ?>>
                                    <?= lease_e(date('F Y', strtotime($row['period'] . '-01'))) ?> <?= lease_e($tail) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text" id="leasePeriodHint">
                            <?= $billable ? '' : 'Set a rent amount and valid dates on the contract below first.' ?>
                        </small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Amount paid (&#8369;)</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="amount_paid" id="leasePayAmount" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date received</label>
                        <input type="date" class="form-control" name="payment_date" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Method</label>
                        <select class="form-select" name="payment_method">
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank transfer</option>
                            <option value="check">Check</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Notes (optional)</label>
                        <input type="text" class="form-control" name="notes" placeholder="Receipt no., remarks…">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <?php $blocked = $lease['status'] === 'pending'; ?>
                        <button class="btn btn-success w-100" type="submit" id="leasePaySubmit" <?= $blocked ? 'disabled' : '' ?>>
                            <?= $blocked ? 'Activate the contract first' : 'Record payment' ?>
                        </button>
                    </div>
                </form>

                <h6 class="gp-subhead">Month by month</h6>
                <p class="gp-pane-hint">
                    One row per rent charge for the whole contract term. Click any row to pay it off.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle gp-schedule-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Charged on</th>
                                <th class="text-end">Rent</th>
                                <th class="text-end">Recorded</th>
                                <th class="text-end">Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$visibleMonths): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-3">
                                    No rent schedule yet — check the contract dates below.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($visibleMonths as $row): ?>
                            <?php
                                [$label, $tone] = $periodState[$row['state']] ?? [$row['state'], ''];
                                $balance = $row['charged'] - $row['paid'];
                                $isLate  = $row['is_due'] && $balance > 0.005;
                            ?>
                            <tr class="js-schedule-row <?= $isLate ? 'is-late-row' : '' ?>"
                                data-period="<?= lease_e($row['period']) ?>" tabindex="0">
                                <td><strong><?= lease_e(date('F Y', strtotime($row['period'] . '-01'))) ?></strong></td>
                                <td>
                                    <?= $row['state'] === 'off_contract'
                                        ? '<span class="text-muted">not in term</span>'
                                        : lease_e(date('M j, Y', strtotime($row['due_date']))) ?>
                                </td>
                                <td class="text-end">
                                    <?= $row['charged'] > 0 ? gjc_money($row['charged']) : '<span class="text-muted">&mdash;</span>' ?>
                                </td>
                                <td class="text-end">
                                    <?= $row['paid'] > 0 ? gjc_money($row['paid']) : '<span class="text-muted">&mdash;</span>' ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($row['state'] === 'off_contract'): ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php elseif ($balance > 0.005): ?>
                                        <span class="<?= $row['is_due'] ? 'gp-amount-due' : 'text-muted' ?>"><?= gjc_money($balance) ?></span>
                                    <?php elseif ($balance < -0.005): ?>
                                        <span class="gp-amount-credit"><?= gjc_money(-$balance) ?> over</span>
                                    <?php else: ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="gp-rent-badge <?= lease_e($tone) ?>"><?= lease_e($label) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($hiddenMonths > 0 || $showAllMonths): ?>
                    <div class="text-center mt-2">
                        <?php if ($showAllMonths): ?>
                            <a class="btn btn-sm btn-link" href="<?= $selfUrl(['all' => '']) ?>">Show fewer months</a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-link" href="<?= $selfUrl(['all' => 1]) ?>">
                                Show all <?= count($schedule) ?> months (<?= $hiddenMonths ?> more)
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($tab === 'receipts'): ?>
            <!-- ── Receipts ─────────────────────────────────────────────── -->
            <section class="premium-panel mb-4" id="receipts">
                <div class="panel-header">
                    <div>
                        <h3>Receipts</h3>
                        <p>
                            Every payment logged against this lease. Remove one if it was keyed by
                            mistake — the schedule and due date recalculate straight away.
                        </p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Covers</th>
                                <th>Received</th>
                                <th class="text-end">Amount</th>
                                <th>Method</th>
                                <th>Notes</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$payments['rows']): ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No rent payments recorded yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($payments['rows'] as $p): ?>
                            <tr>
                                <td><code><?= lease_e($p['reference_no']) ?></code></td>
                                <td><?= lease_e(date('F Y', strtotime($p['period_covered'] . '-01'))) ?></td>
                                <td><?= lease_e(date('M j, Y', strtotime($p['payment_date']))) ?></td>
                                <td class="text-end"><?= gjc_money($p['amount_paid']) ?></td>
                                <td><?= lease_e(ucfirst(str_replace('_', ' ', $p['payment_method'] ?? 'cash'))) ?></td>
                                <td><?= lease_e($p['notes'] ?? '') ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger js-void-payment"
                                            data-payment-id="<?= (int) $p['id'] ?>"
                                            data-ref="<?= lease_e($p['reference_no']) ?>"
                                            data-amount="<?= lease_e(gjc_money_plain((float) $p['amount_paid'])) ?>">Remove</button>
                                </td>
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
                    <span class="btn-group btn-group-sm">
                        <?php if ((int) $payments['page'] > 1): ?>
                            <a class="btn btn-outline-secondary" href="<?= $selfUrl(['page' => (int) $payments['page'] - 1]) ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ((int) $payments['page'] < (int) $payments['total_pages']): ?>
                            <a class="btn btn-outline-secondary" href="<?= $selfUrl(['page' => (int) $payments['page'] + 1]) ?>">Next</a>
                        <?php endif; ?>
                    </span>
                </nav>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($tab === 'contract'): ?>
            <!-- ── Contract terms ───────────────────────────────────────── -->
            <section class="premium-panel" id="contract">
                <div class="panel-header">
                    <div>
                        <h3>Contract</h3>
                        <p>
                            Changing the rent or the dates rebuilds the month-by-month schedule.
                            Payments already recorded stay attached to their months.
                        </p>
                    </div>
                </div>

                <form class="row g-3" id="leaseEditForm">
                    <input type="hidden" name="action" value="update_lease">
                    <input type="hidden" name="lease_id" value="<?= (int) $lease['id'] ?>">
                    <div class="col-md-4">
                        <label class="form-label">Stall</label>
                        <select class="form-select" name="stall_id">
                            <?php if ($lease['stall_id'] === ''): ?>
                                <option value="" selected>Stall <?= lease_e($lease['stall_number']) ?> (not in the registry) &mdash; pick one&hellip;</option>
                            <?php endif; ?>
                            <?= gjc_stall_registry_options_html($db, $lease['stall_id']) ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Stall name</label>
                        <input type="text" class="form-control" name="stall_name" value="<?= lease_e($lease['stall_name']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Monthly rent (&#8369;)</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="monthly_rent" value="<?= lease_e($lease['monthly_rent']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Deposit (&#8369;)</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="deposit_amount" value="<?= lease_e($lease['deposit_amount']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Lease start</label>
                        <input type="date" class="form-control" name="lease_start" value="<?= lease_e($lease['lease_start']) ?>">
                        <small class="form-text">Also the monthly charge day.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Lease end</label>
                        <input type="date" class="form-control" name="lease_end" value="<?= lease_e($lease['lease_end']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contract status</label>
                        <select class="form-select" name="status">
                            <?php foreach ([
                                'active'     => 'Active — rent is being billed',
                                'pending'    => 'Pending — not billing yet',
                                'expired'    => 'Expired — term finished',
                                'terminated' => 'Terminated — ended early',
                            ] as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $lease['status'] === $key ? 'selected' : '' ?>><?= lease_e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Next charge due</label>
                        <div class="gp-readonly-field">
                            <?= $account['next_due_date']
                                ? lease_e(date('M j, Y', strtotime($account['next_due_date'])))
                                : 'Nothing left to bill' ?>
                        </div>
                        <small class="form-text">
                            Worked out from the schedule — the oldest month still unpaid, or the
                            next one coming up. It is not editable, so it can never disagree
                            with the receipts.
                        </small>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Contract notes</label>
                        <textarea class="form-control" rows="2" name="contract_notes"><?= lease_e($lease['contract_notes']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit" id="leaseEditSubmit">Save contract</button>
                    </div>
                </form>
            </section>
            <?php endif; ?>

        <?php endif; ?>

    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
window.leaseDetailConfig = {
    endpoint: '<?= ADMIN_URL ?>/api/leases.php',
    leaseId: <?= $lease ? (int) $lease['id'] : 0 ?>,
};
</script>
<?php require __DIR__ . '/../includes/partials/csrf_bootstrap.php'; ?>
<script src="<?= JS_URL ?>/admin_lease_details.js?v=1"></script>
</body>
</html>
