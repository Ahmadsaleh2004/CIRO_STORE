<?php
/**
 * app/views/admin/inc/export-csv-button.php
 * زر Export CSV مشترك — يُضمَّن بأي صفحة أدمن عندها تصدير.
 *
 * المتغيرات المطلوبة من الصفحة الأم قبل include:
 *   @var string $exportCsvUrl       الرابط الكامل للتصدير (مع URLROOT)
 *   @var bool   $exportCsvOnlyRoleA (اختياري، افتراضي true) — يحصر الزر بـ Role A
 *
 * مثال الاستخدام:
 *   <?php $exportCsvUrl = URLROOT . '/admin/admins/export-csv';
 *         include __DIR__ . '/../inc/export-csv-button.php'; ?>
 *
 * مثال مع تجاوز القيد:
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
