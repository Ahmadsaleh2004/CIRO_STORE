<?php
/**
 * app/views/shared/phone-input.php
 * حقل رقم الهاتف: قائمة رمز الدولة + الرقم المحلي.
 *
 * كانت هذه الكتلة — منطق التقسيم والماركب معاً — منسوخة في ملفين:
 * account/my-info.php (صفحة المستخدم) و admin/my-info.php (صفحة الأدمن).
 * ست وعشرون سطراً متطابقة، لا يفرّقها إلا مصدر القيمة ومعرّف الحقل.
 *
 * المتغيرات:
 *   $phoneValue    string  الرقم كما هو مخزَّن (رمز الدولة ملتصق بالرقم)
 *   $phoneInputId  string  قيمة id للحقل — تعتمد عليها CSS وJS كل صفحة
 *
 * لماذا partial لا helper؟ لأن ما يتكرر هو الماركب والمنطق معاً، والمنطق
 * لا يُستعمل خارج هذا الماركب — الكنترولر يستقبل الحقلين ويدمجهما بنفسه
 * ولا يحتاج قائمة الرموز إطلاقاً.
 */

$phoneValue   = (string)($phoneValue ?? '');
$phoneInputId = $phoneInputId ?? 'phoneInput';

// الرموز المدعومة، بالترتيب الذي تظهر به في القائمة
$countryPrefixes = ['+962','+966','+971','+20','+965','+974','+973','+968','+1','+44','+90','+49'];

// افصل رمز الدولة عن الرقم المحلي. أول تطابق يفوز، ولهذا يهمّ الترتيب:
// '+962' يسبق '+96' لو أُضيف يوماً، وإلا التقط الأقصرُ الأطولَ.
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
