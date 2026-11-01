<?php
/**
 * Partial: personal-details fields (sex, DOB, age, address) for profile forms.
 *
 * Requires:
 *   $pdRow   array  the user's `users` row
 *   $pdField string wrapper class for one field  ('pf-field' | 'pfield')
 *   $pdGrid  string wrapper class for a 2-up row ('pf-form-grid' | 'pgrid')
 *
 * Age is display-only (disabled, not submitted). Fields are paired or full-width.
 */
$pdRow   = $pdRow   ?? [];
$pdField = $pdField ?? 'pf-field';
$pdGrid  = $pdGrid  ?? 'pf-form-grid';

$pdEsc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Prefer submitted values so a validation error keeps the user's input.
$pdVal = static fn(string $key): string => $pdEsc($_POST[$key] ?? $pdRow[$key] ?? '');

$pdSex = (string) ($_POST['sex'] ?? $pdRow['sex'] ?? '');
$pdDob = (string) ($_POST['date_of_birth'] ?? $pdRow['date_of_birth'] ?? '');
$pdAge = gjc_age_from_dob($pdDob);
?>

<div class="<?= $pdEsc($pdField) ?>">
    <label for="pdSex">Sex</label>
    <select name="sex" id="pdSex">
        <option value="">Not specified</option>
        <?php foreach (gjc_sex_options() as $pdOption): ?>
        <option value="<?= $pdEsc($pdOption) ?>" <?= $pdSex === $pdOption ? 'selected' : '' ?>>
            <?= $pdEsc($pdOption) ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="<?= $pdEsc($pdGrid) ?>">
    <div class="<?= $pdEsc($pdField) ?>">
        <label for="pdDob">Date of Birth</label>
        <input type="date" name="date_of_birth" id="pdDob"
               value="<?= $pdEsc($pdDob) ?>"
               max="<?= date('Y-m-d') ?>">
        <small>&nbsp;</small>
    </div>

    <div class="<?= $pdEsc($pdField) ?>">
        <label for="pdAge">Age</label>
        <?php // No name attribute: this is never submitted. Age is worked out
              // from the date beside it, so there is nothing here to save. ?>
        <input type="text" id="pdAge" disabled
               value="<?= $pdAge !== null ? (int) $pdAge : '' ?>"
               placeholder="—">
        <small>Worked out from your date of birth.</small>
    </div>
</div>

<p class="pd-subhead">Address</p>

<div class="<?= $pdEsc($pdGrid) ?>">
    <div class="<?= $pdEsc($pdField) ?>">
        <label for="pdBarangay">Barangay</label>
        <input type="text" name="address_barangay" id="pdBarangay" maxlength="120"
               value="<?= $pdVal('address_barangay') ?>" placeholder="e.g. San Fernando Sur">
        <small>&nbsp;</small>
    </div>

    <div class="<?= $pdEsc($pdField) ?>">
        <label for="pdCity">City / Municipality</label>
        <input type="text" name="address_city" id="pdCity" maxlength="120"
               value="<?= $pdVal('address_city') ?>" placeholder="e.g. Cabiao">
        <small>&nbsp;</small>
    </div>
</div>

<div class="<?= $pdEsc($pdField) ?>">
    <label for="pdProvince">Province</label>
    <input type="text" name="address_province" id="pdProvince" maxlength="120"
           value="<?= $pdVal('address_province') ?>" placeholder="e.g. Nueva Ecija">
    <small>Barangay, municipality and province only — no house number needed.</small>
</div>

<script>
// Update the Age box as the date changes (display only).
(function () {
    var dob = document.getElementById("pdDob");
    var age = document.getElementById("pdAge");
    if (!dob || !age) {
        return;
    }
    dob.addEventListener("input", function () {
        var born = new Date(dob.value);
        if (!dob.value || isNaN(born.getTime())) {
            age.value = "";
            return;
        }
        var today = new Date();
        var years = today.getFullYear() - born.getFullYear();
        var m = today.getMonth() - born.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < born.getDate())) {
            years--;
        }
        age.value = years >= 0 ? years : "";
    });
})();
</script>
