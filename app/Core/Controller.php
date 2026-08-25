<?php

namespace App\Core;

abstract class Controller
{
    /**
     * Render view template and assemble layouts (Head, Navbar, Footer).
     *
     * @param string $view (The main content view file, e.g., 'home')
     * @param array $data (Data passed to all views)
     * @return void
     *
     */
    protected function view(string $view, array $data = []): void
    {
        // نبضة نشاط المستخدم — مخنوقة إلى مرة كل 15 دقيقة (راجع
        // touchUserActivity في auth_helper.php). موضعها هنا مقصود:
        // view() تخصّ المتجر وحده، وبلوغها يعني أن الجلسة الصحيحة
        // بدأت بالفعل. لا تنقلها إلى الـbootstrap — هناك تبدأ جلسة
        // PHPSESSID قبل أن تضبط startAdminSession اسم جلسة الأدمن.
        touchUserActivity();

        // 1. استخراج المتغيرات من مصفوفة $data لتصبح جاهزة للطباعة
        extract($data);

        // 2. تجميع ملفات الفرونت إند النظيفة بالترتيب الصحيح

        // الجزء الأول: الـ Technical Head والـ <head> tags
        // تأكد من وجود ملف head.php داخل مجلد app/views/inc/
        require_once APPROOT . '/views/inc/head.php';

        // الجزء الثاني: القائمة العلوية المرئية (Navbar)
        // تأكد من وجود ملف navbar.php داخل مجلد app/views/inc/
        require_once APPROOT . '/views/inc/navbar.php';

        // الجزء الثالث: محتوى الصفحة الرئيسي (الذي يمرره الـ Controller)
        // قم بتعديل مسار الملف ليتوافق مع نظام تسمية الثوابت لديك (مثلاً APPROOT)
        $viewFile = APPROOT . '/views/' . $view . '.php';

        if (file_exists($viewFile)) {
            // تحميل المحتوى الرئيسي
            require_once $viewFile;
        } else {
            // للأمان والتجربة، سنقوم بعرض رسالة خطأ بسيطة، لكن الأفضل هو توجيه المستخدم لصفحة 404
            die("View file [{$viewFile}] not found!");
        }

        // الجزء الرابع: الـ <offcanvas> modals والـ <footer> وروابط الـ JS
        // تأكد من وجود ملف footer.php داخل مجلد app/views/inc/
        require_once APPROOT . '/views/inc/footer.php';
    }

    // ═══════════════════════════════════════════════════════════
    // استجابات JSON — مشتركة بين كل الكنترولرز
    // ═══════════════════════════════════════════════════════════
    //
    // كانت respond() منسوخة حرفياً في 16 كنترولر (نسختان تختلفان في
    // المسافات فقط). نُقلت هنا مرة واحدة: كنترولرز المتجر ترثها مباشرة،
    // وكنترولرز الأدمن عبر AdminController الذي يرث هذا الكلاس.

    /**
     * يطبع استجابة JSON موحّدة الشكل ويوقف التنفيذ.
     *
     * الشكل ثابت: {success, message, ...$extra}. الـfrontend يعتمد عليه
     * في js/core/utils.js وبقية ملفات features، فلا تُغيَّر أسماء المفاتيح.
     *
     * ملاحظة: لا يضبط رأس Content-Type — بعض النقاط تضبطه بنفسها قبل
     * الاستدعاء، وبعضها يستدعي respond() بعد إخراج بدأ فعلاً.
     */
    protected function respond(bool $success, string $message, array $extra = []): never
    {
        echo json_encode(
            array_merge(['success' => $success, 'message' => $message], $extra),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * اختصار لاستجابة فشل بلا بيانات إضافية.
     */
    protected function jsonError(string $message): never
    {
        $this->respond(false, $message);
    }
}
