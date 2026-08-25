<?php
/**
 * app/views/shared/order-status-badge.php
 * بادج حالة الطلب — الألوان المعتمدة في لوحة التحكم.
 *
 * كانت خريطة الحالة→اللون مكتوبة في خمسة ملفات: أربعة في لوحة التحكم
 * (orders/index · orders/details · users/details · manage-admins/details)
 * وواحد في واجهة المتجر (account/my-info).
 *
 * المتغيرات:
 *   $orderStatus  string  قيمة العمود orders.status
 *   $badgeExtraClass string  أصناف تُضاف إلى الوسم: 'fs-6' لصفحة
 *                           تفاصيل الطلب، 'ms-2' لصفحة الزبون
 *   $badgeLabel   string  نص بديل للحالة (اتركه فارغاً للنص الافتراضي)
 *
 * ملاحظة تاريخية: كانت صفحة الزبون تعرض الطلب الملغى بـbg-secondary
 * (رمادي) بينما صفحات الأدمن الأربع تعرضه بـbg-danger (أحمر). أُبقي
 * الاختلاف في المرحلة 5 لأنه قرار تصميم لا إعادة هيكلة، ثم اختار صاحب
 * المشروع التوحيد على الأحمر — فصار الملغى أحمر في كل مكان.
 */

$badgeExtraClass = $badgeExtraClass ?? '';
$statusClass = match ($orderStatus) {
    'completed' => 'bg-success',
    'cancelled' => 'bg-danger',
    'taken'     => 'bg-primary',
    default     => 'bg-warning text-dark',
};

// النص الافتراضي: not_taken → "Not taken". صفحة قائمة الطلبات كانت
// تكتب "Not Taken" بتاء كبيرة؛ تُمرَّر عبر $badgeLabel للحفاظ عليها.
$label = ($badgeLabel ?? '') !== ''
    ? $badgeLabel
    : ucfirst(str_replace('_', ' ', $orderStatus));
?>
<span class="badge <?= $statusClass ?><?= $badgeExtraClass !== '' ? ' ' . $badgeExtraClass : '' ?>"><?= htmlspecialchars($label) ?></span>
