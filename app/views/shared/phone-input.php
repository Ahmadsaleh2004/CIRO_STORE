<?php
/**
 * app/views/shared/phone-input.php
 * The phone number field: a country-code list plus the local number.
 *
 * This block — the splitting logic and the markup together — used to be copied into two
 * files: account/my-info.php (the user's page) and admin/my-info.php (the admin's).
 * Twenty-six identical lines, differing only in where the value came from and the
 * field's id.
 *
 * The variables:
 *   $phoneValue    string  The number as stored (the country code joined to the number)
 *   $phoneInputId  string  The field's id — each page's CSS and JavaScript depend on it
 *
 * Why a partial rather than a helper? Because what repeats is the markup and the logic
 * together, and the logic is not used outside this markup — the controller receives the
 * two fields and joins them itself, and needs the list of codes not at all.
 */

$phoneValue   = (string)($phoneValue ?? '');
$phoneInputId = $phoneInputId ?? 'phoneInput';

// The supported codes, in the order they appear in the list
$countryPrefixes = ['+962','+966','+971','+20','+965','+974','+973','+968','+1','+44','+90','+49'];

// Split the country code from the local number. The first match wins, which is why the
// order matters: '+962' comes before '+96' should that ever be added, or the shorter
// would swallow the longer.
$detectedCode   = '';
$localPhonePart = $phoneValue;
foreach ($countryPrefixes as $pfx) {
    if (str_starts_with($phoneValue, $pfx)) {
        $detectedCode   = $pfx;
        $localPhonePart = substr($phoneValue, strlen($pfx));
        break;
    }
}
?>
<div class="input-group">
    <select name="phone_country_code" class="form-select phone-code-select">
        <?php foreach ($countryPrefixes as $pfx): ?>
        <?php // @escaping-safe: $countryPrefixes is a literal array in this file ?>
        <option value="<?= $pfx ?>" <?= $detectedCode === $pfx ? 'selected' : '' ?>><?= $pfx ?></option>
        <?php endforeach; ?>
    </select>
    <input type="tel"
           id="<?= htmlspecialchars($phoneInputId) ?>"
           name="phone_local"
           placeholder=" "
           value="<?= htmlspecialchars($localPhonePart) ?>"
           class="form-control"
           autocomplete="tel">
</div>
