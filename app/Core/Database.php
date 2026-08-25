<?php

namespace App\Core;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct()
    {
        // قراءة الإعدادات من الثوابت (config.php) أو من $_ENV مع وجود قيم افتراضية
        $host    = defined('DB_HOST')    ? DB_HOST    : ($_ENV['DB_HOST']    ?? '127.0.0.1');
        $port    = defined('DB_PORT')    ? DB_PORT    : ($_ENV['DB_PORT']    ?? '3306');
        $db      = defined('DB_NAME')    ? DB_NAME    : ($_ENV['DB_DATABASE'] ?? 'store_db');
        $user    = defined('DB_USER')    ? DB_USER    : ($_ENV['DB_USERNAME'] ?? 'root');
        $pass    = defined('DB_PASS')    ? DB_PASS    : ($_ENV['DB_PASSWORD'] ?? '');
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            die("خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage());
        }
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton instance.");
    }

    /**
     * الحصول على النسخة الوحيدة من كلاس Database (Singleton Gateway)
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * الحصول على كائن الـ PDO
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * اختصار استدعاء سريع للحصول على PDO مباشرة: Database::connect()
     */
    public static function connect(): PDO
    {
        return self::getInstance()->getConnection();
    }
}