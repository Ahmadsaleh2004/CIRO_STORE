<?php

/**
 * tests/phpstan-bootstrap.php
 * The run-time constants — for the static analysis alone.
 *
 * config.php defines these constants from .env, but PHPStan does not execute that file
 * (and executing it would open a database connection during analysis). So they are declared
 * here with dummy values, to tell the analyser they exist and what their types are.
 *
 * ⚠️ The values here **never reach any running code**. Put nothing real in them.
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
