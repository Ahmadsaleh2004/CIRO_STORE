<?php

namespace App\Models;

use App\Core\Model;
use Exception;

/**
 * BackupModel — نسخ احتياطي لقاعدة البيانات SQL.
 * التخزين: <root>/storage/backups (خارج public/ تمامًا + حماية .htaccess).
 * الإنشاء: يحاول mysqldump عبر exec() أولًا، فإن فشل يلجأ لتصدير PHP-native
 * (SHOW CREATE TABLE + SELECT * لكل جدول).
 */
class BackupModel extends Model
{
    private const DIR    = ROOTPATH . '/storage/backups';
    private const PREFIX = 'backup_';
    private const PATTERN = '/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/';

    /**
     * إنشاء نسخة جديدة.
     * @return array{success: bool, filename: string|null, message: string}
     */
    public static function createBackup(): array
    {
        $dir = self::getDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['success' => false, 'filename' => null, 'message' => 'Backup directory is not writable.'];
        }

        $filename = self::PREFIX . date('Y-m-d_H-i-s') . '.sql';
        $path     = $dir . DIRECTORY_SEPARATOR . $filename;

        // 1) محاولة mysqldump عبر exec() — الطريقة القياسية
        $dump = self::tryMysqldump($path);
        if ($dump['success'] && is_file($path) && filesize($path) > 0) {
            return ['success' => true, 'filename' => $filename, 'message' => 'Backup created via mysqldump.'];
        }

        // 2) Fallback: تصدير SQL يدوي عبر PDO
        $sql = self::exportSqlNative();
        // exportSqlNative معلَنة `: string`، فـis_string كانت دائماً true.
        if ($sql === '') {
            return ['success' => false, 'filename' => null, 'message' => 'Both mysqldump and native export failed.'];
        }

        if (@file_put_contents($path, $sql) === false) {
            return ['success' => false, 'filename' => null, 'message' => 'Failed to write backup file.'];
        }

        return ['success' => true, 'filename' => $filename, 'message' => 'Backup created via PHP native export.'];
    }

    /**
     * محاولة تشغيل mysqldump — يجرب عدّة مسارات شائعة للـ binary.
     *
     * @return array{success: bool, message?: string} — success وحده مضمون؛ الرسالة تُرفَق عند الفشل
     */
    private static function tryMysqldump(string $path): array
    {
        if (!function_exists('exec') || !function_exists('is_executable')) {
            return ['success' => false];
        }

        $candidates = ['mysqldump'];
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates = array_merge($candidates, [
                'C:\\xampp\\mysql\\bin\\mysqldump.exe',
                'C:\\wamp64\\bin\\mysql\\mysql8.0\\bin\\mysqldump.exe',
            ]);
        } else {
            $candidates[] = '/usr/bin/mysqldump';
        }

        // ── كلمة السر لا تُمرَّر على سطر الأوامر ────────────────────
        //
        // كانت تُمرَّر بـ--password=… . وسطر أوامر أي عملية **مقروء لكل
        // مستخدمي الجهاز**: `ps aux` على لينكس، وTask Manager أو
        // Get-CimInstance Win32_Process على ويندوز. وmysqldump نفسه يحذّر
        // من ذلك. الهروب بـescapeshellarg يمنع الحقن ولا يمنع القراءة —
        // مشكلتان مختلفتان.
        //
        // البديل: ملف إعدادات مؤقّت يقرأه mysqldump بـ--defaults-extra-file،
        // ويجب أن يكون **أول** وسيط وإلا تجاهله. يُحذف في finally مهما حدث.
        $cnfPath = null;
        try {
            $cnfPath = tempnam(sys_get_temp_dir(), 'cs_dump_');
            if ($cnfPath === false) {
                return ['success' => false];
            }

            // صلاحيات ضيّقة قبل الكتابة (بلا أثر على ويندوز لكن لازمة على
            // لينكس حيث tempnam يعطي 0600 أصلاً — التثبيت صراحةً أوضح).
            @chmod($cnfPath, 0600);

            // القيم داخل ملف ini لا تُهرَّب بـescapeshellarg — تُقتبس
            // ويُهرَّب المحرف \ و" وحدهما، وهذا ما يفهمه قارئ my.cnf.
            $q = static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
            $ini = "[client]\n"
                 . 'host=' . $q(DB_HOST) . "\n"
                 . 'user=' . $q(DB_USER) . "\n"
                 . 'password=' . $q(DB_PASS) . "\n";

            if (@file_put_contents($cnfPath, $ini) === false) {
                return ['success' => false];
            }

            $name = escapeshellarg(DB_NAME);
            $out  = escapeshellarg($path);
            $cnf  = escapeshellarg($cnfPath);

            foreach ($candidates as $bin) {
                if ($bin !== 'mysqldump' && !is_file($bin)) {
                    continue;
                }
                $cmd = sprintf(
                    '%s --defaults-extra-file=%s'
                    . ' --single-transaction --routines --triggers %s > %s 2>&1',
                    escapeshellarg($bin),
                    $cnf,
                    $name,
                    $out
                );
                // الأمر لا يحمل أي مدخل مستخدم: $bin من قائمة مسارات
                // مكتوبة في هذا الملف، و$name من ثابت DB_NAME، و$path
                // و$cnfPath مولَّدان داخلياً — وكلها تمرّ بـescapeshellarg.
                // وكلمة السر لم تعد على سطر الأوامر أصلاً.
                // nosemgrep: php.lang.security.exec-use.exec-use
                @exec($cmd, $_, $code);
                if ($code === 0 && is_file($path) && filesize($path) > 0) {
                    return ['success' => true];
                }
                // $path مولَّد في createBackup من طابع زمني، لا مدخل فيه.
                // nosemgrep: php.lang.security.unlink-use.unlink-use,cairo-unlink-unvalidated-path
                @unlink($path); // مسح أي ملف جزئي قبل التجربة التالية
            }

            return ['success' => false];
        } finally {
            // الملف يحمل كلمة السر — لا يجوز أن يبقى في مجلد المؤقتات
            // ولو فشل كل شيء أو رُمي استثناء.
            if ($cnfPath !== null && is_file($cnfPath)) {
                // $cnfPath من tempnam() في هذه الدالة — لا مدخل مستخدم.
                // nosemgrep: php.lang.security.unlink-use.unlink-use,cairo-unlink-unvalidated-path
                @unlink($cnfPath);
            }
        }
    }

    /**
     * تصدير SQL كامل يدويًا عبر PDO (fallback إن لم يتوفر mysqldump).
     */
    private static function exportSqlNative(): string
    {
        try {
            $db    = self::db();
            $tables = $db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            if (!$tables) {
                return '';
            }

            $out  = "-- Cairo Store Database Backup\n";
            $out .= "-- Generated: " . date('Y-m-d H:i:s') . " (PHP native export)\n";
            $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

            foreach ($tables as $table) {
                $stmt = $db->query("SHOW CREATE TABLE `{$table}`");
                $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
                $out .= "-- --------------------------------------------------------\n";
                $out .= "-- Table structure: `{$table}`\n";
                $out .= "-- --------------------------------------------------------\n";
                $out .= ($row['Create Table'] ?? '') . ";\n\n";

                $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
                if (!$rows) {
                    continue;
                }

                $out .= "-- Data: `{$table}`\n";
                foreach ($rows as $row) {
                    $cols  = array_map(fn(string $c) => "`{$c}`", array_keys($row));
                    $vals  = implode(',', array_map(function ($v) use ($db) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        if (is_int($v) || is_float($v)) {
                            return (string)$v;
                        }
                        return $db->quote((string)$v);
                    }, array_values($row)));
                    $out .= "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES ({$vals});\n";
                }
                $out .= "\n";
            }

            $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
            return $out;
        } catch (Exception $e) {
            error_log("BackupModel::exportSqlNative Error: " . $e->getMessage());
            return '';
        }
    }

    /**
     * قائمة النسخ الموجودة (الاسم، الحجم بالبايت + بصيغة مقروءة، التاريخ).
     *
     * @return list<array<string, mixed>>
     */
    public static function listBackups(): array
    {
        $dir = self::getDir();
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/' . self::PREFIX . '*.sql');
        if (!$files) {
            return [];
        }

        $result = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $result[] = [
                'filename'  => basename($file),
                'size'      => filesize($file),
                'size_human' => self::formatBytes((int)filesize($file)),
                'date'      => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }

        usort($result, fn($a, $b) => strcmp($b['filename'], $a['filename']));
        return $result;
    }

    /**
     * حذف نسخة — يقبل اسم ملف مفهرس فقط (عبر getBackupPath).
     */
    public static function deleteBackup(string $filename): bool
    {
        $path = self::getBackupPath($filename);
        if ($path === null) {
            return false;
        }
        // getBackupPath هي الحارس: تشترط basename($f) === $f، وتطابق
        // نمطاً صارماً للاسم، وتتحقق is_file — فما يصل هنا مسار مفهرس
        // داخل مجلد النسخ لا غير.
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        return @unlink($path);
    }

    /**
     * مسار آمن داخل مجلد الباك اب — يرفض أي Path Traversal.
     * يُقبل الاسم فقط لو: basename() مطابق تمامًا + بالصيغة المطلوبة + ملف موجود.
     */
    public static function getBackupPath(string $filename): ?string
    {
        if (basename($filename) !== $filename) {
            return null;
        }
        if (!preg_match(self::PATTERN, $filename)) {
            return null;
        }

        $path = self::getDir() . DIRECTORY_SEPARATOR . $filename;
        return is_file($path) ? $path : null;
    }

    /** مجلد التخزين (يبقى خارج public/ — الدفاع الأساسي). */
    public static function getDir(): string
    {
        return self::DIR;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
