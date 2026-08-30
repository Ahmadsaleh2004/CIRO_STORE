<?php

// ==========================================
// 1. Path constants
// ==========================================
//
// Paths first, because loading .env below needs ROOTPATH.

// Filesystem path to the main app directory
define('APPROOT', dirname(__DIR__));

// Filesystem path to the project root
define('ROOTPATH', dirname(dirname(__DIR__)));

// ==========================================
// 2. Loading the environment (.env)
// ==========================================
//
// Loaded from **here**, not from the entry point alone. public/index.php used to
// call loadEnv itself, while a script under scripts/ loaded config.php directly
// without calling it — so it ran with an empty environment and connected to a
// database nobody intended, **silently**. Making config.php self-sufficient closes
// that door for every entry point, existing or future.
//
// (That script — reset_admins_keep_root — was later deleted, because it wiped
// every admin except root, and such a tool has no place in a production
// repository. The lesson stayed, though: an entry point should not have to
// remember anything.)
//
// loadEnv carries a `static $loaded` guard, so calling it again from
// public/index.php does nothing and overwrites nothing already loaded.
require_once __DIR__ . '/env_loader.php';
loadEnv(ROOTPATH . '/.env');

// ==========================================
// 3. Environment and error reporting
// ==========================================
//
// There used to be an unconditional `ini_set('display_errors', 1)` here — meaning
// every exception trace, server paths, database names and query text included,
// would have been printed to visitors in production. Display is now conditional on
// the environment while logging is permanent: the developer sees the error on
// their screen, the server writes it to its log, and the visitor sees nothing but
// a clean error page.

define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', envBool('APP_DEBUG', APP_ENV !== 'production'));

// The safe default is deliberate: a missing APP_ENV means **production**, not
// development. Forgetting to set the variable should hide errors, not reveal them.
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

// Logging is permanent in both cases — turning display off does not mean losing the error.
ini_set('log_errors', '1');

// ── Error monitoring ─────────────────────────────────────────
//
// It belongs here rather than in public/index.php: config.php is loaded from
// **every** entry point — the router and every script under scripts/ — and it is
// the one place guaranteed to run before all of them. Monitoring that does not
// cover a script running with no human watching (the migrator, the mail queue)
// covers the least of what needs covering.
//
// And it comes **after** the error-logging setup above and before anything else:
// the earliest point at which it can catch a fault, yet still after the local log
// is ready — so if sending fails, the trace survives on disk.
//
// Without SENTRY_DSN in .env, this line does nothing whatsoever.
require_once __DIR__ . '/monitoring.php';
initMonitoring();

// Hide the PHP version. `Header always unset X-Powered-By` in .htaccess is not
// enough: PHP adds the header after Apache has processed its mod_headers
// directives, so it survives them (measured — `X-Powered-By: PHP/8.2.12` remained
// visible). The root fix, `expose_php = Off` in php.ini, is a server setting the
// repository does not own, so this stays the one place the application can
// guarantee for itself.
header_remove('X-Powered-By');
if (is_dir(ROOTPATH . '/storage')) {
    ini_set('error_log', ROOTPATH . '/storage/php-error.log');
}

// ==========================================
// 4. Site identity
// ==========================================

// The site's root URL as the browser reaches it.
// It comes from APP_URL so that deploying never requires editing a code file. The
// default is the local development path, so an existing XAMPP setup works with no
// change to .env.
define('URLROOT', rtrim(env('APP_URL', 'http://localhost/STORE/public'), '/'));

// The store name
define('SITENAME', env('APP_NAME', 'Cairo Store'));

// ==========================================
// 5. Database configuration
// ==========================================
//
// The values used to be written out here (`DB_USER = 'root'`, `DB_PASS = ''`) and
// they **took precedence** over .env: Database.php checks `defined('DB_USER')`
// first, so the .env file existed with no effect — read and then ignored.
//
// Now .env is the only source, and the constants are merely a copy read from it.
// They were kept as constants rather than direct reads because
// BackupModel::createDump uses them in a mysqldump options file; converting those
// to $_ENV would have been a needless change on a path that handles the password.
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_DATABASE', 'store_db'));
define('DB_USER', env('DB_USERNAME', 'root'));
define('DB_PASS', $_ENV['DB_PASSWORD'] ?? '');  // ← not env(): an empty password is a valid value here
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// ==========================================
// 6. Session hardening
// ==========================================
//
// These belong here rather than in auth_helper: both settings must be applied
// **before** any session_start(), and sessions begin in many places
// (startAdminSession, direct session_start lines in AdminAuthController, and the
// store session). config.php is loaded from public/index.php before the router and
// from every script under scripts/ — so it is the one place guaranteed to run
// before all of them.

// A session id the server did not generate is rejected and replaced. Without
// this, an attacker can plant an id they know (through a link, or a cookie on a
// subdomain) and then wait for the victim to sign in with it — session fixation.
ini_set('session.use_strict_mode', '1');

// `secure` follows the actual protocol: pinning it to true over http stops the
// cookie being sent at all, which breaks the entire session in local development.
$httpsOn = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',

    // httponly: zero places in the JavaScript layer read document.cookie
    // (verified), so hiding the cookie from scripts breaks nothing and cuts off
    // session theft through any future XSS.
    'httponly' => true,

    // samesite: Lax **not Strict**, deliberately. Strict stops the cookie being
    // sent with any navigation arriving from another site — and the Google OAuth
    // return (/auth/google/callback) is exactly a navigation from the google.com
    // domain, so the session would arrive empty and Google sign-in would fail. Lax
    // permits that for top-level GET navigations alone, and still blocks
    // cross-site POST requests — which are the actual CSRF path.
    'samesite' => 'Lax',

    'secure'   => $httpsOn,
]);
