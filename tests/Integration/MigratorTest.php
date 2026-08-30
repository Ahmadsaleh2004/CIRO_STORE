<?php

namespace Tests\Integration;

use App\Core\Migrator;
use PDO;
use Tests\Support\DatabaseTestCase;

/**
 * المهاجر — يطبّق تغييرات المخطّط بترتيب وتتبّع وتراجع.
 *
 * الاختبارات تعمل على مجلد هجرات **مؤقّت** تبنيه بنفسها، لا على
 * database/migrations الحقيقي. السبب أن الملفات الحقيقية تعتمد على
 * جداول موجودة في خطّ الأساس، وتشغيلها في اختبار يعني إعادة بناء
 * القاعدة كلها في كل حالة — بطيء، وما يُختبَر عندها هو ملفات SQL لا
 * منطقُ المهاجر.
 */
final class MigratorTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/cairo-migrations-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        $this->pdo->exec('DROP TABLE IF EXISTS `schema_migrations`');
        $this->pdo->exec('DROP TABLE IF EXISTS `mt_widgets`');
        $this->pdo->exec('DROP TABLE IF EXISTS `mt_gadgets`');
    }

    protected function tearDown(): void
    {
        // الخروج المبكّر ليس احتياطاً. parent::setUp() تتخطّى الاختبار حين
        // لا تتوفّر قاعدة اختبار، والتخطّي استثناء — فيخرج setUp قبل إسناد
        // $pdo و$dir، بينما tearDown تعمل على أي حال. النتيجة أن كلّ تخطٍّ
        // كان يُبلَّغ عنه **خطأً** («typed property … before initialization»)،
        // فتظهر ثمانية عشر نتيجة حمراء لا علاقة لها بالمهاجر على أي جهاز
        // بلا MySQL — وحُزمة حمراء دائماً لا تحرس شيئاً، لأن الفشل الحقيقي
        // يضيع بينها.
        if (!isset($this->pdo)) {
            parent::tearDown();
            return;
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);

        $this->pdo->exec('DROP TABLE IF EXISTS `schema_migrations`');
        $this->pdo->exec('DROP TABLE IF EXISTS `mt_widgets`');
        $this->pdo->exec('DROP TABLE IF EXISTS `mt_gadgets`');

        parent::tearDown();
    }

    private function write(string $filename, string $up, string $down = ''): void
    {
        $body = "-- @UP\n{$up}\n";
        if ($down !== '') {
            $body .= "\n-- @DOWN\n{$down}\n";
        }

        file_put_contents($this->dir . '/' . $filename, $body);
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->pdo, $this->dir);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    // ── الترتيب ──────────────────────────────────────────────

    /**
     * الترتيب من رقم النسخة لا من نظام الملفات.
     *
     * هذا هو سبب وجود المهاجر أصلاً: كانت التبعية مكتوبة نصّاً في
     * التعليقات («يعتمد على admin_auth.sql») ولا شيء يفرضها، فترتيب
     * التنفيذ يتبع ترتيب نظام الملفات — وهو يختلف بين جهاز وآخر.
     */
    public function testAppliesMigrationsInVersionOrder(): void
    {
        $this->write('0002_second.sql', 'CREATE TABLE `mt_gadgets` (`id` INT PRIMARY KEY);');
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');

        $done = $this->migrator()->up();

        $this->assertSame(['0001_first', '0002_second'], $done);
        $this->assertTrue($this->tableExists('mt_widgets'));
        $this->assertTrue($this->tableExists('mt_gadgets'));
    }

    public function testRejectsAFileWithoutAVersionNumber(): void
    {
        $this->write('no_version_here.sql', 'SELECT 1;');

        $this->expectException(\RuntimeException::class);
        $this->migrator()->available();
    }

    public function testRejectsDuplicateVersionNumbers(): void
    {
        $this->write('0001_one.sql', 'SELECT 1;');
        $this->write('0001_two.sql', 'SELECT 1;');

        // رقمان متطابقان يعنيان أن ترتيب الاثنين غير محدَّد — وهو
        // بالضبط ما جاء المهاجر ليمنعه.
        $this->expectException(\RuntimeException::class);
        $this->migrator()->available();
    }

    // ── التتبّع ──────────────────────────────────────────────

    public function testAnAppliedMigrationIsNotRunTwice(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');

        $this->assertSame(['0001_first'], $this->migrator()->up());

        // لو أُعيد التنفيذ لفشل بـ«الجدول موجود» — وهذا ما كان يحدث
        // فعلاً حين كانت الملفات تُشغَّل يدوياً بلا تتبّع.
        $this->assertSame([], $this->migrator()->up());
    }

    public function testPendingShrinksAsMigrationsAreApplied(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');
        $this->write('0002_second.sql', 'CREATE TABLE `mt_gadgets` (`id` INT PRIMARY KEY);');

        $this->assertCount(2, $this->migrator()->pending());
        $this->migrator()->up();
        $this->assertCount(0, $this->migrator()->pending());
    }

    public function testPretendReportsWithoutTouchingTheDatabase(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');

        $done = $this->migrator()->up(true);

        $this->assertSame(['0001_first'], $done);
        $this->assertFalse($this->tableExists('mt_widgets'), 'pretend أنشأ الجدول فعلاً.');
        $this->assertCount(1, $this->migrator()->pending(), 'pretend سجّل الهجرة كمطبَّقة.');
    }

    // ── الانحراف ─────────────────────────────────────────────

    /**
     * أخطر ما يحرسه المهاجر.
     *
     * تعديل ملف طُبِّق سلفاً عطلٌ صامت من أسوأ نوع: قاعدة المطوّر تحمل
     * النسخة القديمة وقاعدة الإنتاج الجديدة، والاثنتان تقولان
     * «مطبَّقة». لا شيء يكشف الفرق حتى ينفجر استعلام على عمود غير موجود.
     */
    public function testDetectsAMigrationFileEditedAfterItWasApplied(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');
        $this->migrator()->up();

        $this->assertSame([], $this->migrator()->drifted());

        $this->write(
            '0001_first.sql',
            "CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY, `extra` VARCHAR(10));"
        );

        $this->assertCount(1, $this->migrator()->drifted());
    }

    public function testUpRefusesToRunWhileDriftIsUnresolved(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');
        $this->migrator()->up();

        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY, `x` INT);');
        $this->write('0002_second.sql', 'CREATE TABLE `mt_gadgets` (`id` INT PRIMARY KEY);');

        // التوقّف مقصود: تطبيق هجرة جديدة فوق قاعدة انحرف تاريخها يبني
        // على أساس مجهول.
        $this->expectException(\RuntimeException::class);
        $this->migrator()->up();
    }

    public function testDetectsAnAppliedMigrationWhoseFileDisappeared(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');
        $this->migrator()->up();

        unlink($this->dir . '/0001_first.sql');

        $this->assertCount(1, $this->migrator()->drifted());
    }

    /**
     * نهاية السطر لا تُحسب انحرافاً.
     *
     * .gitattributes يُخرج CRLF على Windows وLF على غيره، فالبصمة الخام
     * كانت ستختلف بين جهازين للملف نفسه بمحتوى واحد — إنذار كاذب في كل
     * مرّة، وإنذار كاذب متكرّر يُدرَّب الناس على تجاهله.
     */
    public function testLineEndingsDoNotCountAsDrift(): void
    {
        $sql = 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);';

        file_put_contents($this->dir . '/0001_first.sql', "-- @UP\n{$sql}\n");
        $this->migrator()->up();

        file_put_contents($this->dir . '/0001_first.sql', "-- @UP\r\n{$sql}\r\n");

        $this->assertSame([], $this->migrator()->drifted());
    }

    // ── التراجع ──────────────────────────────────────────────

    public function testRollsBackTheMostRecentMigration(): void
    {
        $this->write(
            '0001_first.sql',
            'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);',
            'DROP TABLE `mt_widgets`;'
        );
        $this->write(
            '0002_second.sql',
            'CREATE TABLE `mt_gadgets` (`id` INT PRIMARY KEY);',
            'DROP TABLE `mt_gadgets`;'
        );
        $this->migrator()->up();

        $done = $this->migrator()->down();

        $this->assertSame(['0002_second'], $done, 'التراجع بدأ من الأقدم لا الأحدث.');
        $this->assertFalse($this->tableExists('mt_gadgets'));
        $this->assertTrue($this->tableExists('mt_widgets'), 'التراجع تجاوز ما لم يُطلب.');
    }

    public function testRollsBackSeveralStepsInReverseOrder(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);', 'DROP TABLE `mt_widgets`;');
        $this->write('0002_second.sql', 'CREATE TABLE `mt_gadgets` (`id` INT PRIMARY KEY);', 'DROP TABLE `mt_gadgets`;');
        $this->migrator()->up();

        $this->assertSame(['0002_second', '0001_first'], $this->migrator()->down(2));
        $this->assertCount(2, $this->migrator()->pending());
    }

    public function testRollingBackAMigrationWithoutADownSectionThrows(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');
        $this->migrator()->up();

        // الرفض الصريح خير من تراجع نصفيّ يترك القاعدة بين حالتين.
        $this->expectException(\RuntimeException::class);
        $this->migrator()->down();
    }

    // ── خطّ الأساس ───────────────────────────────────────────

    /**
     * baseline تسجّل بلا تنفيذ.
     *
     * الهجرات السبع القائمة مطبوعة في tests/fixtures/schema.sql فعلاً،
     * فتنفيذها على قاعدة بُنيت منه يفشل بـ«الجدول موجود».
     */
    public function testBaselineRecordsWithoutExecuting(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');

        $this->assertSame(1, $this->migrator()->baseline());
        $this->assertFalse($this->tableExists('mt_widgets'), 'baseline نفّذت السكربت.');
        $this->assertSame([], $this->migrator()->pending());
    }

    public function testBaselineIsIdempotent(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');

        $this->assertSame(1, $this->migrator()->baseline());
        $this->assertSame(0, $this->migrator()->baseline());
    }

    // ── الأقسام ──────────────────────────────────────────────

    public function testSectionsAreParsedIndependently(): void
    {
        $this->write('0001_first.sql', 'SELECT 1;', 'SELECT 2;');
        $path = $this->dir . '/0001_first.sql';

        $this->assertSame('SELECT 1;', $this->migrator()->section($path, 'UP'));
        $this->assertSame('SELECT 2;', $this->migrator()->section($path, 'DOWN'));
    }

    public function testAMissingSectionYieldsAnEmptyString(): void
    {
        $this->write('0001_first.sql', 'SELECT 1;');

        $this->assertSame('', $this->migrator()->section($this->dir . '/0001_first.sql', 'DOWN'));
    }

    // ── الهجرات الحقيقية ─────────────────────────────────────

    /**
     * ملفات database/migrations الفعلية كلها صالحة الصيغة.
     *
     * لا تُنفَّذ هنا — تعتمد على جداول من خطّ الأساس. لكن بنيتها تُفحص:
     * رقم نسخة صحيح، وقسم @UP غير فارغ، وقسم @DOWN موجود.
     */
    public function testEveryRealMigrationIsWellFormed(): void
    {
        $real = new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations');
        $problems = [];

        foreach ($real->available() as $migration) {
            $label = $migration['version'] . '_' . $migration['name'];

            if ($real->section($migration['path'], 'UP') === '') {
                $problems[] = "{$label} — قسم @UP فارغ.";
            }
            if ($real->section($migration['path'], 'DOWN') === '') {
                $problems[] = "{$label} — لا قسم @DOWN، ولو كان التراجع مستحيلاً فاكتب سببه.";
            }
        }

        // 10 منذ 0010_order_address_snapshot (عنوان الطلب لقطة لا مرجع:
        // كان address_id مفتاحاً حيّاً بـON DELETE SET NULL، فتعديل
        // المستخدم لعنوانه يغيّر وجهة طلب سُلّم فعلاً، وحذفه يمحو عنوان
        // طلبات مكتملة نهائياً).
        // 11 منذ 0011_server_side_cart (السلّة تتبع المستخدم لا المتصفّح:
        // كانت في localStorage فلا تعبر أجهزته وتضيع بمسح بيانات
        // المتصفّح — وضياع سلّة مليئة خسارة بيع لا إزعاج واجهة).
        // 12 منذ 0012_slider_item_title (سطر عنوان فوق الوصف على صورة
        // السلايدر: كان الحقل النصّي واحداً، فيحمل دورين متنافسين —
        // عنوانٌ يُعرِّف ووصفٌ يشرح — والصورة تعرض أحدهما لا كليهما).
        $this->assertCount(12, $real->available(), 'عدد الهجرات تغيّر — حدّث هذا الاختبار عمداً لا سهواً.');
        $this->assertSame([], $problems, "هجرات غير مكتملة الصيغة:\n  " . implode("\n  ", $problems));
    }

    /**
     * التعليق العربي يبقى تعليقاً بعد الاستخراج.
     *
     * هذا الاختبار يحرس عطلاً كان صامتاً تماماً: section() كانت تقسّم
     * الأسطر بـ`preg_split('/\R/')`، و`\R` بلا معدِّل /u يطابق البايت
     * `\x85` — وهو بايت استمرار شرعي داخل «م» (D9 85) وأخواتها. فكان كل
     * سطر تعليق عربي يُقطع في منتصف حرف، وتصير بقيّته سطراً لا يبدأ
     * بـ`--`، أي نصّاً عربياً يُسلَّم إلى PDO::exec كأنه SQL.
     *
     * ولم يظهر العطل قطّ لأن الهجرات السبع الأولى سُجِّلت بـbaseline بلا
     * تنفيذ — فأول استدعاء حقيقي لـup() هو الذي اصطدم به.
     *
     * الفحص هنا على القاعدة لا على المظهر: كل سطر غير فارغ في القسم
     * المستخرَج إمّا تعليق وإمّا SQL — ولا سطر يبدأ بحرف عربي.
     */
    public function testArabicCommentsSurviveSectionExtraction(): void
    {
        $real     = new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations');
        $mangled  = [];

        foreach ($real->available() as $migration) {
            foreach (['UP', 'DOWN'] as $part) {
                $section = $real->section($migration['path'], $part);

                foreach (preg_split('/\r\n|\n|\r/', $section) ?: [] as $n => $line) {
                    $line = ltrim($line);
                    if ($line === '' || str_starts_with($line, '--')) {
                        continue;
                    }
                    // حرف عربي في أول سطر ليس تعليقاً = تعليق مقطوع.
                    if (preg_match('/^[\x{0600}-\x{06FF}]/u', $line)) {
                        $mangled[] = sprintf(
                            '%s_%s [@%s سطر %d]: %s',
                            $migration['version'],
                            $migration['name'],
                            $part,
                            $n + 1,
                            mb_substr($line, 0, 40)
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $mangled,
            "تعليقات عربية انقطعت فصارت SQL:\n  " . implode("\n  ", $mangled)
        );
    }
}
