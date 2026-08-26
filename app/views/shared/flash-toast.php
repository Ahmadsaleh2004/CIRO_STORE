<?php
/**
 * app/views/shared/flash-toast.php
 * رسالة عابرة تُعرَض كـtoast بعد تحميل الصفحة.
 *
 * كان النمط مكتوباً بمنطقه داخل الـviews في موضعين:
 * admin/branding/index.php (رسالتا $flashMsg و $flashErr) و
 * product/product_dit.php (رسالتا $reviewMsg و $reviewErr) — كلاهما
 * يكتب <script> يستمع لـDOMContentLoaded ويستدعي showToast.
 *
 * هنا لا سكربت إطلاقاً: نطبع عنصراً فارغاً يحمل النص والنوع في
 * data-*، و js/core/flash-toast.js يلتقطه ويعرضه. الـview صار يمرّر
 * بيانات لا منطقاً.
 *
 * المتغيرات:
 *   $toastMessage  string  النص (لا يُطبع شيء إن كان فارغاً)
 *   $toastType     string  'success' (افتراضي) | 'error' | 'info'
 *
 * لماذا عنصر لا سمة على <body>؟ لأن الصفحة قد تعرض رسالتين معاً
 * (نجاح وخطأ)، وكل استدعاء يطبع عنصره المستقل.
 */

if (!empty($toastMessage)):
    $toastType = $toastType ?? 'success';
?>
<div class="js-flash-toast d-none"
     data-toast-message="<?= htmlspecialchars($toastMessage, ENT_QUOTES) ?>"
     data-toast-type="<?= htmlspecialchars($toastType, ENT_QUOTES) ?>"></div>
<?php endif; ?>
