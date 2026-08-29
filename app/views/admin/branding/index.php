<?php
/**
 * app/views/admin/branding/index.php — fragment فقط
 * المتغيرات: $sliders, $csrf, $flashMsg, $flashErr (من AdminBrandingController)
 */
?>
 
<div class="admin-page-header">
    <h1>🎬 Manage Slider</h1>
</div>

<?php if (!empty($flashMsg) || !empty($flashErr)): ?>
<?php
$toastMessage = $flashMsg ?? '';
$toastType    = 'success';
require APPROOT . '/views/shared/flash-toast.php';
$toastMessage = $flashErr ?? '';
$toastType    = 'error';
require APPROOT . '/views/shared/flash-toast.php';
?>
<?php endif; ?>

<form id="brandingForm"
      method="POST"
      action="<?= URLROOT ?>/admin/branding/save"
      enctype="multipart/form-data"
      novalidate>

    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Slides</h5>
        <button type="button" class="btn btn-outline-success btn-sm" id="addSlideBtn">
            ➕ Add Slide
        </button>
    </div>

    <div id="slidesContainer">
        <?php // تُبنى بـ JS من $sliders عند DOMContentLoaded، وتُضاف عليها شرائح جديدة بـ addSlideBtn ?>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" id="saveBrandingBtn" class="btn btn-success" disabled>
            💾 Save Changes
        </button>
        <span class="text-muted small align-self-center" id="brandingDirtyHint" class="d-none">
            You have unsaved changes.
        </span>
    </div>

</form>

<?php // Templates: تُستنسخ بـ JS، لا تُعرض مباشرة ?>
<?php include __DIR__ . '/_templates.php'; ?>

<?php // بيانات السلايدرات الحالية محقونة لـ JS ?>
<?= pageData(['_existingSlidersData' => $sliders]) ?>

<?php include __DIR__ . '/_product-picker-modal.php'; ?>