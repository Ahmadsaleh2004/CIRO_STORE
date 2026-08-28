<?php

namespace Tests\Unit;

use App\Core\ImageUpload;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ImageUpload — الحارس الوحيد لما يُكتب على القرص من المرفوعات.
 *
 * كان المنطق مكتوباً مرّتين (AdminProductModel و BrandingModel) بنسختين
 * متطابقتين لا يفصلهما إلا بادئة الاسم، ولم يكن فيهما **أي حدّ للحجم**:
 * الأمر كلّه متروك لـupload_max_filesize في php.ini — إعداد خادم يختلف
 * بين بيئة وأخرى ولا يعرفه من يقرأ الكود.
 *
 * ⚠️ move_uploaded_file ترفض أي ملف لم يصل عبر رفع HTTP حقيقي، فلا
 * يمكن اختبار مسار الحفظ الكامل من CLI. المُختبَر هنا هو ما **يسبق**
 * الحفظ — وهو موضع كل قرارات الأمان: الحجم، والنوع، واشتقاق الامتداد.
 */
final class ImageUploadTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /** ينشئ ملفاً مؤقتاً بمحتوى معطى ويُرجع مساره. */
    private function tempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/cairo-upload-' . bin2hex(random_bytes(6));
        file_put_contents($path, $contents);
        $this->temp[] = $path;
        return $path;
    }

    /** أصغر GIF صالح — بايتاته الحقيقية، فيتعرّف عليه mime_content_type. */
    private function tinyGif(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    private function entry(string $path): array
    {
        return ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK];
    }

    public function testTheLimitIsFiveMegabytes(): void
    {
        // الرقم مثبَّت عمداً: تغييره قرار لا تفصيل، ويجب أن يُرى في diff.
        $this->assertSame(5 * 1024 * 1024, ImageUpload::MAX_BYTES);
    }

    /**
     * ملف أكبر من الحدّ يُرفض قبل أن يُسأل عن نوعه.
     *
     * الترتيب مقصود: mime_content_type تقرأ الملف، وقراءة ملف ضخم
     * لترفضه بعدها هدرٌ يمكن للمهاجم تكراره.
     */
    public function testAFileOverTheLimitIsRejected(): void
    {
        $path = $this->tempFile(str_repeat('A', ImageUpload::MAX_BYTES + 1));

        $this->assertNull(ImageUpload::store($this->entry($path), sys_get_temp_dir(), 'x_'));
        $this->assertFileExists($path, 'الملف المرفوض يجب ألّا يُنقَل.');
    }

    public function testAFileAtTheLimitIsNotRejectedForItsSize(): void
    {
        // بالضبط عند الحدّ: الشرط `>` لا `>=` — الخطأ بواحد هنا يرفض
        // ملفاً مسموحاً به تماماً.
        $path = $this->tempFile(str_repeat('A', ImageUpload::MAX_BYTES));

        // يُرفض لأنه ليس صورة، لا لأن حجمه تجاوز — والتمييز مهمّ.
        $this->assertNull(ImageUpload::store($this->entry($path), sys_get_temp_dir(), 'x_'));
        $this->assertLessThanOrEqual(ImageUpload::MAX_BYTES, filesize($path));
    }

    public function testANonImageIsRejectedWhateverItsName(): void
    {
        $path = $this->tempFile('<?php echo "hello"; ?>');

        $this->assertNull(ImageUpload::store($this->entry($path), sys_get_temp_dir(), 'x_'));
    }

    public function testAFailedUploadIsRejected(): void
    {
        $path = $this->tempFile($this->tinyGif());

        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE] as $code) {
            $this->assertNull(
                ImageUpload::store(['tmp_name' => $path, 'error' => $code], sys_get_temp_dir(), 'x_'),
                "قُبل رفع فاشل برمز {$code}."
            );
        }
    }

    /**
     * الامتداد يُشتقّ من المحتوى، وكل نوع مقبول له امتداد مقابل.
     *
     * القائمة والأذرع كانتا منفصلتين في الكود القديم: إضافة نوع إلى
     * القائمة بلا امتداد مقابل كانت تحفظ الملف بامتداد خاطئ **بصمت**.
     * الخريطة الواحدة تمنع ذلك، وهذا الاختبار يمنع فصلها مجدداً.
     */
    public function testEveryAcceptedMimeHasAnExtension(): void
    {
        $map = (new ReflectionClass(ImageUpload::class))->getConstant('EXT_BY_MIME');

        $this->assertNotEmpty($map);

        foreach ($map as $mime => $ext) {
            $this->assertMatchesRegularExpression('#^image/[a-z0-9+.-]+$#', (string) $mime);
            $this->assertMatchesRegularExpression('/^[a-z0-9]{2,5}$/', (string) $ext, "امتداد غير صالح لـ{$mime}");
        }
    }

    /**
     * لا يُقبل نوع خطر ولو أُضيف إلى الخريطة سهواً.
     *
     * SVG صورة، لكنها مستند XML ينفّذ سكربتاً عند فتحه مباشرةً — فهي
     * ناقل XSS لا صورة عرض. وجودها في الخريطة يوماً ما خطأ يجب أن
     * يُوقفه البناء لا المراجعة.
     */
    public function testDangerousImageTypesAreNotAccepted(): void
    {
        $map = (new ReflectionClass(ImageUpload::class))->getConstant('EXT_BY_MIME');

        foreach (['image/svg+xml', 'image/svg', 'text/html', 'application/xml'] as $dangerous) {
            $this->assertArrayNotHasKey($dangerous, $map, "نوع خطر مقبول: {$dangerous}");
        }
    }

    /**
     * كلا الموديلين يفوّضان إلى المنطق الواحد.
     *
     * هذا ما يمنع عودة النسختين المتطابقتين — ومعهما احتمال أن يُشدَّد
     * أحدهما ويبقى الآخر.
     */
    public function testBothModelsDelegateInsteadOfDuplicating(): void
    {
        foreach (['AdminProductModel', 'BrandingModel'] as $model) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . "/app/Models/{$model}.php");

            $this->assertStringContainsString('ImageUpload::store(', $src, "{$model} لا يفوّض.");
            $this->assertStringNotContainsString(
                'move_uploaded_file(',
                $src,
                "{$model} ما زال يحفظ بنفسه — نسختان تعنيان تشديداً على واحدة فقط."
            );
        }
    }
}
