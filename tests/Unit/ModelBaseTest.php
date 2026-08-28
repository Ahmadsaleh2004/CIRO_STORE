<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * الأساس المشترك للموديلات — موضع واحد يعرف قاعدة البيانات.
 *
 * كان `Database::connect()` مكتوباً 156 مرّة عبر ستة عشر موديلاً. ولم
 * تكن المشكلة اقتراناً — استبدال مصدر البيانات يعمل أصلاً عبر
 * Database::setConnection()، وعليه تعمل كل اختبارات التكامل في هذا
 * المشروع — بل **تكراراً**: سطر واحد مُعاد 156 مرّة.
 *
 * وهذه الاختبارات تحرس المكسب. سطر واحد جديد يستدعي Database::connect()
 * مباشرةً في موديل يعيد فتح الباب، ولا يظهر في أي اختبار سلوكي — لأنه
 * يعمل تماماً. النتيجة الوحيدة أنه يتراكم حتى نعود إلى 156.
 */
final class ModelBaseTest extends TestCase
{
    /** @return list<string> */
    private static function modelFiles(): array
    {
        return glob(dirname(__DIR__, 2) . '/app/Models/*.php') ?: [];
    }

    public function testTheModelDirectoryIsNotEmpty(): void
    {
        // حارس على الحارس: مسار خاطئ يجعل الفحصين أدناه يمرّان على
        // قائمة فارغة، فيعلنان النجاح بلا أن يفحصا شيئاً.
        $this->assertGreaterThan(10, count(self::modelFiles()));
    }

    public function testEveryModelExtendsTheSharedBase(): void
    {
        $offenders = [];

        foreach (self::modelFiles() as $path) {
            $src = (string) file_get_contents($path);

            if (!preg_match('/^class\s+\w+\s+extends\s+Model\b/m', $src)) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "موديلات خارج الأساس المشترك:\n  " . implode("\n  ", $offenders)
        );
    }

    public function testNoModelOpensItsOwnConnection(): void
    {
        $offenders = [];

        foreach (self::modelFiles() as $path) {
            // التعليقات تُجرَّد: الموديلات تشرح ما استُبدل، والشرح هو
            // ما يمنع عودته.
            $src = (string) file_get_contents($path);
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (str_contains($src, 'Database::connect(')) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "موديلات تفتح اتصالها بنفسها بدل self::db():\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * أصنافٌ خارج طبقة الموديلات يُسمح لها بفتح اتصال — كلٌّ بسببها.
     *
     * القائمة قصيرة عمداً، وكل إضافة إليها قرار يُبرَّر لا سهو يُغتفر.
     * (النمط نفسه المستعمل في CsrfContractHttpTest::DOCUMENTED_EXEMPTIONS.)
     */
    private const DOCUMENTED_EXEMPTIONS = [
        'Core/Throttle.php' =>
            'بنية تحتية لها جدولها الخاص (throttle_attempts) ولا تمثّل كياناً '
            . 'في المجال. جعلها موديلاً يضع حارس الطلبات في طبقة البيانات.',

        'Core/Mailer.php' =>
            'يرسل بريداً؛ الطابور تفصيل في تنفيذه لا هويته. وراثته Model '
            . 'تقول إنه موديل وهو ليس كذلك.',

        'Controllers/HealthController.php' =>
            'يختبر الاتصال نفسه — /health يجيب عن «هل القاعدة تستجيب؟». '
            . 'المرور بموديل يقيس الموديل لا الاتصال.',
    ];

    /**
     * لا يُفتح اتصال خارج طبقة الموديلات إلا بمبرَّر مذكور.
     *
     * لولا هذا الفحص لأمكن أن يهاجر الاستدعاء من الموديلات إلى الخدمات
     * أو الكنترولرز — فيتفرّق من جديد بلا أن يلاحظه أحد، وهو بالضبط ما
     * كانت عليه الحال قبل الأساس المشترك.
     */
    public function testOnlyTheBaseAndItsOwnLayerKnowAboutDatabase(): void
    {
        $allowed   = ['Model.php', 'Database.php'];
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2) . '/app/Core/*.php') ?: [] as $path) {
            if (in_array(basename($path), $allowed, true)) {
                continue;
            }

            $src = (string) file_get_contents($path);
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (str_contains($src, 'Database::connect(')) {
                $offenders[] = 'Core/' . basename($path);
            }
        }

        foreach (glob(dirname(__DIR__, 2) . '/app/Controllers/*.php') ?: [] as $path) {
            $src = (string) file_get_contents($path);
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (str_contains($src, 'Database::connect(')) {
                $offenders[] = 'Controllers/' . basename($path);
            }
        }

        $undocumented = array_values(array_diff($offenders, array_keys(self::DOCUMENTED_EXEMPTIONS)));

        $this->assertSame(
            [],
            $undocumented,
            "اتصال قاعدة البيانات يُفتح خارج طبقة الموديلات بلا مبرَّر مذكور:\n  "
            . implode("\n  ", $undocumented)
        );
    }

    /**
     * ولا استثناء يبقى بعد أن يزول سببه.
     *
     * قائمة استثناءات لا تُراجَع تتحوّل إلى قائمة تجاهل: يُصلَح الموضع
     * ويبقى اسمه فيها، فيغطّي عودةً لاحقة للعطل نفسه بصمت.
     */
    public function testNoExemptionOutlivesItsReason(): void
    {
        $root  = dirname(__DIR__, 2) . '/app/';
        $stale = [];

        foreach (self::DOCUMENTED_EXEMPTIONS as $relative => $reason) {
            $path = $root . $relative;

            if (!is_file($path)) {
                $stale[] = "{$relative} — الملف لم يعد موجوداً";
                continue;
            }

            $src = (string) file_get_contents($path);
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (!str_contains($src, 'Database::connect(')) {
                $stale[] = "{$relative} — لم يعد يفتح اتصالاً، احذفه من القائمة";
            }
        }

        $this->assertSame([], $stale, "استثناءات فقدت سببها:\n  " . implode("\n  ", $stale));
    }
}
