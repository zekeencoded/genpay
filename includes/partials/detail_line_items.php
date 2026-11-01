<?php
/**
 * Itemised receipt for portal view_transaction pages.
 *
 * Expects:
 *   $uvItems       array from gjc_transaction_line_items()
 *   $uvItemsTotal  float, the transaction amount
 *   $uvItemsNote   string|null, optional line under the heading
 */
$uvItems = $uvItems ?? [];
if (!$uvItems) {
    return;
}
$uvItemsTotal = (float) ($uvItemsTotal ?? 0);
$uvItemsNote = $uvItemsNote ?? 'What was bought in this order.';
$uvLinesTotal = array_sum(array_column($uvItems, 'line_total'));
$uvEsc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<section class="gp-card uv-card uv-items">
    <div class="gp-card-head">
        <div>
            <h3>Order items</h3>
            <p><?= $uvEsc($uvItemsNote) ?></p>
        </div>
        <span class="uv-items-count"><?= count($uvItems) ?> <?= count($uvItems) === 1 ? 'item' : 'items' ?></span>
    </div>

    <ul class="uv-item-list">
        <?php foreach ($uvItems as $uvItem): ?>
        <li class="uv-item">
            <span class="uv-item-qty"><?= (int) $uvItem['qty'] ?>&times;</span>
            <span class="uv-item-name">
                <?= $uvEsc($uvItem['name']) ?>
                <small><?= gjc_money($uvItem['price']) ?> each</small>
            </span>
            <span class="uv-item-total"><?= gjc_money($uvItem['line_total']) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="uv-item-foot">
        <span>Total</span>
        <span class="uv-item-grand"><?= gjc_money($uvItemsTotal ?: $uvLinesTotal) ?></span>
    </div>

    <?php if ($uvItemsTotal > 0 && abs($uvLinesTotal - $uvItemsTotal) > 0.01): ?>
    <!-- The snapshot was taken when the order was placed; if the charged
         amount differs, say so rather than quietly showing two numbers. -->
    <p class="uv-item-warn">
        <i class="fa-solid fa-circle-info"></i>
        Item lines add up to <?= gjc_money($uvLinesTotal) ?>, which differs from the amount charged.
    </p>
    <?php endif; ?>
</section>
