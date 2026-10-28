<?php
/**
 * CRUD "Read": one row from the admin transaction feed.
 *
 * The id travels as a signed HMAC token, so the URL cannot be walked by
 * incrementing a number. The token is scoped to the source it was minted for,
 * so a token for a ledger row will not open the top-up request that happens to
 * share its id.
 *
 * ?source= mirrors gjc_fetch_admin_transactions()'s three feeds:
 *   ledger             -> transactions
 *   topup_request      -> topup_requests
 *   encashment_request -> encashment_requests
 */
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);

$source = trim((string) ($_GET['source'] ?? 'ledger'));
if (!in_array($source, ['ledger', 'topup_request', 'encashment_request'], true)) {
    $source = 'ledger';
}

// The reference number is no longer a lookup key in the URL: the id the token
// carries already identifies a row within its source, and matching on a
// user-supplied ref would reopen the walkable address the token closes.
// A missing or tampered token verifies to null and falls through to the same
// "not available" screen as a genuine miss, so the page never reveals whether
// a given record exists.
$recordId = gjc_verify_view_token($_GET['token'] ?? null, 'admin_txn_' . $source);

$transaction = $recordId !== null
    ? gjc_find_admin_transaction($db, $source, null, $recordId)
    : null;

if (!$transaction) {
    http_response_code(404);
}

$esc = gjc_e(...);

if ($transaction) {
    $meta = $transaction['meta'] ?? [];
    $status = (string) ($transaction['status'] ?? '');

    // Success / pending / everything-else -> the shared hero pill colours.
    $statusClass = gjc_transaction_is_success($status)
        ? 'is-active'
        : (gjc_transaction_is_pending($status) ? 'is-pending' : 'is-inactive');

    // Type glyph for the hero tile (mirrors the user page's monogram slot).
    $typeIconMap = [
        'topup'      => 'fa-wallet',
        'encashment' => 'fa-money-bill-transfer',
        'payment'    => 'fa-cart-shopping',
        'transfer'   => 'fa-right-left',
        'refund'     => 'fa-rotate-left',
    ];
    $typeIcon = $typeIconMap[(string) ($transaction['type_slug'] ?? '')] ?? 'fa-receipt';

    $sourceLabel = ucwords(str_replace('_', ' ', (string) $transaction['source']));
    $notes = trim((string) ($transaction['notes'] ?? ''));

    // The maintenance fee charged on this payment/transfer (tuition-only, so it
    // is a line here, not part of the wallet amount above).
    $maintFee = $transaction['source'] === 'ledger'
        ? gjc_maintenance_fee_for_reference($db, (string) $transaction['ref'])
        : null;
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
    <title><?= $transaction ? 'Transaction Details' : 'Content Not Available' ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=22">
</head>

<body class="gp-theme transaction-detail-page">
    <div class="uv-wrap">
        <?php if (!$transaction): ?>
        <!-- Generic not-available state, consistent with view_user.php. -->
        <div class="uv-notfound">
            <div class="uv-nf-ic"><i class="fa-regular fa-circle-question"></i></div>
            <h1>This page isn't available</h1>
            <p>The link you followed may be broken, or the record may have been removed.</p>
            <a href="<?= ADMIN_URL ?>/transactions.php" class="gp-btn gp-btn--forest">Go to Transactions</a>
        </div>
        <?php else: ?>
        <div class="uv-topbar">
            <a class="uv-back" href="<?= ADMIN_URL ?>/transactions.php">
                <i class="fa-solid fa-arrow-left"></i> Back to Transactions
            </a>
        </div>

        <section class="uv-hero">
            <div class="uv-avatar"><i class="fa-solid <?= $esc($typeIcon) ?>"></i></div>
            <div class="uv-hero-main">
                <div class="uv-eyebrow"><?= $esc($sourceLabel) ?> transaction</div>
                <div class="uv-amount"><?= gjc_money($transaction['amount']) ?></div>
                <div class="uv-id-line">
                    <span class="gp-hero-badge"><?= $esc($transaction['type_label']) ?></span>
                    <span class="uv-mono"><?= $esc($transaction['ref']) ?></span>
                    <span class="uv-status <?= $statusClass ?>"><?= $esc($transaction['status_label']) ?></span>
                </div>
            </div>
        </section>

        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div>
                    <h3>Transaction details</h3>
                    <p>Read-only view of the selected wallet movement or request.</p>
                </div>
            </div>
            <div class="uv-fields">
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-user"></i></div>
                    <div>
                        <div class="uv-field-label">Sender</div>
                        <div class="uv-field-value"><?= $esc($transaction['sender']) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-user-check"></i></div>
                    <div>
                        <div class="uv-field-label">Receiver</div>
                        <div class="uv-field-value"><?= $esc($transaction['receiver']) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-layer-group"></i></div>
                    <div>
                        <div class="uv-field-label">Source</div>
                        <div class="uv-field-value"><?= $esc($sourceLabel) ?></div>
                    </div>
                </div>
                <?php
                $ftFee = $maintFee; $ftAmount = (float) $transaction['amount']; $ftShowAmount = true; $ftMerchant = false;
                require __DIR__ . '/../includes/partials/detail_fee_fields.php';
                ?>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-regular fa-clock"></i></div>
                    <div>
                        <div class="uv-field-label">Recorded At</div>
                        <div class="uv-field-value"><?= $esc($transaction['time_label']) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-solid fa-hashtag"></i></div>
                    <div>
                        <div class="uv-field-label">Reference</div>
                        <div class="uv-field-value is-mono"><?= $esc($transaction['ref']) ?></div>
                    </div>
                </div>
                <div class="uv-field">
                    <div class="uv-field-ic"><i class="fa-regular fa-note-sticky"></i></div>
                    <div>
                        <div class="uv-field-label">Notes</div>
                        <div class="uv-field-value"><?= $esc($notes !== '' ? $notes : 'None') ?></div>
                    </div>
                </div>
            </div>
        </section>

        <?php require __DIR__ . '/../includes/partials/detail_download.php'; ?>

        <?php if (!empty($meta)): ?>
        <details class="uv-raw">
            <summary>
                <span class="uv-raw-lead">
                    <i class="fa-solid fa-table-list"></i> Raw record
                    <small>(<?= count($meta) ?> fields)</small>
                </span>
                <i class="fa-solid fa-chevron-down uv-raw-chev"></i>
            </summary>
            <div class="uv-raw-body table-responsive">
                <table class="table align-middle">
                    <tbody>
                        <?php foreach ($meta as $key => $value): ?>
                        <tr>
                            <th><?= $esc(ucwords(str_replace('_', ' ', (string) $key))) ?></th>
                            <td><?= $esc(is_scalar($value) || $value === null ? (string) $value : json_encode($value)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</body>

</html>
