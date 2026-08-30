<?php
/**
 * app/views/admin/inc/export-csv-button.php
 * The shared Export CSV button — included by any admin page that has an export.
 *
 * The variables the parent page must set before including it:
 *   @var string $exportCsvUrl       The full export URL (including URLROOT)
 *   @var bool   $exportCsvOnlyRoleA (optional, defaults to true) — restricts the button to rank A
 *
 * Example use:
 *   <?php $exportCsvUrl = URLROOT . '/admin/admins/export-csv';
 *         include __DIR__ . '/../inc/export-csv-button.php'; ?>
 *
 * Example with the restriction lifted:
 *   <?php $exportCsvUrl = URLROOT . '/admin/users/export-csv';
 *         $exportCsvOnlyRoleA = false;
 *         include __DIR__ . '/../inc/export-csv-button.php'; ?>
 */
$exportCsvOnlyRoleA = $exportCsvOnlyRoleA ?? true;

if (!$exportCsvOnlyRoleA || isRoleA()):
?>
<a href="<?= htmlspecialchars($exportCsvUrl) ?>"
   download
   class="btn btn-success btn-sm btn-export-csv">📄 Export CSV</a>
<?php endif; ?>
