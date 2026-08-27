<?php

/**
 * tests/phpstan-bootstrap.php
 * ثوابت وقت التشغيل — لأجل التحليل الثابت وحده.
 *
 * config.php يعرّف هذه الثوابت من .env، لكن PHPStan لا ينفّذ الملف
 * (وتنفيذه سيفتح اتصالاً بقاعدة البيانات أثناء التحليل). فتُعلَن هنا
 * بقيم وهمية كي يعرف المحلّل أنها موجودة وما أنواعها.
 *
 * ⚠️ القيم هنا **لا تصل إلى أي كود يعمل**. لا تضع فيها شيئاً حقيقياً.
 */

define('APPROOT', __DIR__ . '/../app');
define('ROOTPATH', __DIR__ . '/..');
define('URLROOT', 'http://localhost');
define('SITENAME', 'Cairo Store');
define('APP_ENV', 'testing');
define('APP_DEBUG', false);
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'analysis_only');
define('DB_USER', 'analysis_only');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
