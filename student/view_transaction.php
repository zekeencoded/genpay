<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['student']);
gjc_enforce_student_lock($db);

/**
 * CRUD "Read": one row from the student's own history, in the same shape as
 * the admin detail pages (assets/css/detail_view.css, the .uv-* chrome).
 *
 * Two things guard the record. The id travels as a signed HMAC token, so the
 * URL can't be walked by incrementing a number; and every lookup is still
 * filtered by this student's own wallet / user id, so even a valid token
 * minted elsewhere can't surface someone else's row.
 *
 * ?source= mirrors student/history.php's two feeds:
 *   ledger        -> transactions   (by student_wallet_id)
 *   topup_request -> topup_requests (by student_wallet_id, the not-yet-approved ones)
 */
$currentUser = gjc_current_user($db);
$wallet = gjc_student_wallet($db, (int) $currentUser['id']);
$walletId = (int) $wallet['id'];

$source = trim((string) ($_GET['source'] ?? 'ledger'));
if (!in_array($source, ['ledger', 'topup_request'], true)) {
    $source = 'ledger';
}

$recordId = gjc_verify_view_token($_GET['token'] ?? null, 'student_' . $source);

$record = null;
$items = [];

if ($recordId !== null && $source === 'ledger' && $walletId > 0 && gjc_table_exists($db, 'transactions')) {
    $stmt = $db->prepare(
        "SELECT * FROM transactions WHERE id = ? AND student_wallet_id = ? LIMIT 1"
    );
    $stmt->execute([$recordId, $walletId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row) {
        $type = (string) ($row['transaction_type'] ?? '');
        $meta = gjc_student_txn_meta($type);

        // Who the money went to / came from, in the student's own words.
        $counterLabel = 'Counterparty';
        $counterparty = '';
        if ($type === 'payment' && (int) ($row['merchant_wallet_id'] ?? 0) > 0) {
            $counterLabel = 'Paid To';
            $counterparty = gjc_merchant_wallet_owner_label($db, (int) $row['merchant_wallet_id']);
        } elseif ($type === 'allowance' && (int) ($row['initiated_by'] ?? 0) > 0) {
            $counterLabel = 'Sent By';
            $counterparty = gjc_user_label($db, (int) $row['initiated_by']);
        } elseif (in_array($type, ['cash_in', 'topup'], true)) {
            $counterLabel = 'Credited By';
            $counterparty = (int) ($row['initiated_by'] ?? 0) > 0
                ? gjc_user_label($db, (int) $row['initiated_by'])
                : 'Cashier / Finance Office';
        } elseif ($type === 'refund' && (int) ($row['merchant_wallet_id'] ?? 0) > 0) {
            $counterLabel = 'Refunded By';
            $counterparty = gjc_merchant_wallet_owner_label($db, (int) $row['merchant_wallet_id']);
        }

        $record = [
            'type_raw' => $type,
            'eyebrow' => 'Wallet ' . strtolower($meta['label']),
            'icon' => $meta['icon'],
            'incoming' => $meta['incoming'],
            'type_label' => $meta['label'],
            'amount' => (float) ($row['amount'] ?? 0),
            'ref' => (string) ($row['reference_no'] ?: 'N/A'),
            'status' => (string) ($row['status'] ?? 'completed'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'counter_label' => $counterLabel,
            'counterparty' => $counterparty,
            'extra' => [],
        ];

        if ($record['ref'] !== 'N/A') {
            $items = gjc_transaction_line_items($db, $record['ref']);
        }
    }
}

if ($recordId !== null && $source === 'topup_request' && gjc_table_exists($db, 'topup_requests')) {
    $stmt = $db->prepare(
        // Fee and credited amount live on the ledger row the request became.
        "SELECT r.*, t.fee_amount AS fee_amount, t.amount AS credited_amount
           FROM topup_requests r
           LEFT JOIN transactions t ON t.reference_no = r.reference_no
          WHERE r.id = ? AND r.student_wallet_id = ? LIMIT 1"
    );
    $stmt->execute([$recordId, $walletId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row) {
        // The fee columns arrive from a later guarded migration, so treat them
        // as optional rather than assuming this install already has them.
        $extra = [];
        if (isset($row['fee_amount']) && (float) $row['fee_amount'] > 0) {
            $extra[] = ['icon' => 'fa-percent', 'label' => 'Service Fee', 'value' => gjc_money($row['fee_amount']), 'raw' => true];
        }
        if (isset($row['credited_amount']) && (float) $row['credited_amount'] > 0) {
            $extra[] = ['icon' => 'fa-coins', 'label' => 'Amount Credited', 'value' => gjc_money($row['credited_amount']), 'raw' => true];
        }
        if (!empty($row['approved_at'])) {
            $extra[] = ['icon' => 'fa-circle-check', 'label' => 'Approved At', 'value' => date('M d, Y h:i A', strtotime((string) $row['approved_at']))];
        }
        if (!empty($row['rejected_at'])) {
            $extra[] = ['icon' => 'fa-circle-xmark', 'label' => 'Rejected At', 'value' => date('M d, Y h:i A', strtotime((string) $row['rejected_at']))];
        }

        $record = [
            'eyebrow' => 'Top-up request',
            'icon' => 'fa-circle-plus',
            'incoming' => true,
            'type_label' => 'Top-up Request',
            'amount' => (float) ($row['amount'] ?? 0),
            'ref' => (string) ($row['reference_no'] ?: 'TOPUP-REQ'),
            'status' => (string) ($row['status'] ?? 'pending'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'notes' => '',
            'counter_label' => 'Payment Method',
            'counterparty' => (string) ($row['payment_method'] ?? 'Cash at Cashier'),
            'extra' => $extra,
        ];
    }
}

if (!$record) {
    http_response_code(404);
}

// Maintenance fee charged on this payment/transfer: billed to tuition, so it is
// shown as its own line and is NOT part of the wallet amount above.
$maintFee = ($record && $source === 'ledger')
    ? gjc_maintenance_fee_for_reference($db, (string) $record['ref'], (int) $currentUser['id'])
    : null;

$e = gjc_e(...);

if ($record) {
    $statusClass = gjc_transaction_is_success($record['status'])
        ? 'is-active'
        : (gjc_transaction_is_pending($record['status']) ? 'is-pending' : 'is-inactive');
    $dateLabel = $record['created_at'] !== ''
        ? date('M d, Y h:i A', strtotime($record['created_at']))
        : 'N/A';
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
    <title><?= $record ? 'Transaction Details' : 'Content Not Available' ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=23">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=22">
</head>

<body class="gp-theme transaction-detail-page">
    <div class="uv-wrap">
        <?php if (!$record): ?>
        <!-- Generic not-available state, same wording as the admin views: a
             record that isn't this student's reads the same as one that never
             existed, so the page can't be used to probe for other wallets. -->
        <div class="uv-notfound">
            <div class="uv-nf-ic"><i class="fa-regular fa-circle-question"></i></div>
            <h1>This page isn't available</h1>
            <p>The link you followed may be broken, or the record may have been removed.</p>
            <a href="<?= STUDENT_URL ?>/history.php" class="gp-btn gp-btn--forest">Go to History</a>
        </div>
        <?php else: ?>
        <div class="uv-topbar">
            <a class="uv-back" href="<?= STUDENT_URL ?>/history.php">
                <i class="fa-solid fa-arrow-left"></i> Back to History
            </a>
        </div>

        <section class="uv-hero">
            <div class="uv-avatar"><i class="fa-solid <?= $e($record['icon']) ?>"></i></div>
            <div class="uv-hero-main">
                <div class="uv-eyebrow"><?= $e($record['eyebrow']) ?></div>
                <div class="uv-amount <?= $record['incoming'] ? 'is-in' : 'is-out' ?>">
                    <?= $record['incoming'] ? '+' : '&minus;' ?><?= gjc_money($record['amount']) ?>
                </div>
                <div class="uv-id-line">
                    <span class="gp-hero-badge"><?= $e($record['type_label']) ?></span>
                    <span class="uv-mono"><?= $e($record['ref']) ?></span>
                    <span class="uv-status <?= $statusClass ?>"><?= $e(gjc_transaction_status_label($record['status'])) ?></span>
                </div>
            </div>
        </section>

        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div>
                    <h3>Transaction details</h3>
                    <p>Read-only view of this movement on your wallet.</p>
                </div>
            </div>
            <div class="uv-fields">
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-store"></i></div>
                    <div>
                        <div class="uv-field-label"><?= $e($record['counter_label']) ?></div>
                        <div class="uv-field-value"><?= $record['counterparty'] !== '' ? $e($record['counterparty']) : '&mdash;' ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-layer-group"></i></div>
                    <div>
                        <div class="uv-field-label">Type</div>
                        <div class="uv-field-value"><?= $e($record['type_label']) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-coins"></i></div>
                    <div>
                        <div class="uv-field-label">Amount</div>
                        <div class="uv-field-value"><?= gjc_money($record['amount']) ?> &middot; <?= gjc_gc_amount($record['amount']) ?> GC</div>
                    </div>
                </div>
                <?php
                $ftFee = $maintFee; $ftAmount = $record['amount']; $ftShowAmount = false; $ftMerchant = false;
                require __DIR__ . '/../includes/partials/detail_fee_fields.php';
                ?>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-regular fa-clock"></i></div>
                    <div>
                        <div class="uv-field-label">Recorded At</div>
                        <div class="uv-field-value"><?= $e($dateLabel) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-hashtag"></i></div>
                    <div>
                        <div class="uv-field-label">Reference</div>
                        <div class="uv-field-value is-mono"><?= $e($record['ref']) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-regular fa-note-sticky"></i></div>
                    <div>
                        <div class="uv-field-label">Notes</div>
                        <div class="uv-field-value"><?= $e($record['notes'] !== '' ? $record['notes'] : 'None') ?></div>
                    </div>
                </div>
                <?php foreach ($record['extra'] as $field): ?>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid <?= $e($field['icon']) ?>"></i></div>
                    <div>
                        <div class="uv-field-label"><?= $e($field['label']) ?></div>
                        <div class="uv-field-value"><?= !empty($field['raw']) ? $field['value'] : $e($field['value']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php
        require __DIR__ . '/../includes/partials/detail_download.php';
        $uvItems = $items;
        $uvItemsTotal = $record['amount'];
        $uvItemsNote = 'What you bought in this order.';
        require __DIR__ . '/../includes/partials/detail_line_items.php';
        ?>
        <?php endif; ?>
    </div>
</body>

</html>
