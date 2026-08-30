<?php
/**
 * app/views/shared/flash-toast.php
 * A transient message shown as a toast after the page loads.
 *
 * The pattern used to be written with its logic inside the views in two places:
 * admin/branding/index.php (the $flashMsg and $flashErr messages) and
 * product/product_dit.php (the $reviewMsg and $reviewErr messages) — both writing a
 * <script> that listens for DOMContentLoaded and calls showToast.
 *
 * Here there is no script at all: an empty element is printed carrying the text and the
 * type in data-* attributes, and js/core/flash-toast.js picks it up and displays it. The
 * view now passes data rather than logic.
 *
 * The variables:
 *   $toastMessage  string  The text (nothing is printed if it is empty)
 *   $toastType     string  'success' (the default) | 'error' | 'info'
 *
 * Why an element rather than an attribute on <body>? Because a page may show two
 * messages at once (a success and an error), and each call prints its own element.
 */

if (!empty($toastMessage)):
    $toastType = $toastType ?? 'success';
?>
<div class="js-flash-toast d-none"
     data-toast-message="<?= htmlspecialchars($toastMessage, ENT_QUOTES) ?>"
     data-toast-type="<?= htmlspecialchars($toastType, ENT_QUOTES) ?>"></div>
<?php endif; ?>
