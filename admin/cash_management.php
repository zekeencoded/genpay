<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';
require_once __DIR__ . '/../connection/cash_history.php';

gjc_require_role(['finance']);
gjc_ensure_operational_tables($db);
gjc_ensure_parent_schema($db);
gjc_ensure_parent_wallet_schema($db);

$currentUser = gjc_current_user($db);

$pendingEncashments = (int) $db->query("SELECT COUNT(*) FROM encashment_requests WHERE status = 'pending'")->fetchColumn();
$releasedToday = (float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM encashment_requests WHERE status = 'released' AND DATE(released_at) = CURDATE()")->fetchColumn();
$loadedToday = gjc_cash_in_loaded_today($db);

// Vault balance (sending GenCoin draws from it). Read-only.
$vaultBalance = null;
try {
    $vaultBalance = (float) ((new CirculationEngine($db))->getCirculationSnapshot()['vault'] ?? 0);
} catch (Throwable $e) {
    // A snapshot failure must not take the queue down with it.
}

gjc_backfill_student_ids($db);

$topupHistory = gjc_recent_cash_in_history($db);

$pendingRequests = $db->query(
    "SELECT e.*, mw.user_id AS user_id, m.merchantID, m.stall_name
       FROM encashment_requests e
       LEFT JOIN merchant_wallets mw ON mw.id = e.merchant_wallet_id
       LEFT JOIN merchant m ON m.userID = mw.user_id
      WHERE e.status = 'pending'
      ORDER BY e.created_at ASC
      LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC);

$encashmentHistory = $db->query(
    "SELECT e.*, mw.user_id AS user_id, m.stall_name
       FROM encashment_requests e
       LEFT JOIN merchant_wallets mw ON mw.id = e.merchant_wallet_id
       LEFT JOIN merchant m ON m.userID = mw.user_id
      ORDER BY e.created_at DESC
      LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC);

$currentPage = 'cash';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>Cash Management | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=45">
    <link rel="stylesheet" href="<?= CSS_URL ?>/topups.css?v=10">
    <link rel="stylesheet" href="<?= CSS_URL ?>/encashments.css?v=10">
    <link rel="stylesheet" href="<?= CSS_URL ?>/cash_management.css?v=1">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=3">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=24">
    <style>
    </style>
</head>

<body class="gp-theme">

    <div class="admin-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

        <main class="admin-main">

            <header class="topbar">
                <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>

                <div>
                    <h1>Cash Management</h1>
                    <p>Money in and out of the system: send GenCoin to students and guardians, and release merchant cash-outs.</p>
                </div>

                <div class="admin-user">
                    <span><?= htmlspecialchars($currentUser['name'] ?? 'Finance', ENT_QUOTES, 'UTF-8') ?></span>
                    <?php require __DIR__ . '/../includes/partials/avatar_admin.php'; ?>
                </div>
            </header>


            <section class="cash-stats-grid mb-4">

                <div class="topup-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-circle-arrow-down"></i>
                    </div>
                    <span>Loaded Today</span>
                    <h2><?php echo gjc_money($loadedToday); ?></h2>
                    <p>Total wallet load volume</p>
                </div>

                <div class="topup-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                    <span>Released Today</span>
                    <h2><?php echo gjc_money($releasedToday); ?></h2>
                    <p>Total released to merchants</p>
                </div>

                <div class="topup-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                    <span>Pending Encashments</span>
                    <h2><?php echo $pendingEncashments; ?></h2>
                    <p>Awaiting disbursement</p>
                </div>

                <div class="topup-stat-card">
                    <div class="stat-icon-wrap">
                        <i class="fa-solid fa-vault"></i>
                    </div>
                    <span>Cashier Vault</span>
                    <h2><?php echo $vaultBalance === null ? '&mdash;' : gjc_money($vaultBalance); ?></h2>
                    <p>Top-ups draw from it; encashments return to it</p>
                </div>

            </section>

            <nav class="gp-tabs mb-4" role="tablist" aria-label="Money direction">
                <a class="gp-tab is-active" href="#cash-in" role="tab" id="tab-cash-in" aria-controls="cash-in" aria-selected="true" data-cash-tab="cash-in">
                    <i class="fa-solid fa-circle-arrow-down"></i><span>Cash In</span>
                </a>
                <a class="gp-tab" href="#cash-out" role="tab" id="tab-cash-out" aria-controls="cash-out" aria-selected="false" data-cash-tab="cash-out">
                    <i class="fa-solid fa-circle-arrow-up"></i><span>Cash Out</span>
                    <?php if ($pendingEncashments > 0): ?>
                        <span class="gp-tab-count" title="<?= (int) $pendingEncashments ?> waiting"><?= (int) $pendingEncashments ?></span>
                    <?php endif; ?>
                </a>
            </nav>

            <div class="cash-pane" id="cash-in" role="tabpanel" aria-labelledby="tab-cash-in">

            <section class="gp-howto is-footnote">
                <button type="button" class="gp-howto-toggle" data-howto="topups" aria-expanded="true">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>How a top-up works here</span>
                    <i class="fa-solid fa-chevron-up gp-howto-caret"></i>
                </button>
                <div class="gp-howto-body">
                    <ol class="gp-howto-steps">
                        <li>
                            <strong>You credit the wallet directly.</strong>
                            Use <em>Send GenCoin</em> to load a student or guardian who is standing at
                            the counter. Nothing moves until you confirm.
                        </li>
                        <li>
                            <strong>Sending moves points out of the Cashier Vault.</strong>
                            That is the balance in the Cashier Vault card above. If the vault cannot cover the
                            amount the send is refused and nothing is charged &mdash; top the vault
                            up on <a href="<?= ADMIN_URL ?>/economy.php">Economy</a> first.
                        </li>
                        <li>
                            <strong>The fee rides on top of the amount, never inside it.</strong>
                            The student is credited exactly what they asked for; you collect that
                            plus the service fee in cash. The confirmation shows all three figures
                            before you commit.
                        </li>
                    </ol>
                </div>
            </section>

            <section class="topup-panel">

                <div class="topup-panel-header">
                    <div>
                        <h3>Recent Top-up History</h3>
                        <p>Latest GenCoin top-ups to student and guardian wallets.</p>
                    </div>

                    <button type="button" class="create-topup-btn" onclick="openSendGenCoin()">
                        <i class="fa-solid fa-paper-plane"></i> Send GenCoin
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table topup-table align-middle js-datatable" id="topupHistoryTable" data-page-length="10"
                           data-empty-message="No top-ups have been processed yet.">
                        <thead>
                            <tr>
                                <th data-card="title">Reference</th>
                                <th>Recipient</th>
                                <th data-card="amount">Credited</th>
                                <th data-card="hide">Source</th>
                                <th>Status</th>
                                <th data-card="hide">Sent at</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($topupHistory as $history): ?>
                            <tr>
                                <td><?php echo gjc_e($history["reference_no"]); ?></td>
                                <td><?php echo gjc_e(gjc_user_label($db, (int) $history['recipient_user_id'])); ?><br><small class="text-muted"><?php echo gjc_e($history['recipient_role']); ?></small></td>
                                <td class="amount-text"><?php echo gjc_money($history["amount"]); ?></td>
                                <td><span class="method-pill"><?php echo gjc_e($history['top_up_source'] ? ucfirst($history['top_up_source']) : 'Cash top-up'); ?></span></td>
                                <td>
                                    <span class="topup-status <?php echo strtolower($history["status"]); ?>">
                                        <?php echo gjc_e(ucfirst($history["status"])); ?>
                                    </span>
                                </td>
                                <td data-order="<?php echo gjc_e($history['created_at']); ?>"><?php echo gjc_e(gjc_datetime($history["created_at"])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>
                </div>

            </section>

            </div>

            <div class="cash-pane" id="cash-out" role="tabpanel" aria-labelledby="tab-cash-out" hidden>

            <section class="encash-panel mb-4">

                <div class="encash-panel-header">
                    <div>
                        <h3>Pending Encashment Requests</h3>
                        <p>Merchants cashing out their sales. Releasing pays them and returns the points to the vault.</p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table encash-table align-middle js-datatable" id="pendingEncashmentsTable" data-page-length="10"
                           data-empty-message="No merchants are waiting to cash out. Requests appear here as merchants submit them.">
                        <thead>
                            <tr>
                                <th data-card="title">Reference</th>
                                <th>Merchant</th>
                                <th data-card="hide">Stall</th>
                                <th data-card="amount">Amount</th>
                                <th>Method</th>
                                <th data-card="hide">Requested</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($pendingRequests as $request): ?>
                            <tr>
                                <?php $merchantName = gjc_user_label($db, (int) $request['user_id']); ?>
                                <td><?php echo gjc_e($request["reference_no"]); ?></td>
                                <td>
                                    <div class="encash-user-cell">
                                        <div class="encash-avatar">
                                            <?php echo gjc_e(strtoupper(substr($merchantName, 0, 1))); ?>
                                        </div>
                                        <strong><?php echo gjc_e($merchantName); ?></strong>
                                    </div>
                                </td>
                                <td><?php echo gjc_e($request['stall_name'] ?: 'No stall on file')
                                        . ($request['merchantID'] ? ' &middot; Stall #' . (int) $request['merchantID'] : ''); ?></td>
                                <td class="amount-text"><?php echo gjc_money($request["amount"]); ?></td>
                                <td><span class="method-pill"><?php echo gjc_e($request["method"]); ?></span></td>
                                <td><?php echo gjc_e(gjc_datetime($request["created_at"])); ?></td>
                                <td>
                                    <div class="encash-actions">
                                        <button type="button" class="release-btn"
                                            onclick="releaseEncashment(this, <?php echo (int) $request['id']; ?>, <?php echo (int) $request['merchant_wallet_id']; ?>, <?php echo (float) $request['amount']; ?>, <?php echo htmlspecialchars(json_encode($merchantName), ENT_QUOTES, 'UTF-8'); ?>)">Release</button>
                                        <button type="button" class="reject-btn"
                                            onclick="rejectEncashment(this, <?php echo (int) $request['id']; ?>, <?php echo (float) $request['amount']; ?>, <?php echo htmlspecialchars(json_encode($merchantName), ENT_QUOTES, 'UTF-8'); ?>)">Reject</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>
                </div>

            </section>

            <section class="encash-panel">

                <div class="encash-panel-header">
                    <div>
                        <h3>Recent Encashment History</h3>
                        <p>Latest released, rejected, and processing merchant withdrawals.</p>
                    </div>

                    <a href="<?= ADMIN_URL ?>/transactions.php?type=encashment" class="history-link">View All</a>
                </div>

                <div class="table-responsive">
                    <table class="table encash-table align-middle js-datatable" id="encashmentHistoryTable" data-page-length="10"
                           data-empty-message="No encashments have been processed yet.">
                        <thead>
                            <tr>
                                <th data-card="title">Reference</th>
                                <th>Merchant</th>
                                <th data-card="amount">Amount</th>
                                <th data-card="hide">Method</th>
                                <th>Status</th>
                                <th data-card="hide">Requested</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($encashmentHistory as $history): ?>
                            <tr>
                                <td><?php echo gjc_e($history["reference_no"]); ?></td>
                                <td><?php echo gjc_e(gjc_user_label($db, (int) $history['user_id'])); ?></td>
                                <td class="amount-text"><?php echo gjc_money($history["amount"]); ?></td>
                                <td><span class="method-pill"><?php echo gjc_e($history["method"]); ?></span></td>
                                <td>
                                    <span class="encash-status <?php echo strtolower($history["status"]); ?>">
                                        <?php echo gjc_e(ucfirst($history["status"])); ?>
                                    </span>
                                </td>
                                <td><?php echo gjc_e(gjc_datetime($history["created_at"])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>
                </div>

            </section>

            </div>

        </main>

    </div>

    <!-- Send GenCoin Modal -->
    <div class="modal fade" id="sendGenCoinModal" tabindex="-1" aria-labelledby="sendGenCoinModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
            <div class="modal-content gp-modal">

                <!-- Header -->
                <div class="modal-header">
                    <div style="flex:1">
                        <h5 class="modal-title" id="sendGenCoinModalLabel">
                            <i class="fa-solid fa-coins"></i>Send GenCoin
                        </h5>
                        <!-- Step indicator -->
                        <div style="display:flex;gap:6px;margin-top:10px;align-items:center" id="sgc-steps">
                            <div class="sgc-step-dot sgc-step-dot--active" data-step="1"></div>
                            <div class="sgc-step-line"></div>
                            <div class="sgc-step-dot" data-step="2"></div>
                            <div class="sgc-step-line"></div>
                            <div class="sgc-step-dot" data-step="3"></div>
                            <span id="sgc-step-label" style="margin-left:8px;font-size:12px;color:var(--gp-muted);font-weight:600">Step 1 of 3</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">

                    <!-- STEP 1: Recipient ID (the ID tells which account it is) -->
                    <div id="sgc-step-1">
                        <p id="sgc-step1-hint" style="font-size:13px;color:var(--gjc-slate);margin-bottom:16px">Enter the Student, Employee or Guardian ID of the recipient.</p>
                        <div style="position:relative">
                            <input type="text" id="sgc-school-id" class="form-control"
                                   placeholder="e.g. GJC2026-0001 or GRD2026-0001" autocomplete="off"
                                   style="padding-right:44px">
                            <i class="fa-solid fa-magnifying-glass" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--gp-subtle);pointer-events:none"></i>
                        </div>
                        <div id="sgc-lookup-result" style="margin-top:10px;min-height:36px"></div>
                        <div style="display:flex;justify-content:flex-end;margin-top:16px">
                            <button type="button" id="sgc-next-1" class="btn btn-success" disabled
                                    onclick="sgcGoStep(2)">
                                Next <i class="fa-solid fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- STEP 2: Amount + Message -->
                    <div id="sgc-step-2" style="display:none">
                        <!-- Recipient pill -->
                        <div style="display:flex;align-items:center;gap:10px;background:#fff;border-radius:12px;padding:10px 14px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.06)">
                            <div style="width:36px;height:36px;border-radius:50%;background:#bbf7d4;display:flex;align-items:center;justify-content:center;font-weight:700;color:#27764b;font-size:15px" id="sgc-recipient-avatar"></div>
                            <div>
                                <div style="font-weight:700;font-size:14px;color:#111" id="sgc-recipient-name-2"></div>
                                <div style="font-size:11px;color:var(--gp-muted)" id="sgc-recipient-id-2"></div>
                            </div>
                        </div>

                        <label>Amount (₱)</label>
                        <div style="position:relative;margin-bottom:6px">
                            <input type="number" id="sgc-gencoins" class="form-control" min="1" step="0.01"
                                   placeholder="e.g. 50"
                                   style="padding-right:44px;font-size:20px;font-weight:700">
                            <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:12px;font-weight:600;color:var(--gp-subtle)">₱</span>
                        </div>
                        <div id="sgc-peso-equiv" style="font-size:12px;color:#27764b;font-weight:600;margin-bottom:12px;padding-left:4px;min-height:18px"></div>
                        <!-- Fee breakdown preview (step 2) -->
                        <div id="sgc-fee-preview" style="display:none;background:#fff;border-radius:10px;padding:10px 14px;font-size:12px;border:1px solid var(--gp-success-bg);margin-bottom:14px">
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                <span style="color:#27764b;font-weight:700">Credited to wallet</span>
                                <span id="sgc-fp-credited" style="font-weight:800;color:#27764b"></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                <span style="color:var(--gp-red)">Service fee (<?= CirculationEngine::ratePct(CirculationEngine::FEE_SYSTEM_RATE) ?>)</span>
                                <span id="sgc-fp-fee" style="font-weight:600;color:var(--gp-red)"></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;border-top:1px solid var(--gp-success-bg);padding-top:6px;margin-top:2px">
                                <span style="color:#111;font-weight:700">Collect from student</span>
                                <span id="sgc-fp-cash" style="font-weight:800;color:#111"></span>
                            </div>
                        </div>

                        <label>Message <span style="font-weight:400;color:var(--gp-subtle)">(optional)</span></label>
                        <textarea id="sgc-message" class="form-control" rows="2" maxlength="120"
                                  placeholder="e.g. For school supplies"
                                  style="resize:none"></textarea>

                        <div style="display:flex;justify-content:space-between;margin-top:20px">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="sgcGoStep(1)">
                                <i class="fa-solid fa-arrow-left me-1"></i> Back
                            </button>
                            <button type="button" id="sgc-next-2" class="btn btn-success" disabled
                                    onclick="sgcGoStep(3)">
                                Next <i class="fa-solid fa-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- STEP 3: Preview + Confirm -->
                    <div id="sgc-step-3" style="display:none">
                        <p style="font-size:13px;color:var(--gjc-slate);margin-bottom:14px;font-weight:600">Review the details before sending.</p>

                        <!-- Preview card -->
                        <div style="background:#fff;border-radius:16px;padding:18px 20px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:18px">
                            <div style="text-align:center;margin-bottom:16px">
                                <div style="font-size:32px;font-weight:800;color:#27764b" id="sgc-prev-coins"></div>
                                <div style="font-size:13px;color:var(--gp-muted)" id="sgc-prev-peso"></div>
                            </div>
                            <hr style="margin:12px 0;border-color:var(--gjc-soft-2)">
                            <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
                                <div style="display:flex;justify-content:space-between">
                                    <span style="color:var(--gp-muted)">To</span>
                                    <strong id="sgc-prev-name"></strong>
                                </div>
                                <div style="display:flex;justify-content:space-between">
                                    <span style="color:var(--gp-muted)">ID</span>
                                    <span id="sgc-prev-id" style="font-family:monospace"></span>
                                </div>
                                <div style="display:flex;justify-content:space-between" id="sgc-prev-msg-row">
                                    <span style="color:var(--gp-muted)">Message</span>
                                    <span id="sgc-prev-msg" style="max-width:180px;text-align:right;color:var(--gjc-slate)"></span>
                                </div>
                            </div>
                            <!-- Fee breakdown in preview -->
                            <div style="border-top:1px dashed var(--gp-success-bg);margin-top:12px;padding-top:12px;display:flex;flex-direction:column;gap:6px;font-size:12px">
                                <div style="display:flex;justify-content:space-between;font-size:13px">
                                    <span style="color:#27764b;font-weight:700">Credited to wallet</span>
                                    <span id="sgc-prev-credited" style="font-weight:800;color:#27764b"></span>
                                </div>
                                <div style="display:flex;justify-content:space-between">
                                    <span style="color:var(--gp-red)">Service fee (<?= CirculationEngine::ratePct(CirculationEngine::FEE_SYSTEM_RATE) ?>)</span>
                                    <span id="sgc-prev-fee" style="font-weight:600;color:var(--gp-red)"></span>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:13px">
                                    <span style="color:#111;font-weight:700">Collect from student</span>
                                    <span id="sgc-prev-cash" style="font-weight:800"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Confirmation checkbox -->
                        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;background:#fff;border-radius:12px;padding:12px 14px;border:1.5px solid var(--gp-success-bg)">
                            <input type="checkbox" id="sgc-confirm-check" style="width:18px;height:18px;margin-top:1px;accent-color:var(--gp-success);cursor:pointer">
                            <span style="font-size:13px;color:var(--gjc-slate);line-height:1.5">
                                I confirm that I want to send <strong id="sgc-confirm-coins"></strong> to <strong id="sgc-confirm-name"></strong>. This action cannot be undone.
                            </span>
                        </label>

                        <div id="sgc-send-error" style="margin-top:10px;font-size:13px;color:var(--gp-red);min-height:18px"></div>

                        <div style="display:flex;justify-content:space-between;margin-top:16px">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="sgcGoStep(2)">
                                <i class="fa-solid fa-arrow-left me-1"></i> Back
                            </button>
                            <button type="button" id="sgc-send-btn" class="btn btn-success" disabled
                                    onclick="sgcSend()">
                                <i class="fa-solid fa-paper-plane me-1"></i> Send
                            </button>
                        </div>
                    </div>

                    <!-- SUCCESS STATE -->
                    <div id="sgc-success" style="display:none;text-align:center;padding:16px 0">
                        <div style="width:64px;height:64px;background:var(--gp-success-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
                            <i class="fa-solid fa-circle-check" style="font-size:32px;color:var(--gp-success)"></i>
                        </div>
                        <div style="font-size:18px;font-weight:700;color:#27764b;margin-bottom:4px">Sent!</div>
                        <div style="font-size:13px;color:var(--gp-muted)" id="sgc-success-msg"></div>
                        <div style="margin-top:10px;display:inline-block;background:var(--gjc-soft-2);border:1px solid #bbf7d4;border-radius:8px;padding:6px 14px">
                            <span style="font-size:11px;color:var(--gp-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">Reference No.</span><br>
                            <span id="sgc-success-ref" style="font-size:13px;font-weight:700;color:#27764b;font-family:monospace;letter-spacing:.5px"></span>
                        </div>
                        <button type="button" class="btn btn-success mt-4 d-block mx-auto"
                                data-bs-dismiss="modal">Done</button>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
    <?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=2"></script>

    <script>
    function toggleSidebar() {
        document.getElementById("sidebar").classList.toggle("collapsed");
    }

    // ── Cash In / Cash Out tabs (#cash-in / #cash-out) ──
    function cashShowTab(name) {
        if (name !== 'cash-in' && name !== 'cash-out') name = 'cash-in';
        document.querySelectorAll('[data-cash-tab]').forEach((tab) => {
            const on = tab.dataset.cashTab === name;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.cash-pane').forEach((pane) => {
            pane.hidden = pane.id !== name;
        });
        // DataTables sizes columns on init; a table that started hidden needs a nudge.
        if (window.jQuery && jQuery.fn.dataTable) {
            jQuery.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
        }
    }
    document.querySelectorAll('[data-cash-tab]').forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            cashShowTab(tab.dataset.cashTab);
            history.replaceState(null, '', '#' + tab.dataset.cashTab);
        });
    });
    window.addEventListener('DOMContentLoaded', () => cashShowTab(location.hash.replace('#', '')));

    async function releaseEncashment(btn, encashmentId, merchantWalletId, amount, merchantName) {
        const ok = await gpConfirm({
            title: 'Release this encashment?',
            body: 'Hand over the cash, then release. The points return to the cashier vault.',
            detail: [
                ['Merchant', merchantName],
                ['Pay out', gpMoney(amount)],
            ],
            note: 'This cannot be undone.',
            confirmLabel: 'Release funds',
        });
        if (!ok) return;

        if (!gpBusy(btn, 'Releasing…')) return;

        const result = await gpFetchJson('release_encashment.php', {
            encashment_id: encashmentId,
            merchant_wallet_id: merchantWalletId,
            amount: amount,
        });

        if (result.success) {
            gpToast(result.message || 'Encashment released.');
            setTimeout(() => window.location.reload(), 900);
            return;
        }
        gpToast(result.message || 'The encashment could not be released.', false);
        gpBusyDone(btn);
    }

    async function rejectEncashment(btn, encashmentId, amount, merchantName) {
        const ok = await gpConfirm({
            title: 'Reject this encashment?',
            body: 'No cash is released and the merchant balance stays as it is.',
            detail: [
                ['Merchant', merchantName],
                ['Amount', gpMoney(amount)],
            ],
            note: 'The merchant will be notified.',
            confirmLabel: 'Reject request',
        });
        if (!ok) return;

        if (!gpBusy(btn, 'Rejecting…')) return;

        const result = await gpFetchJson('reject_encashment.php', { encashment_id: encashmentId });

        if (result.success) {
            gpToast(result.message || 'Encashment rejected.');
            setTimeout(() => window.location.reload(), 900);
            return;
        }
        gpToast(result.message || 'The encashment could not be rejected.', false);
        gpBusyDone(btn);
    }

    // ── Send GenCoin ────────────────────────────────────────────────────────
    const SGC_API = '<?= ADMIN_URL ?>/api/economy.php';
    // Fees ride ON TOP of the entered amount. Rates come from CirculationEngine
    // so this preview can never drift from what the engine actually charges.
    const FEE_SYSTEM_RATE = <?= json_encode(CirculationEngine::FEE_SYSTEM_RATE) ?>;
    const FEE_SYSTEM_PCT  = <?= json_encode(CirculationEngine::ratePct(CirculationEngine::FEE_SYSTEM_RATE)) ?>;
    let sgcWalletId = null, sgcParentId = null, sgcStudentName = '', sgcSchoolId = '';
    let sgcMode = 'student'; // 'student' | 'parent'

    function openSendGenCoin() {
        sgcReset();
        new bootstrap.Modal(document.getElementById('sendGenCoinModal')).show();
    }

    function sgcReset() {
        sgcWalletId = null; sgcParentId = null; sgcStudentName = ''; sgcSchoolId = '';
        sgcMode = 'student';
        document.getElementById('sgc-school-id').value = '';
        document.getElementById('sgc-lookup-result').innerHTML = '';
        document.getElementById('sgc-next-1').disabled = true;
        document.getElementById('sgc-gencoins').value = '';
        document.getElementById('sgc-peso-equiv').textContent = '';
        document.getElementById('sgc-message').value = '';
        document.getElementById('sgc-next-2').disabled = true;
        document.getElementById('sgc-confirm-check').checked = false;
        document.getElementById('sgc-send-btn').disabled = true;
        document.getElementById('sgc-send-btn').innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Send';
        document.getElementById('sgc-send-error').textContent = '';
        ['sgc-step-1','sgc-step-2','sgc-step-3','sgc-success'].forEach(id => {
            document.getElementById(id).style.display = 'none';
        });
        document.getElementById('sgc-step-1').style.display = '';
        sgcUpdateStepUI(1);
    }

    function sgcUpdateStepUI(step) {
        document.getElementById('sgc-step-label').textContent = `Step ${step} of 3`;
        document.querySelectorAll('.sgc-step-dot').forEach(dot => {
            const s = parseInt(dot.dataset.step);
            dot.classList.toggle('sgc-step-dot--active', s === step);
            dot.classList.toggle('sgc-step-dot--done', s < step);
        });
    }

    function sgcGoStep(step) {
        ['sgc-step-1','sgc-step-2','sgc-step-3'].forEach((id, i) => {
            document.getElementById(id).style.display = (i + 1 === step) ? '' : 'none';
        });
        sgcUpdateStepUI(step);
        if (step === 3) sgcBuildPreview();
    }

    // peso = the amount CREDITED to the wallet; the fee is added on top.
    function sgcCalcFee(peso) {
        const fee      = Math.round(peso * FEE_SYSTEM_RATE * 100) / 100;
        const credited = Math.round(peso * 100) / 100;
        const total    = Math.round((credited + fee) * 100) / 100;
        return { fee, credited, total };
    }

    function sgcFmt(n) {
        return '₱' + n.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    // Peso -> GenCoin equivalent for display (₱10 = 1 GC).
    function sgcGc(peso) {
        return (peso / 10).toLocaleString('en-PH', {maximumFractionDigits:2});
    }

    function sgcBuildPreview() {
        const peso    = parseFloat(document.getElementById('sgc-gencoins').value) || 0;
        const { fee, credited, total } = sgcCalcFee(peso);
        const msg     = document.getElementById('sgc-message').value.trim();

        document.getElementById('sgc-prev-coins').textContent   = sgcFmt(peso);
        document.getElementById('sgc-prev-peso').textContent    = '= ' + sgcGc(peso) + ' GenCoins';
        document.getElementById('sgc-prev-name').textContent    = sgcStudentName;
        document.getElementById('sgc-prev-id').textContent      = sgcSchoolId;
        document.getElementById('sgc-prev-msg').textContent     = msg || '—';
        document.getElementById('sgc-prev-credited').textContent= sgcFmt(credited);
        document.getElementById('sgc-prev-fee').textContent     = '+ ' + sgcFmt(fee);
        document.getElementById('sgc-prev-cash').textContent    = sgcFmt(total);
        document.getElementById('sgc-confirm-coins').textContent= sgcFmt(credited) + ' (₱' + credited.toFixed(2) + ')';
        document.getElementById('sgc-confirm-name').textContent = sgcStudentName;

        document.getElementById('sgc-confirm-check').checked = false;
        document.getElementById('sgc-send-btn').disabled = true;
    }

    async function sgcLookup() {
        const schoolId = document.getElementById('sgc-school-id').value.trim();
        const resultEl = document.getElementById('sgc-lookup-result');
        const nextBtn  = document.getElementById('sgc-next-1');
        if (!schoolId) return;

        resultEl.innerHTML = '<span style="font-size:12px;color:var(--gp-muted)">Looking up…</span>';
        nextBtn.disabled = true;
        sgcWalletId = null;
        sgcParentId = null;

        // The ID itself says who it is: GRD… is a guardian, anything else a student or faculty wallet.
        try {
            const res  = await fetch(SGC_API, {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({action:'lookup_recipient', school_id: schoolId}),
            });
            const data = await res.json();
            if (!data.success) {
                resultEl.innerHTML = '<div style="font-size:12px;color:var(--gp-red);padding:4px 2px"><i class="fa-solid fa-triangle-exclamation me-1"></i><span></span></div>';
                resultEl.querySelector('span').textContent = data.error || 'No account found with that ID.';
                return;
            }
            sgcMode = data.kind;
            sgcWalletId = data.kind === 'student' ? data.wallet_id : null;
            sgcParentId = data.kind === 'parent' ? data.parent_id : null;
            sgcStudentName = data.name;
            sgcSchoolId = data.id;
            resultEl.innerHTML = `
                <div style="display:flex;align-items:center;gap:8px;background:var(--gp-success-bg);border-radius:10px;padding:8px 12px">
                    <i class="fa-solid fa-circle-check" style="color:#27764b"></i>
                    <div>
                        <strong style="font-size:13px;color:#27764b"></strong>
                        <div class="rcp-sub" style="font-size:11px;color:var(--gp-muted)"></div>
                    </div>
                </div>`;
            resultEl.querySelector('strong').textContent = data.name;
            resultEl.querySelector('.rcp-sub').textContent = data.label + ' · ' + data.id;
            nextBtn.disabled = false;
            document.getElementById('sgc-recipient-avatar').textContent = data.name.charAt(0).toUpperCase();
            document.getElementById('sgc-recipient-name-2').textContent  = data.name;
            document.getElementById('sgc-recipient-id-2').textContent    = data.id;
        } catch {
            resultEl.innerHTML = '<div style="font-size:12px;color:var(--gp-red)">Network error. Try again.</div>';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const schoolInput = document.getElementById('sgc-school-id');
        schoolInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); sgcLookup(); }});
        schoolInput.addEventListener('blur', sgcLookup);

        document.getElementById('sgc-gencoins').addEventListener('input', function () {
            const peso    = parseFloat(this.value) || 0;   // amount is entered in ₱
            const next    = document.getElementById('sgc-next-2');
            const preview = document.getElementById('sgc-fee-preview');
            if (peso > 0) {
                const { fee, credited, total } = sgcCalcFee(peso);
                document.getElementById('sgc-peso-equiv').textContent  = '≈ ' + sgcGc(peso) + ' GenCoins (₱10 = 1 GC)';
                document.getElementById('sgc-fp-credited').textContent= sgcFmt(credited);
                document.getElementById('sgc-fp-fee').textContent     = '+ ' + sgcFmt(fee);
                document.getElementById('sgc-fp-cash').textContent    = sgcFmt(total);
                preview.style.display = '';
                next.disabled = false;
            } else {
                document.getElementById('sgc-peso-equiv').textContent = '';
                preview.style.display = 'none';
                next.disabled = true;
            }
        });

        document.getElementById('sgc-confirm-check').addEventListener('change', function () {
            document.getElementById('sgc-send-btn').disabled = !this.checked;
        });

        document.getElementById('sendGenCoinModal').addEventListener('hidden.bs.modal', function () {
            if (this.dataset.sent === 'true') {
                window.location.reload();
                return;
            }
            sgcReset();
        });
    });

    async function sgcSend() {
        const sendBtn  = document.getElementById('sgc-send-btn');
        const errorEl  = document.getElementById('sgc-send-error');
        const amount   = parseFloat(document.getElementById('sgc-gencoins').value) || 0;  // entered in ₱
        const msg      = document.getElementById('sgc-message').value.trim();
        const { fee, credited } = sgcCalcFee(amount);

        sendBtn.disabled = true;
        sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending…';
        errorEl.textContent = '';

        const payload = sgcMode === 'student'
            ? { action: 'topup', student_wallet_id: sgcWalletId, amount: amount, notes: msg || null }
            : { action: 'topup_parent', parent_id: sgcParentId, amount: amount, notes: msg || null };

        try {
            const res  = await fetch(SGC_API, {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('sendGenCoinModal').dataset.sent = 'true';
                const actualCredited = data.credited_amount ?? credited;
                const actualFee      = data.fee_amount      ?? fee;
                const actualTotal    = data.total_collected ?? (actualCredited + actualFee);
                document.getElementById('sgc-step-3').style.display = 'none';
                document.getElementById('sgc-success').style.display = '';
                document.getElementById('sgc-step-label').textContent = 'Complete';
                document.getElementById('sgc-success-msg').textContent =
                    sgcFmt(actualCredited) + ' credited to ' + sgcStudentName +
                    ' — collect ' + sgcFmt(actualTotal) + ' (' + sgcFmt(actualFee) + ' service fee on top).';
                document.getElementById('sgc-success-ref').textContent = data.reference || '—';
            } else {
                errorEl.textContent = data.error || 'Failed to send. Please try again.';
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Send';
            }
        } catch {
            errorEl.textContent = 'Network error. Please try again.';
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Send';
        }
    }
    // ────────────────────────────────────────────────────────────────────────
    </script>

</body>

</html>
