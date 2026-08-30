<?php
/**
 * app/views/admin/branding/index.php — a fragment only.
 * The variables: $sliders, $csrf, $flashMsg, $flashErr (from AdminBrandingController).
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
        <?php // Built from $sliders in JavaScript at DOMContentLoaded, with new slides appended by addSlideBtn ?>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" id="saveBrandingBtn" class="btn btn-success" disabled>
            💾 Save Changes
        </button>
        <span class="text-muted small align-self-center d-none" id="brandingDirtyHint">
            You have unsaved changes.
        </span>
    </div>

</form>

<?php // Templates: cloned from JavaScript, never displayed directly ?>
<?php include __DIR__ . '/_templates.php'; ?>

<?php // The current sliders' data, handed to JavaScript ?>
<?= pageData(['_existingSlidersData' => $sliders]) ?>

<?php include __DIR__ . '/_product-picker-modal.php'; ?>