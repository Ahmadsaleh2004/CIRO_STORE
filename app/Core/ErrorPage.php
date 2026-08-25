<?php

namespace App\Core;

/**
 * ErrorPage — المُصيّر الوحيد لصفحات الخطأ.
 *
 * وُجد هذا الكلاس لأن المشروع كان يملك **ثلاث** طرق مختلفة للرد على
 * «الصفحة غير موجودة»، ولا واحدة منها صفحة حقيقية:
 *
 *   1. Controller::view()  → die("View file [مسار الخادم الكامل] not found!")
 *   2. AdminAuthController → echo "View not found: {$viewPath}"
 *   3. Router::dispatch()  → echo "404 - Page Not Found"
 *
 * الأولى والثانية اختفتا في المرحلة 4، والثالثة هنا. الآن مسار واحد:
 * كود 404 صحيح · صفحة HTML كاملة بلغة المستخدم · وتفاصيل التشخيص إلى
 * سجل أخطاء PHP وحده.
 *
 * لماذا كلاس مستقل لا دالة في Controller؟ لأن Router لا يرث Controller
 * ولا يجب أن يرثه — وضع المُصيّر في الكلاس الأب كان سيجبر أحدهما على
 * تكرار النسخة، وهو بالضبط ما نحلّه.
 */
final class ErrorPage
{
    /**
     * يرسل صفحة 404 كاملة ويوقف التنفيذ.
     *
     * @param string|null $logDetail تفصيل تشخيصي للمطوّر — يذهب إلى
     *        error_log وحده ولا يُطبع أبداً في المتصفح. تسريب مسارات
     *        الخادم أو أسماء الملفات للزائر كشفٌ لبنية المشروع بلا فائدة.
     */
    public static function notFound(?string $logDetail = null): never
    {
        if ($logDetail !== null && $logDetail !== '') {
            error_log('[Cairo Store] 404: ' . $logDetail);
        }

        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
        }

        // احتياط: لو غاب ملف الـ404 نفسه نطبع صفحة صغيرة مضمّنة بدل
        // استدعاء view() — استدعاؤها من داخل معالج «view مفقود» تكرار
        // لا نهائي محتمل.
        $page = APPROOT . '/views/errors/404.php';
        if (is_file($page)) {
            require $page;
        } else {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
               . '<title>404 — Page Not Found</title></head><body>'
               . '<h1>404</h1><p>The page you requested could not be found.</p>'
               . '</body></html>';
        }

        exit;
    }
}
