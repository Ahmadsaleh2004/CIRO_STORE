<?php

namespace Tests\Unit;

use App\Services\SliderFormParser;
use PHPUnit\Framework\TestCase;

/**
 * SliderFormParser — التحقق الذي لم يكن قابلاً للاختبار.
 *
 * هذه الفحوص هي المبرِّر الأوّل لاستخراج الخدمة. كان المنطق نفسه داخل
 * AdminBrandingController::save، وكل مسار خطأ فيه ينتهي بـheader()
 * وexit — أي أن اختباره من PHPUnit يعني إنهاء العملية. النتيجة أن
 * ثمانية مسارات رفض لم يكن أيٌّ منها مغطّى.
 *
 * ⚠️ لا يُختبَر الرفع هنا: move_uploaded_file ترفض أي ملف لم يصل عبر
 * رفع HTTP حقيقي، فمسار الرفع مغطّى في ImageUploadTest. المُختبَر هو
 * القرار: ما يُقبل، وما يُرفض، وبأي رسالة.
 */
final class SliderFormParserTest extends TestCase
{
    private const DIR = '/tmp/does-not-matter';

    /** شريحة صالحة واحدة بصورة موجودة مسبقاً. */
    /**
     * @return list<array<string, mixed>>
     */
    private function validSlides(): array
    {
        return [
            [
                'items' => [
                    [
                        'active_mode'           => 'manual',
                        'existing_manual_image' => 'images/old.jpg',
                    ],
                ],
            ],
        ];
    }

    public function testAValidSlideIsPrepared(): void
    {
        $r = SliderFormParser::parse($this->validSlides(), [], self::DIR);

        $this->assertNull($r['error']);
        $this->assertCount(1, $r['slides']);
        $this->assertSame('images/old.jpg', $r['slides'][0]['items'][0]['manual_image_path']);
        $this->assertSame(['images/old.jpg'], $r['images']);
        $this->assertSame([], $r['uploaded']);
    }

    public function testAnEmptyFormIsRejected(): void
    {
        $r = SliderFormParser::parse([], [], self::DIR);

        $this->assertSame('Please add at least one slide before saving.', $r['error']);
        $this->assertSame([], $r['slides']);
    }

    public function testTooManySlidesAreRejected(): void
    {
        $slides = array_fill(0, SliderFormParser::MAX_SLIDES + 1, $this->validSlides()[0]);

        $r = SliderFormParser::parse($slides, [], self::DIR);

        $this->assertStringContainsString('Too many slides', (string) $r['error']);
    }

    public function testTooManyImagesInOneSlideAreRejected(): void
    {
        $item   = $this->validSlides()[0]['items'][0];
        $slides = [['items' => array_fill(0, SliderFormParser::MAX_ITEMS_PER_SLIDE + 1, $item)]];

        $r = SliderFormParser::parse($slides, [], self::DIR);

        $this->assertStringContainsString('too many images', (string) $r['error']);
    }

    /**
     * شريحة بلا صور تُتجاهَل بصمت — لا تُرفض.
     *
     * الواجهة تضيف شريحة فارغة عند الضغط على «أضف»، فرفضها يمنع الحفظ
     * على من أضاف واحدة ثم عدل عنها.
     */
    public function testAnEmptySlideIsSkippedNotRejected(): void
    {
        $r = SliderFormParser::parse(
            [['items' => []], $this->validSlides()[0]],
            [],
            self::DIR
        );

        $this->assertNull($r['error']);
        $this->assertCount(1, $r['slides']);
    }

    public function testAFormOfOnlyEmptySlidesIsRejected(): void
    {
        $r = SliderFormParser::parse([['items' => []], ['items' => []]], [], self::DIR);

        $this->assertStringContainsString('at least one valid slide', (string) $r['error']);
    }

    public function testProductModeWithoutAProductIsRejected(): void
    {
        $r = SliderFormParser::parse(
            [['items' => [['active_mode' => 'product', 'product_id' => 0]]]],
            [],
            self::DIR
        );

        $this->assertStringContainsString('must have a product selected', (string) $r['error']);
    }

    public function testManualModeWithoutAnImageIsRejected(): void
    {
        $r = SliderFormParser::parse(
            [['items' => [['active_mode' => 'manual']]]],
            [],
            self::DIR
        );

        $this->assertStringContainsString('must have a product selected', (string) $r['error']);
    }

    /**
     * أي وضع غير 'product' يُعامَل كـ'manual'.
     *
     * القيمة تأتي من الفورم فقد تكون أي شيء. الافتراضي الآمن مهمّ: وضع
     * غير معروف يجب ألّا يتخطّى شرطَي الإلزام كليهما.
     */
    public function testAnUnknownModeFallsBackToManualAndIsStillValidated(): void
    {
        $r = SliderFormParser::parse(
            [['items' => [['active_mode' => 'nonsense', 'product_id' => 5]]]],
            [],
            self::DIR
        );

        $this->assertStringContainsString('must have a product selected', (string) $r['error']);
    }

    // ── الروابط الخطرة ───────────────────────────────────────

    /**
     * @return list<array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            ['javascript:alert(1)'],
            ['JaVaScRiPt:alert(1)'],
            ['  javascript:alert(1)'],
            ["java\tscript:alert(1)"],
            ["java\nscript:alert(1)"],
            ['data:text/html,<script>alert(1)</script>'],
            ['vbscript:msgbox(1)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeUrls')]
    public function testAnUnsafeLinkIsRejected(string $url): void
    {
        $r = SliderFormParser::parse(
            [['items' => [[
                'active_mode'           => 'manual',
                'existing_manual_image' => 'images/x.jpg',
                'manual_link_url'       => $url,
            ]]]],
            [],
            self::DIR
        );

        $this->assertStringContainsString('Unsafe link URL', (string) $r['error'], "قُبل رابط خطر: {$url}");
    }

    public function testOrdinaryLinksPass(): void
    {
        foreach (['https://example.test/x', '/products?id=3', 'mailto:a@b.test', ''] as $url) {
            $this->assertFalse(SliderFormParser::isUnsafeUrl($url), "رُفض رابط سليم: {$url}");
        }

        $this->assertFalse(SliderFormParser::isUnsafeUrl(null));
    }

    /**
     * قارئ $_FILES يفهم البنية المقلوبة التي ينتجها PHP.
     *
     * الخصائص الخمس تُقرأ من خمسة مسارات متوازية لا من مصفوفة واحدة —
     * وهذا أكثر ما يُخطئ فيه من يلمس هذا الكود.
     */
    public function testTheFileReaderUnderstandsPhpsInvertedStructure(): void
    {
        $files = [
            'name'     => [0 => ['items' => [1 => ['manual_image' => 'a.jpg']]]],
            'type'     => [0 => ['items' => [1 => ['manual_image' => 'image/jpeg']]]],
            'tmp_name' => [0 => ['items' => [1 => ['manual_image' => '/tmp/phpABC']]]],
            'error'    => [0 => ['items' => [1 => ['manual_image' => UPLOAD_ERR_OK]]]],
            'size'     => [0 => ['items' => [1 => ['manual_image' => 1234]]]],
        ];

        $entry = SliderFormParser::extractFileEntry($files, 0, 1);

        $this->assertSame('/tmp/phpABC', $entry['tmp_name']);
        $this->assertSame('a.jpg', $entry['name']);
        $this->assertSame(UPLOAD_ERR_OK, $entry['error']);
        $this->assertSame(1234, $entry['size']);
    }

    public function testTheFileReaderReturnsNullWhenNoFileWasSent(): void
    {
        $this->assertNull(SliderFormParser::extractFileEntry([], 0, 0));
        $this->assertNull(SliderFormParser::extractFileEntry(
            ['tmp_name' => [0 => ['items' => [0 => ['manual_image' => '']]]]],
            0,
            0
        ));
    }
}
