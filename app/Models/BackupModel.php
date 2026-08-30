<?php

namespace App\Models;

use App\Core\Model;
use Exception;

/**
 * BackupModel — SQL database backups.
 * Storage: <root>/storage/backups (entirely outside public/, plus .htaccess protection).
 * Creation: it tries mysqldump through exec() first, and on failure falls back to a
 * PHP-native export (SHOW CREATE TABLE + SELECT * per table).
 */
class BackupModel extends Model
{
    private const DIR    = ROOTPATH . '/storage/backups';
    private const PREFIX = 'backup_';
    private const PATTERN = '/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/';

    /**
     * Create a new backup.
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

        // 1) Try mysqldump through exec() — the standard route
        $dump = self::tryMysqldump($path);
        if ($dump['success'] && is_file($path) && filesize($path) > 0) {
            return ['success' => true, 'filename' => $filename, 'message' => 'Backup created via mysqldump.'];
        }

        // 2) Fallback: a manual SQL export through PDO
        $sql = self::exportSqlNative();
        // exportSqlNative is declared `: string`, so is_string was always true.
        if ($sql === '') {
            return ['success' => false, 'filename' => null, 'message' => 'Both mysqldump and native export failed.'];
        }

        if (@file_put_contents($path, $sql) === false) {
            return ['success' => false, 'filename' => null, 'message' => 'Failed to write backup file.'];
        }

        return ['success' => true, 'filename' => $filename, 'message' => 'Backup created via PHP native export.'];
    }

    /**
     * Try to run mysqldump — it attempts several common paths for the binary.
     *
     * @return array{success: bool, message?: string} — only success is guaranteed; the message is attached on failure
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

        // ── The password is never passed on the command line ───────
        //
        // It used to be passed as --password=… . And any process's command line is
        // **readable by every user on the machine**: `ps aux` on Linux, and Task Manager
        // or Get-CimInstance Win32_Process on Windows. mysqldump itself warns about this.
        // Escaping with escapeshellarg prevents injection; it does not prevent reading —
        // two different problems.
        //
        // The alternative: a temporary options file that mysqldump reads with
        // --defaults-extra-file, which must be the **first** argument or it is ignored.
        // It is deleted in a finally block whatever happens.
        $cnfPath = null;
        try {
            $cnfPath = tempnam(sys_get_temp_dir(), 'cs_dump_');
            if ($cnfPath === false) {
                return ['success' => false];
            }

            // Tight permissions before writing (no effect on Windows, but necessary on
            // Linux, where tempnam already gives 0600 — setting it explicitly is clearer).
            @chmod($cnfPath, 0600);

            // Values inside an ini file are not escaped with escapeshellarg — they are
            // quoted, with only \ and " escaped, which is what a my.cnf reader understands.
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
                // The command carries no user input: $bin comes from a list of paths written
                // in this file, $name from the DB_NAME constant, and $path and $cnfPath are
                // generated internally — and all of them pass through escapeshellarg. The
                // password is no longer on the command line at all.
                // nosemgrep: php.lang.security.exec-use.exec-use
                @exec($cmd, $_, $code);
                if ($code === 0 && is_file($path) && filesize($path) > 0) {
                    return ['success' => true];
                }
                // $path is generated in createBackup from a timestamp; there is no input in it.
                // nosemgrep: php.lang.security.unlink-use.unlink-use,cairo-unlink-unvalidated-path
                @unlink($path); // Clear any partial file before the next attempt
            }

            return ['success' => false];
        } finally {
            // The file carries the password — it must not be left in the temp directory,
            // even if everything failed or an exception was thrown.
            if ($cnfPath !== null && is_file($cnfPath)) {
                // $cnfPath comes from tempnam() in this method — no user input.
                // nosemgrep: php.lang.security.unlink-use.unlink-use,cairo-unlink-unvalidated-path
                @unlink($cnfPath);
            }
        }
    }

    /**
     * A complete manual SQL export through PDO (the fallback when mysqldump is unavailable).
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
     * The list of existing backups (name, size in bytes and in a readable form, date).
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
     * Delete a backup — it accepts only a resolved file name (through getBackupPath).
     */
    public static function deleteBackup(string $filename): bool
    {
        $path = self::getBackupPath($filename);
        if ($path === null) {
            return false;
        }
        // getBackupPath is the guard: it requires basename($f) === $f, matches a strict
        // name pattern, and checks is_file — so what reaches here is a path resolved
        // inside the backup directory and nothing else.
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        return @unlink($path);
    }

    /**
     * A safe path inside the backup directory — it refuses any path traversal.
     * A name is accepted only if: basename() matches it exactly, it is in the required
     * form, and the file exists.
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

    /** The storage directory (it stays outside public/ — the primary defence). */
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
