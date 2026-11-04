<?php
/**
 * Maintenance Fee and Total rows for view_transaction pages. Voided fees are excluded from the total.
 *
 * Expects:
 *   $ftFee         array|null  from gjc_maintenance_fee_for_reference() — null renders nothing
 *   $ftAmount      float       the payment/transfer amount
 *   $ftShowAmount  bool        also print an Amount row
 *   $ftMerchant    bool        merchant viewer: fee is informational, no total
 */
$ftFee = $ftFee ?? null;
if (!$ftFee) {
    return;
}
$ftEsc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ftAmount = (float) ($ftAmount ?? 0);
$ftFeeVoided = $ftFee['status'] === 'voided';
$ftFeeCharged = $ftFeeVoided ? 0.0 : (float) $ftFee['fee_amount'];
?>
<?php if (!empty($ftShowAmount)): ?>
<div class="uv-field">
    <div class="uv-field-ic"><i class="fa-solid fa-coins"></i></div>
    <div>
        <div class="uv-field-label">Amount</div>
        <div class="uv-field-value"><?= gjc_money($ftAmount) ?></div>
    </div>
</div>
<?php endif; ?>
<div class="uv-field">
    <div class="uv-field-ic"><i class="fa-solid fa-wallet"></i></div>
    <div>
        <div class="uv-field-label">Maintenance Fee</div>
        <div class="uv-field-value">
            <?php if (!empty($ftMerchant)): ?>
            <?= gjc_money($ftFee['fee_amount']) ?><?= $ftFeeVoided ? ' &middot; Voided' : '' ?>
            <?php else: ?>
            <?php $ftState = gjc_maintenance_fee_state_label($ftFee['status']); ?>
            <?= gjc_money($ftFee['fee_amount']) ?><?= $ftState !== '' ? ' &middot; ' . $ftEsc($ftState) : '' ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if (empty($ftMerchant)): ?>
<div class="uv-field">
    <div class="uv-field-ic"><i class="fa-solid fa-equals"></i></div>
    <div>
        <div class="uv-field-label">Total (Amount + Fee)</div>
        <div class="uv-field-value"><?= gjc_money($ftAmount + $ftFeeCharged) ?></div>
    </div>
</div>
<?php endif; ?>
