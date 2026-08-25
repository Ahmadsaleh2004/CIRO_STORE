<?php
/**
 * app/views/shared/order-status-badge.php
 * بادج حالة الطلب — الألوان المعتمدة في لوحة التحكم.
 *
 * كانت خريطة الحالة→اللون مكتوبة في أربعة ملفات أدمن:
 * orders/index · orders/details · users/details · manage-admins/details.
 *
 * المتغيرات:
 *   $orderStatus  string  قيمة العمود orders.status
 *   $badgeSize    string  ''|'fs-6'  — صفحة تفاصيل الطلب تستعمل الأكبر
 *   $badgeLabel   string  نص بديل للحالة (اتركه فارغاً للنص الافتراضي)
 *
 * ⚠️ صفحة المستخدم account/my-info.php **لا** تستعمل هذا الـpartial عمداً:
 * هي تعرض الطلب الملغى بـbg-secondary (رمادي) بينما كل صفحات الأدمن
 * تعرضه بـbg-danger (أحمر). التوحيد الأعمى كان سيغيّر ما يراه الزبون،
 * وهو قرار تصميم لا إعادة هيكلة. التفاصيل في تقرير المرحلة 5.
 */

$badgeSize  = $badgeSize ?? '';
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
<span class="badge <?= $statusClass ?><?= $badgeSize !== '' ? ' ' . $badgeSize : '' ?>"><?= htmlspecialchars($label) ?></span>
