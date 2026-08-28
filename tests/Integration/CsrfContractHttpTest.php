<?php

namespace Tests\Integration;

use App\Core\Controller;
use PHPUnit\Framework\TestCase;

/**
 * عقد CSRF عبر HTTP — الحارس الدائم لعطل تكرّر **ثلاث مرّات**.
 *
 * القصة: js/core/csrf.js كان يكتشف فشل التوكن بـ
 *     message.startsWith('Invalid CSRF token')
 * فأي نقطة تصوغ رسالتها بشكل آخر تفقد إعادة المحاولة التلقائية بصمت.
 * حدث ذلك في WishlistController::notify ('Invalid session…') و
 * ContactController::send ('Invalid request…')، ونجت ستّ نقاط بالصدفة
 * وحدها لأن صياغتها بدأت بالبادئة نفسها.
 *
 * الحلّ كان error_code صريحاً. لكن الحلّ بلا اختبار يتآكل: تكفي نقطة
 * جديدة واحدة تستدعي respond() مباشرةً بدل beginJsonPost() ليعود
 * العطل. هذا الملف يمنع ذلك — يمسح **كل** نقاط POST من الراوتر نفسه،
 * فأي نقطة تُضاف غداً تدخل الفحص تلقائياً بلا أن يتذكّرها أحد.
 *
 * ⚠️ يحتاج خادم التطوير يعمل. يتخطّى نفسه بوضوح إن لم يكن كذلك، كي لا
 * يفشل CI على غياب خدمة بدل غياب صحّة.
 */
final class CsrfContractHttpTest extends TestCase
{
    /**
     * جذر الخادم الذي تُفحص نقاطه.
     *
     * قابل للضبط بمتغيّر البيئة TEST_BASE_URL كي يعمل الاختبار في
     * موضعين مختلفين تماماً: XAMPP محلياً على مسار فرعي
     * (/STORE/public)، وخادم PHP المدمج في CI على جذر منفذ
     * (http://127.0.0.1:8080). تثبيت المسار كان سيجعل الاختبار
     * يتخطّى نفسه في CI دائماً — أي حارس لا يحرس.
     */
    private static function base(): string
    {
        return rtrim(
            getenv('TEST_BASE_URL') ?: ($_ENV['TEST_BASE_URL'] ?? 'http://localhost/STORE/public'),
            '/'
        );
    }

    /**
     * نقاط POST عامة **لا** تتحقّق من CSRF — كل واحدة بسببها.
     *
     * القائمة قصيرة عمداً، وكل إضافة إليها قرار يُبرَّر لا سهو يُغتفر.
     */
    private const DOCUMENTED_EXEMPTIONS = [
        // صفحتان تعرضان HTML وتقبلان POST لنموذج فلترة/عرض، لا لتغيير حالة.
        '/product'  => 'صفحة عرض تقبل POST لنموذج الفلترة — لا تغيّر حالة',
        '/contact'  => 'صفحة عرض تقبل POST لإعادة ملء النموذج — الإرسال الفعلي على /contact/send',
        // قراءة محضة: تُرجع مخزون variants. لا تكتب شيئاً.
        '/cart/check-stock' => 'قراءة محضة للمخزون — لا تكتب، فلا حالة تُزوَّر',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (self::request('/', 'GET') === null) {
            $this->markTestSkipped('خادم التطوير لا يستجيب على ' . self::base());
        }

        self::clearThrottle();
    }

    /**
     * يصفّر عدّاد الخنق قبل كل حالة.
     *
     * هذا الملف يضرب كل نقاط POST من عنوان واحد بتوكن باطل — وهو
     * بالضبط النمط الذي يُفترض أن يوقفه Middleware::throttle. فبلا
     * تصفير يبدأ الاختبار بقياس عقد CSRF وينتهي بقياس الخنق: تردّ
     * النقاط 429 برسالة «محاولات كثيرة» بدل رمز csrf_invalid، فيفشل
     * الاختبار على سلوك صحيح.
     *
     * الحذف مباشر على القاعدة لا عبر Throttle::clear: تلك تمسح دلواً
     * واحداً لمصدر واحد، والمطلوب هنا أرضٌ نظيفة تماماً.
     */
    private static function clearThrottle(): void
    {
        try {
            \App\Core\Database::connect()->exec('DELETE FROM throttle_attempts');
        } catch (\Throwable $e) {
            // القاعدة غير متاحة — الاختبار سيتخطّى نفسه أو يفشل لسبب
            // أوضح من هذا. ابتلاع الاستثناء هنا يمنع رسالة مضلّلة.
        }
    }

    /** @return array{status:int, body:string}|null */
    private static function request(string $path, string $method = 'POST'): ?array
    {
        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $body === false ? null : ['status' => $status, 'body' => (string) $body];
    }

    /** يقرأ مسارات POST من الراوتر نفسه — لا قائمة يدوية تتقادم. */
    private static function postRoutes(): array
    {
        $index = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        preg_match_all('/->post\(\s*\'([^\']+)\'/', (string) $index, $m);

        // المسارات ذات المعاملات {id} تحتاج قيمة حقيقية — خارج نطاق هذا العقد.
        return array_values(array_filter($m[1], static fn ($p) => !str_contains($p, '{')));
    }

    /**
     * المسارات المحروسة بـauth في جدول المسارات.
     *
     * تُقرأ من public/index.php لا من قائمة يدوية: نقل الحراسة إلى
     * تعريف المسار جعل الحارس يسبق الكنترولر، فتغيّر ما تردّه هذه
     * النقاط على طلب غير مصادَق — والاختبار يجب أن يتبع المصدر لا أن
     * يحمل صورة قديمة عنه.
     *
     * @return list<string>
     */
    private static function authGuardedPaths(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

        preg_match_all(
            "/->post\(\s*'([^']+)'[^;]*?->middleware\('auth'\)/s",
            $src,
            $m
        );

        return array_values(array_unique($m[1]));
    }

    /**
     * كل نقطة JSON عامة ترفض الطلب بلا توكن، وترفضه **بالرمز الصريح**
     * لا بنصّ رسالة. الرمز هو ما يقرأه csrf.js.
     *
     * تُستثنى النقاط المحروسة بـauth: عندها المصادقة تسبق CSRF، وهذا
     * هو الترتيب الصحيح — فحص توكن يحمي جلسةً لا وجود لها بلا معنى،
     * ورسالة «توكن غير صالح» لزائر غير مسجّل تصف عرضاً لا سبباً.
     * تُغطّى في الاختبار التالي.
     */
    public function testEveryPublicJsonPostEndpointRejectsWithTheExplicitErrorCode(): void
    {
        $authGuarded = self::authGuardedPaths();
        $failures = [];
        $checked  = 0;

        foreach (self::postRoutes() as $path) {
            if (str_starts_with($path, '/admin/')) {
                continue; // تُغطّى أدناه — حارس الجلسة يسبق حارس CSRF
            }
            if (isset(self::DOCUMENTED_EXEMPTIONS[$path]) || in_array($path, $authGuarded, true)) {
                continue;
            }

            $checked++;
            $response = self::request($path);
            $json     = json_decode($response['body'] ?? '', true);

            if (!is_array($json)) {
                $failures[] = "{$path} — لم تُرجع JSON إطلاقاً";
                continue;
            }
            if (($json['error_code'] ?? null) !== Controller::ERR_CSRF_INVALID) {
                $failures[] = sprintf(
                    '%s — error_code = %s (متوقّع %s) · message: %s',
                    $path,
                    var_export($json['error_code'] ?? null, true),
                    Controller::ERR_CSRF_INVALID,
                    $json['message'] ?? '—'
                );
            }
        }

        $this->assertGreaterThan(8, $checked, 'المسح لم يجد نقاطاً كافية — تحقّق من قارئ الراوتر.');
        $this->assertSame(
            [],
            $failures,
            "نقاط لا تحترم عقد error_code (وهو ما يقرأه js/core/csrf.js):
  "
            . implode("
  ", $failures)
        );
    }

    /**
     * النقاط المحروسة بـauth تردّ **JSON بكود 401** لا تحويلاً إلى HTML.
     *
     * هذا ما كان عطلاً كامناً قبل نقل الحراسة إلى المسار:
     * Middleware::requireLogin كانت تحوّل بـ302 دائماً، بلا تفريق بين
     * صفحة كاملة ونقطة JSON. لم يظهر العطل لأنها كانت تُستدعى من داخل
     * جسم الفعل — أي بعد أن تكون beginJsonPost قد أنهت الطلب.
     *
     * ولحظة صار الحارس يسبق الكنترولر بدأ fetch في المتصفح يتلقّى صفحة
     * HTML كاملة ويحاول قراءتها JSON. أمسك ذلك هذا الاختبار.
     */
    public function testAuthGuardedJsonEndpointsAnswerWithJsonNotARedirect(): void
    {
        $failures = [];
        $checked  = 0;

        foreach (self::authGuardedPaths() as $path) {
            $checked++;
            $response = self::request($path);
            $json     = json_decode($response['body'] ?? '', true);

            if (!is_array($json)) {
                $failures[] = "{$path} — ردّت بغير JSON (كود {$response['status']})";
                continue;
            }
            if (($json['success'] ?? null) !== false) {
                $failures[] = "{$path} — success ليست false لطلب غير مصادَق";
                continue;
            }
            if ($response['status'] !== 401) {
                $failures[] = "{$path} — الكود {$response['status']} (متوقّع 401)";
            }
        }

        $this->assertGreaterThan(3, $checked, 'لم يُعثر على مسارات محروسة بـauth.');
        $this->assertSame(
            [],
            $failures,
            "نقاط محروسة بـauth لا تردّ JSON/401 على طلب غير مصادَق:
  " . implode("
  ", $failures)
        );
    }

    /**
     * السطح الإداري مغلق قبل CSRF.
     *
     * نقاط /admin/* لا تصل إلى فحص CSRF أصلاً لأن Middleware::requireAdmin
     * يسبقه — وهذا صحيح ومقصود (دفاع بالطبقات). ما يجب إثباته هنا أن أياً
     * منها **لا ينجح** بلا جلسة: لا success:true، ولا كود 200 يحمل عملاً
     * منجزاً.
     */
    public function testNoAdminPostEndpointSucceedsWithoutASession(): void
    {
        $leaks   = [];
        $checked = 0;

        foreach (self::postRoutes() as $path) {
            if (!str_starts_with($path, '/admin/')) {
                continue;
            }
            // نقاط الدخول نفسها يجب أن تكون متاحة بلا جلسة — وإلا استحال الدخول.
            if (in_array($path, ['/admin/login', '/admin/login/2fa', '/admin/forgot'], true)) {
                continue;
            }

            $checked++;
            $response = self::request($path);
            $json     = json_decode($response['body'] ?? '', true);

            if (is_array($json) && ($json['success'] ?? false) === true) {
                $leaks[] = "{$path} — أرجعت success:true بلا جلسة أدمن";
            }
        }

        $this->assertGreaterThan(20, $checked, 'المسح لم يغطِّ نقاط الأدمن.');
        $this->assertSame([], $leaks, "نقاط إدارية تعمل بلا مصادقة:\n  " . implode("\n  ", $leaks));
    }

    /**
     * الرمز نفسه ثابت. تغييره يكسر js/core/csrf.js صامتاً، فيُجمَّد هنا
     * بالقيمة الحرفية التي يبحث عنها المتصفح.
     */
    public function testTheErrorCodeConstantMatchesWhatTheBrowserLooksFor(): void
    {
        $this->assertSame('csrf_invalid', Controller::ERR_CSRF_INVALID);

        $clientSide = file_get_contents(dirname(__DIR__, 2) . '/public/js/core/csrf.js');
        $this->assertStringContainsString(
            'csrf_invalid',
            (string) $clientSide,
            'js/core/csrf.js لم يعد يذكر الرمز — طرفا العقد افترقا.'
        );
    }
}
