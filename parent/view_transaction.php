<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['parent']);
gjc_ensure_parent_schema($db);
gjc_ensure_parent_wallet_schema($db);

/**
 * CRUD "Read": one row from the parent's activity trail, in the shared .uv-*
 * record chrome (assets/css/detail_view.css).
 *
 * The id travels as a signed HMAC token so the URL can't be walked, and the
 * lookup is still constrained to the same scope activity.php lists: the
 * linked students' wallets, plus the parent's own wallet (Scan & Pay). A
 * transaction outside that scope reads as "not available", exactly like one
 * that never existed — the page can't be used to probe other families.
 */
$parentUserId = gjc_user_id();
$parentId = gjc_parent_id_for_user($db, $parentUserId);
$parentWallet = gjc_parent_wallet($db, $parentId);
$parentWalletId = (int) $parentWallet['id'];

$linkedStmt = $db->prepare(
    "SELECT u.userID, u.first_name, u.last_name, sw.id AS wallet_id
       FROM parent_student_links psl
       JOIN users u ON u.userID = psl.student_user_id
       LEFT JOIN student_wallets sw ON sw.user_id = u.userID
      WHERE psl.parent_id = ?"
);
$linkedStmt->execute([$parentId]);

$studentByWallet = [];
$walletIds = [];
foreach ($linkedStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    if ((int) $s['wallet_id'] > 0) {
        $walletIds[] = (int) $s['wallet_id'];
        $studentByWallet[(int) $s['wallet_id']] = trim($s['first_name'] . ' ' . $s['last_name']);
    }
}

$txnId = gjc_verify_view_token($_GET['token'] ?? null, 'parent_txn');

$record = null;
$items = [];

if ($txnId !== null && ($walletIds || $parentWalletId > 0) && gjc_table_exists($db, 'transactions')) {
    $scopeClauses = [];
    $params = [$txnId];
    if ($walletIds) {
        $ph = implode(',', array_fill(0, count($walletIds), '?'));
        $scopeClauses[] = "student_wallet_id IN ({$ph})";
        $params = array_merge($params, $walletIds);
    }
    if ($parentWalletId > 0) {
        $scopeClauses[] = "parent_wallet_id = ?";
        $params[] = $parentWalletId;
    }

    $stmt = $db->prepare(
        "SELECT * FROM transactions
          WHERE id = ? AND (" . implode(' OR ', $scopeClauses) . ")
          LIMIT 1"
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row) {
        $type = (string) ($row['transaction_type'] ?? '');
        $meta = gjc_student_txn_meta($type);

        $whose = ((int) ($row['parent_wallet_id'] ?? 0) > 0 && (int) $row['parent_wallet_id'] === $parentWalletId)
            ? 'You (Parent)'
            : ($studentByWallet[(int) ($row['student_wallet_id'] ?? 0)] ?? 'Linked Student');

        $counterLabel = 'Counterparty';
        $counterparty = '';
        if (in_array($type, ['payment', 'voucher_payment'], true) && (int) ($row['merchant_wallet_id'] ?? 0) > 0) {
            $counterLabel = 'Merchant';
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
            'icon' => $meta['icon'],
            'incoming' => $meta['incoming'],
            'type_label' => $meta['label'],
            'amount' => (float) ($row['amount'] ?? 0),
            'ref' => (string) ($row['reference_no'] ?: 'N/A'),
            'status' => (string) ($row['status'] ?? 'completed'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'whose' => $whose,
            'counter_label' => $counterLabel,
            'counterparty' => $counterparty,
        ];

        if ($record['ref'] !== 'N/A') {
            $items = gjc_transaction_line_items($db, $record['ref']);
        }
    }
}

if (!$record) {
    http_response_code(404);
}

// Local escaper — the shared gjc_e() only casts to string, and this page
// renders student names, merchant names and free-text notes.
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

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
    <title><?= $record ? 'Activity Details' : 'Content Not Available' ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=21">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=11">
</head>

<body class="gp-theme">
    <div class="uv-wrap">
        <?php if (!$record): ?>
        <div class="uv-notfound">
            <div class="uv-nf-ic"><i class="fa-regular fa-circle-question"></i></div>
            <h1>This page isn't available</h1>
            <p>The link you followed may be broken, or the record may have been removed.</p>
            <a href="<?= PARENT_URL ?>/activity.php" class="gp-btn gp-btn--forest">Go to Activity Trail</a>
        </div>
        <?php else: ?>
        <div class="uv-topbar">
            <a class="uv-back" href="<?= PARENT_URL ?>/activity.php">
                <i class="fa-solid fa-arrow-left"></i> Back to Activity Trail
            </a>
        </div>

        <section class="uv-hero">
            <div class="uv-avatar"><i class="fa-solid <?= $e($record['icon']) ?>"></i></div>
            <div class="uv-hero-main">
                <div class="uv-eyebrow"><?= $e($record['whose']) ?></div>
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
                    <h3>Activity details</h3>
                    <p>Read-only view of this movement on your family's wallets.</p>
                </div>
            </div>
            <div class="uv-fields">
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-graduation-cap"></i></div>
                    <div>
                        <div class="uv-field-label">Wallet</div>
                        <div class="uv-field-value"><?= $e($record['whose']) ?></div>
                    </div>
                </div>
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
        $uvItemsNote = 'What was bought in this order.';
        require __DIR__ . '/../includes/partials/detail_line_items.php';
        ?>
        <?php endif; ?>
    </div>
</body>

</html>
