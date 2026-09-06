<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);

$source = trim((string) ($_GET['source'] ?? 'ledger'));
$ref = trim((string) ($_GET['ref'] ?? ''));
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$transaction = gjc_find_admin_transaction($db, $source, $ref, $id ?: null);

if (!$transaction) {
    http_response_code(404);
}

// Local escaper — the shared gjc_e() only casts to string, and this page
// renders record values (sender/receiver labels, notes, meta).
$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

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
        'voucher'    => 'fa-ticket',
        'transfer'   => 'fa-right-left',
        'refund'     => 'fa-rotate-left',
    ];
    $typeIcon = $typeIconMap[(string) ($transaction['type_slug'] ?? '')] ?? 'fa-receipt';

    $sourceLabel = ucwords(str_replace('_', ' ', (string) $transaction['source']));
    $notes = trim((string) ($transaction['notes'] ?? ''));
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
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=35">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=11">
</head>

<body class="gp-theme">
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
