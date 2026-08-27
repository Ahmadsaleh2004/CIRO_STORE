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

    /**
     * يرسل صفحة 403 كاملة ويوقف التنفيذ.
     *
     * وُجدت لنفس سبب notFound(): كان الرد على الرفض في BackupController
     * و AdminManageAdminsController هو
     * `http_response_code(403); die('Unauthorized — Root admin only (ID=1)')`
     * — نصّاً خاماً بلا <head> ولا لايوت ولا طريق رجوع. وهو يكشف قاعدة
     * الصلاحية للزائر بلا فائدة؛ الرسالة المعروضة الآن عامة والتفصيل
     * إلى السجل.
     *
     * @param string|null $logDetail تفصيل تشخيصي — إلى error_log وحده،
     *        لا يُطبع في المتصفح أبداً.
     * @param string|null $backUrl   وجهة زر الرجوع. الافتراضي جذر الموقع؛
     *        تمرّره صفحات الأدمن كي لا تُلقي الأدمن في واجهة المتجر.
     * @param string|null $backLabel نصّ زر الرجوع.
     */
    public static function forbidden(
        ?string $logDetail = null,
        ?string $backUrl = null,
        ?string $backLabel = null
    ): never {
        if ($logDetail !== null && $logDetail !== '') {
            error_log('[Cairo Store] 403: ' . $logDetail);
        }

        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
        }

        // متاحان للـview.
        //
        // ⚠️ الوجهة تُقيَّد بجذر الموقع عمداً. كل المستدعين اليوم يمرّرون
        // ثابتاً مبنياً على URLROOT، لكن التوقيع يقبل نصّاً — ومستدعٍ
        // لاحق يمرّر مدخلاً من المستخدم كان سيزرع `javascript:` في href.
        // htmlspecialchars في الـview يهرّب المحارف ولا يمنع مخطّطاً خبيثاً.
        $backUrl   = $backUrl   ?? URLROOT . '/';
        if (!str_starts_with($backUrl, URLROOT)) {
            error_log('[Cairo Store] 403: وجهة رجوع خارج الموقع رُفضت: ' . $backUrl);
            $backUrl = URLROOT . '/';
        }
        $backLabel = $backLabel ?? 'العودة للصفحة الرئيسية';

        // نفس احتياط notFound(): لو غاب ملف الصفحة نطبع بديلاً مضمّناً
        // بدل استدعاء view() — وهو تكرار محتمل داخل معالج خطأ.
        $page = APPROOT . '/views/errors/403.php';
        if (is_file($page)) {
            require $page;
        } else {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
               . '<title>403 — Access Denied</title></head><body>'
               . '<h1>403</h1><p>You do not have permission to access this page.</p>'
               . '<p><a href="' . htmlspecialchars($backUrl) . '">'
               . htmlspecialchars($backLabel) . '</a></p>'
               . '</body></html>';
        }

        exit;
    }

    /**
     * يرسل صفحة 500 كاملة ويوقف التنفيذ.
     *
     * وُجدت لسبب notFound() نفسه. كان فشل الاتصال بقاعدة البيانات يُعالج
     * في Database::__construct بـ
     *     die("خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage())
     * ورسالة PDO تحمل **اسم المضيف واسم القاعدة واسم المستخدم** حرفياً.
     * أي أن أول خطأ اتصال على الإنتاج كان يسلّم الزائرَ نصفَ بيانات
     * الدخول، بلا صفحة ولا كود حالة صحيح (die تُرجع 200).
     *
     * ثلاثة فروق عن أختيها:
     *
     *   1. **الوضع CLI**: سكربتات scripts/ تعمل على الطرفية بلا composer
     *      autoload — فطباعة صفحة HTML هناك بلا معنى، واستدعاء دوال
     *      الـlayout خطأ قاتل. الفرع CLI يطبع نصّاً خاماً على STDERR
     *      ويخرج بكود 1 كي يلتقطه أي سكربت مُشغِّل.
     *
     *   2. **الاحتياط المضمّن أوسع**: هذه الصفحة قد تُستدعى وقاعدة
     *      البيانات ساقطة، فلا تعتمد على أي شيء يقرأ منها. head-bare.php
     *      لا يلمس القاعدة (مفحوص)، لكن الاحتياط يبقى مكتفياً بذاته
     *      تماماً — بلا CSS خارجي ولا دوال هيلبرز.
     *
     *   3. **503 لا 500 عند فشل الاتصال**: الخدمة غير متاحة مؤقتاً لا
     *      «خطأ في الخادم». الفرق يهمّ محرّكات البحث وأدوات المراقبة.
     *
     * @param string|null $logDetail تفصيل تشخيصي — إلى error_log وحده،
     *        لا يُطبع في المتصفح أبداً.
     * @param int         $status    503 (غير متاح مؤقتاً) أو 500.
     */
    public static function serverError(?string $logDetail = null, int $status = 500): never
    {
        if ($logDetail !== null && $logDetail !== '') {
            error_log('[Cairo Store] ' . $status . ': ' . $logDetail);
        }

        // الطرفية: لا HTML ولا ترويسات. النصّ إلى STDERR وكود خروج غير صفري.
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "خطأ: تعذّر إكمال العملية. التفاصيل في سجلّ الأخطاء.
");
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            // لا تُخزَّن صفحة خطأ مؤقت في أي وسيط.
            header('Cache-Control: no-store');
            if ($status === 503) {
                header('Retry-After: 60');
            }
        }

        $page = APPROOT . '/views/errors/500.php';
        if (is_file($page)) {
            require $page;
        } else {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
               . '<meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>' . $status . ' — Service Unavailable</title></head>'
               . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:60px">'
               . '<h1>' . $status . '</h1>'
               . '<p>الخدمة غير متاحة مؤقتاً. حاول بعد قليل.</p>'
               . '</body></html>';
        }

        exit;
    }
}
