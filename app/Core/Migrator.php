<?php

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Migrator — تطبيق تغييرات المخطّط بترتيب وتتبّع وتراجع.
 *
 * ما كان قبله: سبعة ملفات .sql في database/migrations تُشغَّل **يدوياً**،
 * وترتيبها مكتوب في تعليقاتها فقط («يعتمد على admin_auth.sql»). لا شيء
 * يعرف أيّها طُبِّق، ولا شيء يمنع تشغيل واحد مرّتين، ولا سبيل للتراجع.
 *
 * ── النموذج: خطّ أساس + تغييرات ────────────────────────────
 *
 * الهجرات السبع القائمة **لا تبني القاعدة من الصفر**: كلها تعتمد على
 * جداول (users, products, orders, categories) لا وجود لها في أي منها.
 * أي أن المخطّط الحقيقي وُلد قبلها ونما بها.
 *
 * فبدل التظاهر بغير ذلك، النموذج هنا صريح:
 *
 *   tests/fixtures/schema.sql   → خطّ الأساس، المخطّط الكامل اليوم
 *   database/migrations/*.sql   → تغييرات، أغلبها **مطبوع في الأساس**
 *
 * ولذلك يوجد `baseline`: يسجّل الهجرات الموجودة كمطبَّقة بلا تنفيذها،
 * لأن خطّ الأساس يحويها فعلاً. تشغيلها عليه يفشل بـ«الجدول موجود».
 *
 * ── البصمة ─────────────────────────────────────────────────
 *
 * يُخزَّن sha256 لكل هجرة مطبَّقة. تعديل ملف طُبِّق سلفاً عطلٌ صامت من
 * أسوأ نوع: قاعدة المطوّر تحمل النسخة القديمة وقاعدة الإنتاج الجديدة،
 * والاثنتان تقولان «مطبَّقة». المهاجر يكشف ذلك بدل أن ينتظر انفجاره.
 */
final class Migrator
{
    private const TABLE = 'schema_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory
    ) {
    }

    /**
     * ينشئ جدول التتبّع إن غاب.
     *
     * `IF NOT EXISTS` مقصود: المهاجر يُستدعى كثيراً وأول ما يفعله هو
     * هذا، فيجب أن يكون بلا أثر عند التكرار.
     */
    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . '`version` VARCHAR(20) NOT NULL,'
            . '`name` VARCHAR(190) NOT NULL,'
            . '`checksum` CHAR(64) NOT NULL,'
            . '`applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`version`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * كل ملفات الهجرة على القرص، مرتّبة بالنسخة.
     *
     * الترتيب من اسم الملف (`0001_admin_auth.sql`) لا من تاريخ التعديل
     * ولا من ترتيب نظام الملفات — الاثنان يختلفان بين جهاز وآخر، وترتيب
     * الهجرات لا يحتمل ذلك.
     *
     * @return array<string, array{version: string, name: string, path: string}>
     */
    public function available(): array
    {
        $files = glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [];
        $out = [];

        foreach ($files as $path) {
            $base = basename($path, '.sql');

            if (!preg_match('/^(\d{4})_(.+)$/', $base, $m)) {
                throw new RuntimeException(
                    "ملف هجرة بلا رقم نسخة: {$base}.sql — الصيغة المطلوبة NNNN_name.sql"
                );
            }

            if (isset($out[$m[1]])) {
                throw new RuntimeException("رقم نسخة مكرّر: {$m[1]}");
            }

            $out[$m[1]] = ['version' => $m[1], 'name' => $m[2], 'path' => $path];
        }

        ksort($out);

        return $out;
    }

    /**
     * الهجرات المسجَّلة كمطبَّقة.
     *
     * @return array<string, array{version: string, name: string, checksum: string, applied_at: string}>
     */
    public function applied(): array
    {
        $this->ensureTable();

        $rows = $this->pdo
            ->query('SELECT `version`, `name`, `checksum`, `applied_at` FROM `' . self::TABLE . '` ORDER BY `version`')
            ->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $out[$row['version']] = $row;
        }

        return $out;
    }

    /** @return list<array{version: string, name: string, path: string}> */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->available(),
            static fn (array $m): bool => !isset($applied[$m['version']])
        ));
    }

    /**
     * هجرات طُبِّقت ثم تغيّر ملفها بعد ذلك.
     *
     * @return list<string>
     */
    public function drifted(): array
    {
        $available = $this->available();
        $out = [];

        foreach ($this->applied() as $version => $row) {
            if (!isset($available[$version])) {
                $out[] = "{$version} — مسجَّلة كمطبَّقة ولا ملف لها على القرص.";
                continue;
            }

            $current = $this->checksum($available[$version]['path']);
            if ($current !== $row['checksum']) {
                $out[] = "{$version}_{$row['name']} — الملف تغيّر بعد تطبيقه.";
            }
        }

        return $out;
    }

    /**
     * يسجّل كل الهجرات الموجودة كمطبَّقة **بلا تنفيذها**.
     *
     * يُستعمل مرّة واحدة على قاعدة بُنيت من خطّ الأساس: الأساس يحوي
     * أثر هذه الهجرات فعلاً، وتنفيذها عليه يفشل بـ«الجدول موجود».
     *
     * @return int عدد ما سُجِّل
     */
    public function baseline(): int
    {
        $this->ensureTable();
        $applied = $this->applied();
        $count = 0;

        foreach ($this->available() as $migration) {
            if (isset($applied[$migration['version']])) {
                continue;
            }

            $this->record($migration);
            $count++;
        }

        return $count;
    }

    /**
     * ينفّذ الهجرات المعلّقة.
     *
     * كل هجرة داخل معاملة خاصة بها: فشل السابعة لا يتراجع بالسادسة.
     *
     * ⚠️ MySQL لا يدعم DDL داخل معاملة — `CREATE TABLE` تُنهي المعاملة
     * القائمة ضمنياً ولا يمكن التراجع عنها. المعاملة هنا تحمي الـDML
     * (نقل البيانات في categories_dynamic مثلاً) وتضمن أن صفّ التتبّع
     * لا يُكتب إلا بعد نجاح السكربت كاملاً. أما التراجع عن DDL فمسؤولية
     * قسم @DOWN وحده.
     *
     * @param bool $pretend يطبع ما سيُنفَّذ بلا تنفيذه
     * @return list<string> أسماء ما طُبِّق
     */
    public function up(bool $pretend = false): array
    {
        $drift = $this->drifted();
        if ($drift !== []) {
            throw new RuntimeException(
                "هجرات مطبَّقة تغيّرت ملفاتها — أوقف قبل أن تنحرف القواعد:\n  "
                . implode("\n  ", $drift)
            );
        }

        $done = [];

        foreach ($this->pending() as $migration) {
            $sql = $this->section($migration['path'], 'UP');

            if ($sql === '') {
                throw new RuntimeException(
                    "الهجرة {$migration['version']}_{$migration['name']} بلا قسم @UP."
                );
            }

            if ($pretend) {
                $done[] = $migration['version'] . '_' . $migration['name'];
                continue;
            }

            $this->pdo->exec($sql);
            $this->record($migration);

            $done[] = $migration['version'] . '_' . $migration['name'];
        }

        return $done;
    }

    /**
     * يتراجع عن آخر هجرة أو أكثر.
     *
     * @param int $steps عدد الهجرات المتراجَع عنها، من الأحدث
     * @return list<string>
     */
    public function down(int $steps = 1, bool $pretend = false): array
    {
        $available = $this->available();
        $applied   = $this->applied();
        krsort($applied);

        $done = [];

        foreach ($applied as $version => $row) {
            if (count($done) >= $steps) {
                break;
            }

            if (!isset($available[$version])) {
                throw new RuntimeException("لا ملف للهجرة {$version} — التراجع مستحيل.");
            }

            $sql = $this->section($available[$version]['path'], 'DOWN');

            if ($sql === '') {
                throw new RuntimeException(
                    "الهجرة {$version}_{$row['name']} بلا قسم @DOWN — لا يمكن التراجع عنها."
                );
            }

            if (!$pretend) {
                $this->pdo->exec($sql);
                $this->pdo
                    ->prepare('DELETE FROM `' . self::TABLE . '` WHERE `version` = ?')
                    ->execute([$version]);
            }

            $done[] = $version . '_' . $row['name'];
        }

        return $done;
    }

    /**
     * يستخرج قسم @UP أو @DOWN من ملف هجرة.
     *
     * الصيغة سطر تعليق مفرد: `-- @UP` و `-- @DOWN`. اختير التعليق لأن
     * الملف يبقى SQL صالحاً يمكن لصقه في أي عميل قاعدة بيانات كما هو —
     * ولا يحتاج المهاجر ليُقرأ.
     */
    public function section(string $path, string $name): string
    {
        $content = (string) file_get_contents($path);

        // ⚠️ `\R` ممنوع هنا — والسبب مكتوب أصلاً في scripts/audit.php
        // (splitLines)، لكن هذا الموضع فاته. بلا معدِّل /u يعمل `\R` على
        // البايتات ويطابق `\x85`، وهو بايت استمرار شرعي داخل الحروف
        // العربية بترميز UTF-8 — أشهرها «م» (D9 85).
        //
        // الأثر هنا كان أخطر منه في عدّاد الأسطر: كل سطر تعليق عربي
        // يُقطع في منتصف حرف، فتصير بقيّته أسطراً لا تبدأ بـ`--`، أي
        // نصّاً عربياً خاماً يُسلَّم إلى PDO::exec كأنه SQL. النتيجة أن
        // **كل** هجرة في هذا المشروع تفشل بخطأ صيغة — الثماني كلها
        // (مقيس: 0001 يتضخّم من 112 سطراً إلى 149).
        //
        // بقي العطل مستتراً لأن الهجرات السبع الأولى سُجِّلت بـ`baseline`
        // — وهي تسجّل بلا تنفيذ — فلم يمرّ أي ملف على up() قطّ قبل اليوم.
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        $collecting = false;
        $out = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*--\s*@(UP|DOWN)\s*$/i', $line, $m)) {
                $collecting = strtoupper($m[1]) === strtoupper($name);
                continue;
            }

            if ($collecting) {
                $out[] = $line;
            }
        }

        return trim(implode("\n", $out));
    }

    public function checksum(string $path): string
    {
        // نهايات الأسطر تُوحَّد قبل الحساب: .gitattributes يُخرج CRLF على
        // Windows وLF على غيره، فالبصمة الخام كانت ستختلف بين جهازين
        // للملف نفسه بمحتوى واحد — إنذار انحراف كاذب في كل مرّة.
        $content = (string) file_get_contents($path);

        return hash('sha256', str_replace("\r\n", "\n", $content));
    }

    /** @param array{version: string, name: string, path: string} $migration */
    private function record(array $migration): void
    {
        $this->pdo
            ->prepare(
                'INSERT INTO `' . self::TABLE . '` (`version`, `name`, `checksum`) VALUES (?, ?, ?)'
            )
            ->execute([
                $migration['version'],
                $migration['name'],
                $this->checksum($migration['path']),
            ]);
    }
}
