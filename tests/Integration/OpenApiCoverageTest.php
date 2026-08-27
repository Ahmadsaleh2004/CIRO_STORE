<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * بوّابة المواصفة — تحوّل قياساً لحظياً إلى ضمان دائم.
 *
 * كانت التغطية 103 من 104 نقطة. الرقم جيّد، لكنه لقطة: تكفي نقطة
 * واحدة تُضاف إلى public/index.php بلا سمة OA ليصير 103 من 105، ولا
 * شيء يخبر أحداً. هذا الاختبار يجعل الفجوة تفشل البناء لا تمرّ صامتة.
 *
 * يقرأ الطرفين من مصدريهما الحقيقيين — الراوتر من index.php والمواصفة
 * من openapi.yaml — فلا قائمة يدوية تتقادم.
 */
final class OpenApiCoverageTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * مسارات الراوتر: ['get /products', 'post /checkout', ...]
     *
     * @return list<string>
     */
    private static function routerOperations(): array
    {
        $index = (string) file_get_contents(self::root() . '/public/index.php');
        preg_match_all("/->(get|post)\(\s*'([^']+)'/", $index, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $hit) {
            $out[] = strtolower($hit[1]) . ' ' . $hit[2];
        }

        return array_values(array_unique($out));
    }

    /**
     * عمليات المواصفة بالشكل نفسه.
     *
     * قراءة سطرية لا محلّل YAML: المشروع لا يحمل ext-yaml ولا مكتبة
     * تحليل، وإضافة اعتمادية من أجل اختبار واحد ثمن أعلى من قيمته.
     * البنية التي نقرأها ثابتة لأن swagger-php هو من يولّدها.
     *
     * @return list<string>
     */
    private static function specOperations(): array
    {
        $lines = file(self::root() . '/public/docs/openapi.yaml', FILE_IGNORE_NEW_LINES);
        $out = [];
        $path = null;
        $inPaths = false;

        foreach ($lines as $line) {
            if (preg_match('/^paths:/', $line)) {
                $inPaths = true;
                continue;
            }
            if (!$inPaths) {
                continue;
            }
            // بداية قسم من المستوى الأعلى (components، tags…) تُنهي paths.
            if (preg_match('/^[a-z]/i', $line)) {
                break;
            }
            if (preg_match("/^  '?(\/[^:']*)'?:\s*$/", $line, $m)) {
                $path = $m[1];
                continue;
            }
            if ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m)) {
                $out[] = $m[1] . ' ' . $path;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * كل مسار في الراوتر موثّق.
     *
     * المسارات ذات المعاملات ({id}) مستثناة: swagger-php يكتبها بصيغة
     * قد تختلف عن صيغة الراوتر، والمقارنة النصّية بينهما تنتج ضجيجاً لا
     * معلومة.
     */
    public function testEveryRouterOperationIsDocumented(): void
    {
        $router = array_filter(
            self::routerOperations(),
            static fn (string $op): bool => !str_contains($op, '{')
        );

        $missing = array_values(array_diff($router, self::specOperations()));

        $this->assertGreaterThan(90, count($router), 'قارئ الراوتر لم يجد مسارات كافية.');
        $this->assertSame(
            [],
            $missing,
            "نقاط مسجَّلة في public/index.php وغائبة عن openapi.yaml.\n"
            . "أضف سمة #[OA\\Get] أو #[OA\\Post] ثم شغّل `composer docs:generate`:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * والعكس: لا عملية موثّقة بلا راوت.
     *
     * توثيق نقطة غير موجودة أسوأ من عدم توثيقها — يبني عليها من يقرأ
     * المواصفة ثم يكتشف 404 وقت التشغيل.
     */
    public function testNoDocumentedOperationLacksARoute(): void
    {
        $spec = array_filter(
            self::specOperations(),
            static fn (string $op): bool => !str_contains($op, '{')
        );

        $orphans = array_values(array_diff($spec, self::routerOperations()));

        $this->assertSame(
            [],
            $orphans,
            "عمليات موثّقة بلا راوت مقابل — تصف واجهة لا وجود لها:\n  " . implode("\n  ", $orphans)
        );
    }

    /**
     * المواصفة محدَّثة مقابل الشيفرة.
     *
     * openapi.yaml مولَّد ومتتبَّع في git معاً، وهذا يعني أنه قد يتخلّف:
     * يعدّل أحدهم سمة OA وينسى `composer docs:generate`، فيبقى الملف
     * المرفوع يصف نسخة قديمة. مقارنة عدد العمليات تمسك أوضح صور هذا
     * التخلّف — إضافة نقطة أو حذفها بلا إعادة توليد.
     */
    public function testSpecOperationCountMatchesTheRouter(): void
    {
        $router = array_filter(
            self::routerOperations(),
            static fn (string $op): bool => !str_contains($op, '{')
        );

        $this->assertCount(
            count($router),
            self::specOperations(),
            'عدد عمليات المواصفة لا يطابق الراوتر — شغّل `composer docs:generate`.'
        );
    }

    /**
     * المكوّنات المشتركة موجودة ومُشار إليها فعلاً.
     *
     * المواصفة كانت تحمل صفر schema وصفر $ref: كل عملية تصف جسمها
     * بأسطر مضمّنة تخصّها وحدها، فتتفرّق الصياغات كلما عُدِّلت واحدة.
     * هذا الاختبار يمنع الانزلاق إلى تلك الحالة مرّة أخرى.
     */
    public function testSharedComponentsExistAndAreReferenced(): void
    {
        $yaml = (string) file_get_contents(self::root() . '/public/docs/openapi.yaml');

        foreach (['ApiResponse', 'ApiError', 'Product', 'Order', 'Admin', 'CsrfToken'] as $schema) {
            $this->assertStringContainsString(
                "    {$schema}:",
                $yaml,
                "المخطّط المشترك {$schema} غائب عن components/schemas."
            );
        }

        foreach (['CsrfFailure', 'SessionExpired', 'PermissionDenied', 'ServiceUnavailable'] as $response) {
            $this->assertStringContainsString(
                "    {$response}:",
                $yaml,
                "الاستجابة المشتركة {$response} غائبة عن components/responses."
            );
        }

        $refCount = substr_count($yaml, '$ref:');
        $this->assertGreaterThan(
            100,
            $refCount,
            "عدد \$ref هبط إلى {$refCount} — العمليات تعود إلى وصف أجسامها بأسطر مضمّنة."
        );
    }

    /**
     * رمز CSRF موثّق في المواصفة.
     *
     * العقد بين الخادم وjs/core/csrf.js يجب أن يكون مقروءاً ممّن يقرأ
     * المواصفة وحدها. غيابه منها يعني أن مستهلكاً جديداً للـAPI سيعيد
     * اكتشاف الخطأ نفسه الذي كلّف هذا المشروع ثلاث دورات.
     */
    public function testCsrfErrorCodeIsDocumented(): void
    {
        $yaml = (string) file_get_contents(self::root() . '/public/docs/openapi.yaml');

        $this->assertStringContainsString('csrf_invalid', $yaml, 'رمز فشل CSRF غير موثّق في المواصفة.');
    }
}
