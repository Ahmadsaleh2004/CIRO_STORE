<?php

namespace App\Core;

require_once __DIR__ . '/../helpers/auth_helper.php';

/**
 * AdminController — الكلاس الأب المشترك لكل كنترولرز لوحة الأدمن.
 *
 * يتحقق من تسجيل دخول الأدمن في الـ constructor — أي كنترولر يرث منه
 * محمي تلقائياً بدون الحاجة لاستدعاء requireAdminLogin() يدوياً.
 *
 * يوفر adminView() لعرض صفحات الأدمن مع الـ layout المشترك
 * (head.php + navbar.php + [view] + footer.php).
 */
abstract class AdminController extends Controller
{
    public function __construct()
    {
        startAdminSession();
        if (!isAdmin()) {
            header('Location: ' . URLROOT . '/admin/login');
            exit;
        }
    }

    /**
     * عرض view خاص بالأدمن مع الـ layout المشترك.
     *
     * يحقن تلقائياً: $adminName, $adminRole, $adminId, $csrf
     * بالإضافة لأي متغيرات مخصصة ممرَّرة عبر $data.
     *
     * @param string $view  اسم الـ view بدون المسار أو الامتداد
     *                      (يُبحث عنه بـ app/views/admin/<view>.php)
     * @param array  $data  متغيرات إضافية تُمرَّر للـ view
     */
    protected function adminView(string $view, array $data = []): void
    {
        extract($data);

        $adminName = $_SESSION['admin_name'] ?? 'Admin';
        $adminRole = getAdminRole();
        $adminId   = getCurrentAdminId();
        $csrf      = function_exists('generateCsrfToken')
            ? generateCsrfToken()
            : ($_SESSION['csrf_token'] ?? '');

        // عدّادات غير المقروء (طلبات/رسائل دعم) — تُحقن تلقائيًا بكل صفحات الأدمن
        // حتى يظهر البادج في الـ navbar بدون استدعاء يدوي من كل Controller
        $counters    = function_exists('getAdminUnreadCounters')
            ? getAdminUnreadCounters()
            : ['orders' => 0, 'messages' => 0];
        $newOrders   = $counters['orders'];
        $newMessages = $counters['messages'];

        require __DIR__ . '/../views/admin/inc/head.php';
        require __DIR__ . '/../views/admin/inc/navbar.php';
        require __DIR__ . '/../views/admin/' . $view . '.php';
        require __DIR__ . '/../views/admin/inc/footer.php';
    }

    /**
     * إرسال ملف CSV للتحميل — مشتركة بين كل كنترولرز الأدمن
     * (Admins/Users/Orders/Products...) لتفادي تكرار نفس الكود.
     *
     * @param string $filename اسم الملف عند التحميل (مع .csv)
     * @param array  $headers  أسماء الأعمدة (صف أول بالملف)
     * @param array  $rows     كل صف بيانات كـ array مسطّح بنفس ترتيب $headers
     */
    protected function sendCsv(string $filename, array $headers, array $rows): void
    {
        if (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM لدعم العربي بإكسل
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}
