<?php

/**
 * build/php-server-router.php
 * The router for PHP's built-in server, as CI's Lighthouse job runs the site:
 *
 *     php -S 127.0.0.1:8080 -t public build/php-server-router.php
 *
 * ── Why it exists ────────────────────────────────────────────
 *
 * The job used to pass public/index.php as the router. And the built-in server hands every
 * request to its router, files included, unless the router returns false — which index.php
 * never does. So every stylesheet, script and image answered 404 under Lighthouse, and for a
 * long time nothing showed it: Bootstrap came from a CDN, and was enough on its own to make
 * the page look styled. When Bootstrap moved to our own origin it 404'd with the rest, the
 * modals rendered unhidden with their focusable fields inside aria-hidden, the nav links lost
 * their padding, and /products fell to 0.87 on accessibility — measuring a page with no CSS
 * that no visitor ever sees.
 *
 * Apache, which serves the real site, never had the fault: .htaccess routes only what is not
 * a file. This file is that same rule for the built-in server, and nothing in production
 * reads it.
 */

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// A real file under public/ is served by the built-in server itself. realpath() and the
// prefix check keep a path with ".." from reaching outside public/.
$public = realpath(__DIR__ . '/../public');
$file   = realpath($public . $path);

if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, $public . DIRECTORY_SEPARATOR)) {
    return false;
}

require $public . '/index.php';
