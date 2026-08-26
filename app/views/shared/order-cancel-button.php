<?php
/**
 * app/views/shared/order-cancel-button.php
 * زر إلغاء/حذف الطلب — مشترك بين واجهة المستخدم (my-info.php) ولوحة الأدمن (order-details.php).
 * المتغيرات المطلوبة قبل include:
 *   $orderId        — رقم الطلب
 *   $orderContext   — 'user' | 'admin'  (يتحكم بنص الزر ونقطة الوصول المستدعاة)
 */
$orderContext = $orderContext ?? 'user';
$endpoint     = $orderContext === 'admin'
    ? URLROOT . '/admin/orders/delete'
    : URLROOT . '/checkout/cancel-order';
?>
<button type="button"
        class="btn btn-sm btn-outline-danger order-cancel-btn"
        data-order-id="<?= (int)$orderId ?>"
        data-context="<?= $orderContext ?>"
        data-endpoint="<?= $endpoint ?>">
    🗑️ <?= $orderContext === 'admin' ? 'Delete Order' : 'Cancel Order' ?>
</button>