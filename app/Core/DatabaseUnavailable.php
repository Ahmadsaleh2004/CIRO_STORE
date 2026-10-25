<?php

namespace App\Core;

/**
 * The database could not be reached — thrown by Database's constructor.
 *
 * ⚠️ It extends \Error, not \Exception, and that choice is the whole point of the class.
 *
 * The models wrap their queries in `catch (Exception $e)` — over a hundred and fifty of
 * them — and turn a failure into an empty result: no products, no cart, a "wrong password".
 * That is the right reaction to one bad query. It is the wrong reaction to the database
 * being gone: the products page would render "no products", the cart would empty itself on
 * screen, and a sign-in would answer "email or password is incorrect" — counting a failed
 * attempt towards a lockout for something the visitor did not do.
 *
 * Before this class existed, the constructor ended the request on the spot with a 503, so
 * none of those catches ever ran. That stopped the damage but also stopped the one caller
 * that genuinely needs to know: /health, whose `try/catch` could never fire, so a monitor
 * received an HTML error page instead of {"status":"fail"}.
 *
 * Extending \Error keeps both properties. `catch (Exception)` does not match it, so every
 * model behaves exactly as before and the request still ends in the 503 page — the global
 * handler in app/config/monitoring.php maps this class to that page. `catch (Throwable)` or
 * a catch naming this class does match it, so the callers that want to degrade gracefully
 * (/health, the About page's product count) now can.
 *
 * The message carries the PDO error, which names the host, the database and the user. It is
 * logged, never shown: ErrorPage::serverError() treats its argument as log detail only.
 */
final class DatabaseUnavailable extends \Error
{
}
