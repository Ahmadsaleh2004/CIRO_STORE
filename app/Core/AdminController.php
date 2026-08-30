<?php

namespace App\Core;

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
     * تجميع الـlayout نفسه صار في Controller::view() — هنا يبقى ما يخصّ
     * لوحة التحكم وحدها: حقن متغيرات الأدمن، وبادئة المسار admin/.
     *
     * يحقن تلقائياً: $adminName, $adminRole, $adminId, $csrf,
     * $newOrders, $newMessages — بالإضافة لأي متغيرات ممرَّرة عبر $data.
     *
     * @param string $view  اسم الـ view بدون المسار أو الامتداد
     *                      (يُبحث عنه بـ app/views/admin/<view>.php)
     * @param array<string, mixed> $data متغيرات إضافية تُمرَّر للـ view
     */
    protected function adminView(string $view, array $data = []): void
    {
        // عدّادات غير المقروء (طلبات/رسائل دعم) — تُحقن تلقائيًا بكل صفحات الأدمن
        // حتى يظهر البادج في الـ navbar بدون استدعاء يدوي من كل Controller
        $counters = getAdminUnreadCounters();

        // الكتابة فوق $data لا بعد extract: هذا هو السلوك القديم بالحرف —
        // كان extract($data) يسبق هذه الإسنادات، فتغلب هي على أي مفتاح
        // بنفس الاسم قادم من الكنترولر.
        $data['adminName']   = $_SESSION['admin_name'] ?? 'Admin';
        $data['adminRole']   = getAdminRole();
        $data['adminId']     = getCurrentAdminId();
        $data['csrf']        = generateCsrfToken();
        $data['newOrders']   = $counters['orders'];
        $data['newMessages'] = $counters['messages'];

        $this->view('admin/' . $view, $data, 'admin');
    }

    /**
     * إرسال ملف CSV للتحميل — مشتركة بين كل كنترولرز الأدمن
     * (Admins/Users/Orders/Products...) لتفادي تكرار نفس الكود.
     *
     * @param string $filename اسم الملف عند التحميل (مع .csv)
     * @param list<string> $headers أسماء الأعمدة (صف أول بالملف)
     * @param array<array-key, array<array-key, mixed>> $rows كل صف بيانات كـ array مسطّح بنفس ترتيب $headers
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
