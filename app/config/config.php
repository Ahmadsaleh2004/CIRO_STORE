<?php

// ==========================================
// 1. إعدادات البيئة وتتبع الأخطاء (Error Reporting)
// ==========================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ==========================================
// 2. الثوابت الأساسية للمسارات (Path Constants)
// ==========================================

// مسار مجلد app الرئيسي على القرص الصلب (App Root)
define('APPROOT', dirname(__DIR__));

// مسار المجلد الرئيسي للمشروع (Project Root)
define('ROOTPATH', dirname(dirname(__DIR__)));

// رابط الموقع الرئيسي الذي يصل إليه المتصفح (URL Root)
define('URLROOT', 'http://localhost/STORE/public');

// اسم المتجر
define('SITENAME', 'Cairo Store');

// ==========================================
// 3. إعدادات قاعدة البيانات (Database Config)
// ==========================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ciro_db');
define('DB_CHARSET', 'utf8mb4');