<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['merchant']);

/**
 * CRUD "Read": one sale from this merchant's own history, in the shared
 * .uv-* record chrome (assets/css/detail_view.css).
 *
 * The id travels as a signed HMAC token so the URL can't be walked, and the
 * lookup is still scoped to this stall's wallet — staff and owner both read
 * the same stall, which is what gjc_merchant_owner_id() resolves.
 */
$currentUser = gjc_current_user($db);
$ownerMerchId = gjc_merchant_owner_id($db, (int) $currentUser['id']);
$wallet = gjc_merchant_wallet($db, $ownerMerchId);
$walletId = (int) $wallet['id'];

$txnId = gjc_verify_view_token($_GET['token'] ?? null, 'merchant_txn');

$record = null;
$items = [];

if ($txnId !== null && $walletId > 0 && gjc_table_exists($db, 'transactions')) {
    $stmt = $db->prepare(
        "SELECT * FROM transactions WHERE id = ? AND merchant_wallet_id = ? LIMIT 1"
    );
    $stmt->execute([$txnId, $walletId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row) {
        $type = (string) ($row['transaction_type'] ?? '');

        // Who paid. A student wallet on the row means a student bought at the
        // counter; a parent wallet means the Scan & Pay flow.
        $customerLabel = 'Customer';
        $customer = 'Walk-in';
        if ((int) ($row['student_wallet_id'] ?? 0) > 0) {
            $sStmt = $db->prepare("SELECT user_id FROM student_wallets WHERE id = ? LIMIT 1");
            $sStmt->execute([(int) $row['student_wallet_id']]);
            $sUserId = (int) $sStmt->fetchColumn();
            $customer = $sUserId ? gjc_user_label($db, $sUserId) : 'Student Wallet #' . (int) $row['student_wallet_id'];
        } elseif ((int) ($row['parent_wallet_id'] ?? 0) > 0) {
            // parent_wallets keys on parents.id, not users.userID — hop through
            // parents to reach the account the name lives on.
            $customerLabel = 'Paid By (Parent)';
            $pUserId = 0;
            if (gjc_table_exists($db, 'parent_wallets') && gjc_table_exists($db, 'parents')) {
                $pStmt = $db->prepare(
                    "SELECT p.user_id
                       FROM parent_wallets pw
                       JOIN parents p ON p.id = pw.parent_id
                      WHERE pw.id = ? LIMIT 1"
                );
                $pStmt->execute([(int) $row['parent_wallet_id']]);
                $pUserId = (int) $pStmt->fetchColumn();
            }
            $customer = $pUserId ? gjc_user_label($db, $pUserId) : 'Parent Wallet #' . (int) $row['parent_wallet_id'];
        } elseif ($type === 'merchant_settle' || $type === 'encashment') {
            $customerLabel = 'Released By';
            $customer = (int) ($row['initiated_by'] ?? 0) > 0
                ? gjc_user_label($db, (int) $row['initiated_by'])
                : 'Cashier / Finance Office';
        }

        // A refund leaves the stall wallet; everything else lands in it.
        $outgoing = in_array($type, ['refund', 'merchant_settle', 'encashment'], true);
        $iconMap = [
            'payment' => 'fa-cart-shopping',
            'voucher_payment' => 'fa-credit-card',
            'refund' => 'fa-rotate-left',
            'merchant_settle' => 'fa-money-bill-transfer',
            'encashment' => 'fa-money-bill-transfer',
        ];

        $record = [
            'type_raw' => $type,
            'icon' => $iconMap[$type] ?? 'fa-receipt',
            'incoming' => !$outgoing,
            'type_label' => gjc_transaction_type_label($type),
            'amount' => (float) ($row['amount'] ?? 0),
            'ref' => (string) ($row['reference_no'] ?: 'N/A'),
            'status' => (string) ($row['status'] ?? 'completed'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'customer_label' => $customerLabel,
            'customer' => $customer,
        ];

        if ($record['ref'] !== 'N/A') {
            $items = gjc_transaction_line_items($db, $record['ref']);
        }
    }
}

if (!$record) {
    http_response_code(404);
}

// The student's maintenance fee on this sale — shown for context only. It is a
// school charge billed to the student's tuition; the merchant is credited the
// full sale amount either way.
$maintFee = $record ? gjc_maintenance_fee_for_reference($db, (string) $record['ref']) : null;

// Local escaper — the shared gjc_e() only casts to string, and this page
// renders customer names and free-text notes.
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($record) {
    // 'reversed' is the refunded state in merchant/history.php — neither a
    // success nor pending, so it lands on the neutral-negative tab.
    $statusClass = gjc_transaction_is_success($record['status'])
        ? 'is-active'
        : (gjc_transaction_is_pending($record['status']) ? 'is-pending' : 'is-inactive');
    $statusLabel = strtolower($record['status']) === 'reversed'
        ? 'Refunded'
        : gjc_transaction_status_label($record['status']);
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
    <title><?= $record ? 'Sale Details' : 'Content Not Available' ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=23">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=20">
</head>

<body class="gp-theme">
    <div class="uv-wrap">
        <?php if (!$record): ?>
        <div class="uv-notfound">
            <div class="uv-nf-ic"><i class="fa-regular fa-circle-question"></i></div>
            <h1>This page isn't available</h1>
            <p>The link you followed may be broken, or the record may have been removed.</p>
            <a href="<?= MERCHANT_URL ?>/history.php" class="gp-btn gp-btn--forest">Go to Sales History</a>
        </div>
        <?php else: ?>
        <div class="uv-topbar">
            <a class="uv-back" href="<?= MERCHANT_URL ?>/history.php">
                <i class="fa-solid fa-arrow-left"></i> Back to Sales History
            </a>
        </div>

        <section class="uv-hero">
            <div class="uv-avatar"><i class="fa-solid <?= $e($record['icon']) ?>"></i></div>
            <div class="uv-hero-main">
                <div class="uv-eyebrow">Stall <?= $e(strtolower($record['type_label'])) ?></div>
                <div class="uv-amount <?= $record['incoming'] ? 'is-in' : 'is-out' ?>">
                    <?= $record['incoming'] ? '+' : '&minus;' ?><?= gjc_money($record['amount']) ?>
                </div>
                <div class="uv-id-line">
                    <span class="gp-hero-badge"><?= $e($record['type_label']) ?></span>
                    <span class="uv-mono"><?= $e($record['ref']) ?></span>
                    <span class="uv-status <?= $statusClass ?>"><?= $e($statusLabel) ?></span>
                </div>
            </div>
        </section>

        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div>
                    <h3>Sale details</h3>
                    <p>Read-only view of this movement on your stall wallet.</p>
                </div>
            </div>
            <div class="uv-fields">
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-user"></i></div>
                    <div>
                        <div class="uv-field-label"><?= $e($record['customer_label']) ?></div>
                        <div class="uv-field-value"><?= $e($record['customer']) ?></div>
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
                $ftFee = $maintFee; $ftAmount = $record['amount']; $ftShowAmount = false; $ftMerchant = true;
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
            </div>
        </section>

        <?php
        $uvItems = $items;
        $uvItemsTotal = $record['amount'];
        $uvItemsNote = 'What the customer bought in this order.';
        require __DIR__ . '/../includes/partials/detail_line_items.php';
        ?>
        <?php endif; ?>
    </div>
</body>

</html>
