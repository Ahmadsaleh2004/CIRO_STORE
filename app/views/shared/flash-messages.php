<?php
/**
 * app/views/shared/flash-messages.php
 * رسالتا النجاح والخطأ العابرتان (flash) أعلى الصفحة.
 *
 * كانت هذه الكتلة منسوخة **حرفياً** في خمسة ملفات: admin/backup ·
 * admin/manage-admins/index · admin/orders/index · admin/product/index ·
 * admin/users/index — ثلاثة عشر سطراً متطابقة بايت-ببايت في كل واحد.
 *
 * المتغيرات — كلاهما اختياري، ولا يُطبع شيء إن كانا فارغين:
 *   $flashMsg  string  رسالة نجاح (alert-success)
 *   $flashErr  string  رسالة خطأ  (alert-danger)
 *
 * الهروب هنا لا في المستدعي: الرسائل تأتي من الجلسة وقد تحمل نصاً
 * أدخله المستخدم (اسم منتج، بريد، سبب رفض).
 */
?>
<?php if (!empty($flashMsg)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flashMsg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($flashErr)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flashErr) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
