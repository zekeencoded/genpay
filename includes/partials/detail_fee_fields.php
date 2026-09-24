<?php
/**
 * Maintenance-fee rows for a payment/transfer detail page: plain .uv-field rows
 * dropped into the page's existing details grid — Maintenance Fee, then the
 * Total (amount + fee). Shared by the admin, student, parent and merchant
 * view_transaction.php pages.
 *
 * The fee is billed to tuition, never taken from the wallet, so the state text
 * says so. A voided fee (sale returned) is shown as voided and left out of the
 * total. The merchant is only ever credited the sale amount, so their page shows
 * the fee for information and no combined total.
 *
 * Expects:
 *   $ftFee         array|null  from gjc_maintenance_fee_for_reference() — null renders nothing
 *   $ftAmount      float       the payment/transfer amount
 *   $ftShowAmount  bool        also print an Amount row (pages whose grid lacks one)
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
        <div class="uv-field-label"><?= !empty($ftMerchant) ? 'Student Maintenance Fee' : 'Maintenance Fee' ?></div>
        <div class="uv-field-value">
            <?= gjc_money($ftFee['fee_amount']) ?> &middot; <?= $ftEsc(gjc_maintenance_fee_state_label($ftFee['status'])) ?><?= !empty($ftMerchant) ? ' &middot; not deducted from your sale' : '' ?>
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
