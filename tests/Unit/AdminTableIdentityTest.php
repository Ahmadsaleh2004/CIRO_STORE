<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * جداول الأدمن — الهوية المعروضة، وسلامة عدد الأعمدة.
 *
 * قاعدة المشروع: المفتاح الأساسي هوية تبقى مع الصفّ مدى حياته، ولا
 * يُعاد ترقيمه. الوجه الآخر لها في العرض: الجدول الذي يُظهر «رقماً»
 * لكيان يجب أن يُظهر معرّفه الحقيقي لا ترتيب صفّه.
 *
 * كان جدول اليوزر يطبع `$startNum + $i` — عدّاد صفحات يزحف عند كل حذف،
 * فيتغيّر «رقم» المستخدم بلا أن يتغيّر هو. وجدول المنتجات لم يكن يُظهر
 * الهوية إطلاقاً: موجودة في id="product-row-N" فيراها الـJS ولا يراها
 * الأدمن. أمّا جدولا الأدمنية والطلبات فكانا يطبعان المعرّف الحقيقي
 * أصلاً — أي أن أربعة جداول متشابهة كانت تتصرّف بثلاث طرق.
 */
final class AdminTableIdentityTest extends TestCase
{
    private static function viewsDir(): string
    {
        return dirname(__DIR__, 2) . '/app/views/admin';
    }

    /**
     * جداول الكيانات تعرض المعرّف الحقيقي.
     *
     * @return array<string, array{string, string}>
     */
    public static function entityTables(): array
    {
        return [
            'users'    => ['users/index.php',          "\$u['id']"],
            'products' => ['product/index.php',        "\$p['id']"],
            'admins'   => ['manage-admins/index.php',  "\$adm['id']"],
            'orders'   => ['orders/index.php',         "\$o['order_id']"],
        ];
    }

    /**
     * @param string $file    مسار الـview نسبةً إلى app/views/admin
     * @param string $idExpr  تعبير المعرّف المتوقّع داخل خلية
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entityTables')]
    public function testTheTablePrintsTheRealIdInACell(string $file, string $idExpr): void
    {
        $src = (string) file_get_contents(self::viewsDir() . '/' . $file);

        // بين <td> والمعرّف قد يقع تعليق شرح، أو بادئة قصيرة مثل «#»
        // (جدول الطلبات يكتب `#<?= …`) — كلاهما تنسيق لا يغيّر أن
        // المطبوع هو المعرّف الحقيقي.
        $pattern = '/<td[^>]*>\s*(?:<\?php.*?\?>\s*)?[^<]{0,4}<\?=\s*\(int\)'
                 . preg_quote($idExpr, '/') . '/s';

        $this->assertMatchesRegularExpression(
            $pattern,
            $src,
            "{$file} لا يطبع المعرّف الحقيقي في خلية — الهوية المعروضة يجب ألّا تكون ترتيب صفّ."
        );
    }

    /**
     * لا عدّاد صفوف يتظاهر بأنه هوية.
     *
     * `$startNum + $i` وأمثاله تنتج رقماً يتغيّر عند حذف أي صفّ قبله —
     * فيظنّ الأدمن أنه يشير إلى كيان بينما يشير إلى موضع.
     */
    public function testNoAdminTableUsesARowCounterAsIdentity(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::viewsDir(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            // عدّاد ترقيم صفحات يُطبع كما هو داخل خلية.
            if (preg_match('/<td[^>]*>\s*<\?=\s*\$startNum\s*\+/', $src)) {
                $offenders[] = $file->getFilename() . ' — يطبع $startNum + $i كهوية.';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "عدّاد صفوف يتظاهر بأنه هوية:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * $emptyColspan يطابق عدد أعمدة الجدول فعلاً.
     *
     * صفّ «لا نتائج» يمتدّ على الجدول بـcolspan مكتوب بيده. وإضافة عمود
     * بلا تحديثه — وهي ما فعلته إضافة عمود المعرّف للمنتجات — تترك الصفّ
     * أقصر من الجدول، فينكسر شكله بصمت في الحالة التي لا يفتحها أحد
     * أثناء التطوير: حين لا توجد بيانات أصلاً.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entityTables')]
    public function testEmptyRowColspanMatchesTheHeaderCount(string $file): void
    {
        $src = (string) file_get_contents(self::viewsDir() . '/' . $file);

        if (!preg_match('/\$emptyColspan\s*=\s*(\d+)/', $src, $m)) {
            $this->markTestSkipped("{$file} لا يستعمل صفّ الجدول الفارغ المشترك.");
        }

        $declared = (int) $m[1];
        $headers  = preg_match_all('/<th[\s>]/', $src);

        $this->assertSame(
            $headers,
            $declared,
            "{$file}: الجدول فيه {$headers} عموداً و\$emptyColspan = {$declared}."
        );
    }
}
