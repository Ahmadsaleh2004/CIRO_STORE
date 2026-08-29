<?php
/**
 * app/views/shared/table-empty-row.php
 * صفّ «لا توجد سجلات» داخل جدول أدمن فارغ.
 *
 * كانت الكتلة مكتوبة في خمسة جداول: backup · manage-admins/index ·
 * orders/index · product/index · users/index. أربعة منها متطابقة عدا
 * قيمة colspan والنص، والخامس يختلف في التباعد.
 *
 * المتغيرات:
 *   $emptyColspan  int     عدد الأعمدة (مطلوب — يختلف بين الجداول)
 *   $emptyMessage  string  النص المعروض
 *   $emptyPadding  string  صنف التباعد الرأسي: 'py-4' (افتراضي) أو 'py-5'
 *
 * ⚠️ $emptyMessage يُطبع كما هو بلا هروب: صفحة المنتجات تمرّر نصاً فيه
 * مصطلح البحث مُهرَّباً مسبقاً بـhtmlspecialchars. الهروب هنا كان
 * سيُظهر الكيانات حرفياً. مسؤولية المستدعي أن يُهرّب ما يأتي من
 * المستخدم — وقد فعلت الصفحة الوحيدة التي تفعل ذلك.
 *
 * ملاحظة على اللون: users/index كانت تستعمل صنف Bootstrap ‏.text-muted
 * بينما البقية تستعمل المتغيّر var(--muted-text). قِستُ الاثنين في
 * المتصفح في الوضعين الفاتح والداكن فأعطيا اللون نفسه بالضبط —
 * المشروع يعيد تعريف .text-muted على النغمة نفسها — فالتوحيد على
 * المتغيّر بلا أي تغيير مرئي.
 */

$emptyPadding = $emptyPadding ?? 'py-4';
?>
<tr>
    <td colspan="<?= (int)$emptyColspan ?>" class="text-center <?= htmlspecialchars($emptyPadding) ?> u-muted"><?= htmlspecialchars($emptyMessage ?? 'No records found.') ?></td>
</tr>
