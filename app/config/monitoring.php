<?php

/**
 * app/config/monitoring.php
 * تهيئة رصد الأخطاء (Sentry) ومعالِجات الالتقاط.
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا
 * ══════════════════════════════════════════════════════════════
 *
 * كان كل ما يحدث عند الخطأ سطراً في `storage/php-error.log` — ملفاً
 * لا يفتحه أحد. أي أن عطلاً في صفحة الدفع الساعة الثانية فجراً
 * يُكتشف حين يشتكي زبون، إن اشتكى.
 *
 * وهذا ليس افتراضاً: صفحة الدفع كانت **معطّلة كلياً** منذ كومِت
 * الأساس — تعارض أسماء حقول يجعل كل عنصر سلّة يسقط — ولم يلاحظ أحد.
 * لو كان الرصد قائماً لوصل التنبيه مع أوّل محاولة شراء.
 *
 * ══════════════════════════════════════════════════════════════
 * ثلاثة شروط للتشغيل — والغياب هو الوضع الافتراضي
 * ══════════════════════════════════════════════════════════════
 *
 * لا شيء هنا يعمل ما لم يوجد `SENTRY_DSN` في `.env`. فالمطوّر الذي
 * يستنسخ المستودع لا يحتاج حساباً ولا يدفع طلب شبكة واحداً، والملف
 * كلّه يصير سطر `return` واحداً.
 *
 * والاختبارات مستثناة صراحةً: `APP_ENV=testing` يمنع التهيئة. بلا
 * ذلك كانت كل استثناءات الاختبارات المقصودة تُرسَل إلى Sentry فتغرق
 * المشروع في ضجيج، وتُستهلك حصّة الحساب على أخطاء ليست أخطاء.
 *
 * وCLI مشمول عمداً: سكربتات scripts/ (المهاجر، طابور البريد) تعمل
 * بلا مراقب بشري — وهي أولى بالرصد لا أحقّ بالاستثناء.
 */

declare(strict_types=1);

/**
 * مفاتيح لا تغادر الخادم أبداً.
 *
 * ⚠️ هذه أهمّ قائمة في الملف. Sentry يرسل سياق الطلب مع كل خطأ، وسياق
 * الطلب يشمل `$_POST` — أي كلمات المرور وتوكنات CSRF وأكواد 2FA
 * ومفاتيح الاستعادة. وإرسالها إلى طرف ثالث ليس تسريباً محتملاً بل
 * تسريبٌ بالتصميم.
 *
 * `send_default_pii => false` أدناه تمنع الأساس (الكوكيز، عنوان IP،
 * ترويسات المصادقة). وهذه القائمة تغطّي ما يخصّ هذا المشروع تحديداً
 * ولا يعرفه المُرسِل الافتراضي.
 *
 * من يضيف حقلاً حسّاساً جديداً في أي نموذج مسؤول عن إضافته هنا.
 */
const MONITORING_SCRUB_KEYS = [
    'password',
    'password_confirmation',
    'confirm_password',
    'current_password',
    'new_password',
    'csrf_token',
    'token',
    'totp_code',
    'totp_secret',
    'code',
    'h-captcha-response',
    'secret',
    'authorization',
];

/**
 * ينظّف مصفوفة من القيم الحسّاسة — تعاودياً.
 *
 * التعاود ليس تزيّناً: جسم JSON قد يحمل الحقل داخل كائن متداخل،
 * وتنظيف المستوى الأوّل وحده يعطي إحساساً بالأمان بلا أمان.
 *
 * @param  array<string,mixed> $data
 * @return array<string,mixed>
 */
function monitoringScrub(array $data): array
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = monitoringScrub($value);
            continue;
        }

        if (in_array(strtolower((string) $key), MONITORING_SCRUB_KEYS, true)) {
            $data[$key] = '[scrubbed]';
        }
    }

    return $data;
}

/**
 * يهيّئ Sentry ويربط معالِجات الالتقاط — أو لا يفعل شيئاً.
 */
function initMonitoring(): void
{
    $dsn = env('SENTRY_DSN');

    if ($dsn === null || $dsn === '') {
        return;
    }

    // الاختبارات ترمي استثناءات عمداً. إرسالها يغرق المشروع في ضجيج
    // ويستهلك الحصّة على ما ليس عطلاً.
    //
    // القراءة من env() لا من ثابت APP_ENV: tests/phpstan-bootstrap.php
    // يعرّف الثابت بقيمة 'testing' كي يرى المحلّل الثوابت، فيستنتج أن
    // المقارنة صحيحة دائماً ويعتبرها خطأ. والقراءة من المصدر أصحّ
    // منطقياً أيضاً — لا تعتمد على أن config.php عرّف الثابت بعد.
    $environment = (string) (env('APP_ENV', 'production') ?? 'production');

    if ($environment === 'testing') {
        return;
    }

    if (!class_exists(\Sentry\SentrySdk::class)) {
        // الحزمة غير مثبَّتة (تركيب بلا composer install مثلاً).
        // الغياب لا يجوز أن يُسقط التطبيق: الرصد يخدم التشغيل ولا
        // يشترطه.
        error_log('SENTRY_DSN مضبوط لكن حزمة sentry/sentry غير مثبَّتة.');
        return;
    }

    \Sentry\init([
        'dsn'         => $dsn,
        'environment' => $environment,

        // ⚠️ false صراحةً لا اتّكالاً على الافتراضي: تمنع إرسال عنوان
        // IP والكوكيز وترويسات المصادقة. الكوكي هنا يحمل معرّف الجلسة،
        // ومن يملكه يملك الجلسة.
        'send_default_pii' => false,

        // تتبّع الأداء مُطفأ افتراضياً: يرسل عيّنة من **كل** طلب لا من
        // الأخطاء وحدها، فيستهلك الحصّة بسرعة بلا حاجة قائمة.
        'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', '0.0'),

        // آخر مصفاة قبل مغادرة البيانات للخادم.
        'before_send' => static function (\Sentry\Event $event): \Sentry\Event {
            $request = $event->getRequest();

            if ($request !== []) {
                if (isset($request['data']) && is_array($request['data'])) {
                    $request['data'] = monitoringScrub($request['data']);
                }
                // الرابط قد يحمل توكن استعادة أو تحقّق بريد في الـquery.
                if (isset($request['query_string']) && is_string($request['query_string'])) {
                    parse_str($request['query_string'], $parsed);
                    $request['query_string'] = http_build_query(monitoringScrub($parsed));
                }
                unset($request['cookies'], $request['headers']);
                $event->setRequest($request);
            }

            return $event;
        },
    ]);

    registerMonitoringHandlers();
}

/**
 * يربط الاستثناءات غير الملتقَطة والأخطاء القاتلة بـSentry.
 *
 * ── لماذا معالِجان لا واحد ───────────────────────────────────
 *
 * `set_exception_handler` لا يرى الأخطاء القاتلة التي ليست استثناءات:
 * نفاد الذاكرة، انتهاء المهلة، خطأ تحليل في ملف مُضمَّن. وهذه بالضبط
 * أعطال الإنتاج التي لا يراها أحد — لأنها تُنهي الطلب قبل أن يصل إلى
 * أي `catch`.
 *
 * `register_shutdown_function` مع `error_get_last` تلتقطها.
 *
 * ⚠️ كلاهما **يعيد الرمي/يترك السلوك كما هو**: الرصد يلاحظ ولا يغيّر.
 * ابتلاع الاستثناء هنا كان سيحوّل صفحة خطأ صريحة إلى صفحة بيضاء.
 */
function registerMonitoringHandlers(): void
{
    $previousException = set_exception_handler(null);
    set_exception_handler(static function (\Throwable $e) use ($previousException): void {
        \Sentry\captureException($e);

        if ($previousException !== null) {
            $previousException($e);
            return;
        }

        // السلوك الافتراضي لولا هذا المعالج: صفحة 500 نظيفة بلا أثر
        // للزائر، والأثر الكامل في السجلّ.
        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage());
        \App\Core\ErrorPage::serverError($e->getMessage(), 500);
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        \Sentry\captureException(new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        ));

        // الإرسال غير متزامن بطبعه؛ الإغلاق الصريح يضمن مغادرة الحدث
        // قبل أن تموت العملية. بدونه تُفقد أعطالٌ هي الأهمّ.
        \Sentry\SentrySdk::getCurrentHub()->getClient()?->flush();
    });
}
