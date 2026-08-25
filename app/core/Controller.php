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
     * @edited by (Your Assistant) for MVC Integration.
     */
    protected function view(string $view, array $data = []): void
    {
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

    /**
     * Instantiate model class.
     * ... (دالة الـ model تبقى كما هي) ...
     */
    protected function model(string $model): object
    {
        $modelClass = "App\\Models\\" . $model;
        if (class_exists($modelClass)) {
            return new $modelClass();
        }

        die("Model class [{$modelClass}] not found!");
    }
}