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
    private const BASE = 'http://localhost/STORE/public';

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
            $this->markTestSkipped('خادم التطوير لا يستجيب على ' . self::BASE);
        }
    }

    /** @return array{status:int, body:string}|null */
    private static function request(string $path, string $method = 'POST'): ?array
    {
        $ch = curl_init(self::BASE . $path);
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
     * كل نقطة JSON عامة ترفض الطلب بلا توكن، وترفضه **بالرمز الصريح**
     * لا بنصّ رسالة. الرمز هو ما يقرأه csrf.js.
     */
    public function testEveryPublicJsonPostEndpointRejectsWithTheExplicitErrorCode(): void
    {
        $failures = [];
        $checked  = 0;

        foreach (self::postRoutes() as $path) {
            if (str_starts_with($path, '/admin/')) {
                continue; // تُغطّى في الاختبار التالي — حارس الجلسة يسبق حارس CSRF
            }
            if (isset(self::DOCUMENTED_EXEMPTIONS[$path])) {
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

        $this->assertGreaterThan(10, $checked, 'المسح لم يجد نقاطاً كافية — تحقّق من قارئ الراوتر.');
        $this->assertSame(
            [],
            $failures,
            "نقاط لا تحترم عقد error_code (وهو ما يقرأه js/core/csrf.js):\n  "
            . implode("\n  ", $failures)
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
