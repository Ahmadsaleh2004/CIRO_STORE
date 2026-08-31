<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guard parity — between the route table and the action bodies.
 *
 * Guarding in this project is now declared in two places:
 *
 *   1. the route table in public/index.php  →  ->middleware('perm:x')
 *   2. the action's body in the controller  →  Middleware::requirePermission('x')
 *
 * The duplication is **deliberate and temporary**. Moving the guarding onto the route is the
 * right direction (the guard runs before the controller is constructed, not after), but
 * removing the internal check in the same step would have turned any mistake in the move
 * into a silent hole. With both in place, the new guard cannot be **weaker** than the old —
 * the worst that can happen is that it is stricter, and that shows up immediately as a 403.
 *
 * And this test is what makes the duplication safe rather than dangerous: it derives both
 * sides from their sources and compares them. So if either drifts — a route added without a
 * guard, or an action's permission changed without changing the route's — the build fails.
 *
 * When the internal checks are removed later, the first half of this file remains a guard
 * that every admin route declares its permission.
 */
final class RouteGuardParityTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Removes the PHP comments and keeps the code.
     *
     * token_get_all rather than a regex: a comment inside a string ("// not a comment" between
     * quotes), a nowdoc and multi-line strings all break any regular expression, and only the
     * language's own lexer knows the difference.
     */
    private static function stripComments(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * The permission each action declares in its body.
     *
     * @return array<string, string> "Controller::action" => "perm:x" | "auth"
     */
    private static function guardsInActionBodies(): array
    {
        $out = [];

        foreach (glob(self::root() . '/app/Controllers/*.php') as $file) {
            $src = (string) file_get_contents($file);
            $class = basename($file, '.php');

            $parts = preg_split('/\n    public function (\w+)\s*\(/', $src, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts === false) {
                continue;
            }

            for ($i = 1; $i < count($parts); $i += 2) {
                $action = $parts[$i];
                $body   = $parts[$i + 1] ?? '';

                if (preg_match("/Middleware::requirePermission\('([^']+)'\)/", $body, $m)) {
                    $out["{$class}::{$action}"] = 'perm:' . $m[1];
                } elseif (str_contains($body, 'Middleware::requireRoot()')) {
                    $out["{$class}::{$action}"] = 'root';
                } elseif (str_contains($body, 'Middleware::requireLogin()')) {
                    $out["{$class}::{$action}"] = 'auth';
                }
            }
        }

        return $out;
    }

    /**
     * The guard declared in the route table for each action.
     *
     * @return array<string, string>
     */
    private static function guardsInRouteTable(): array
    {
        $src = (string) file_get_contents(self::root() . '/public/index.php');

        preg_match_all(
            "/->(?:get|post|put|patch|delete)\(\s*'[^']+'\s*,\s*\[(\w+)::class,\s*'(\w+)'\]\s*\)"
            . "(\s*\n?\s*->middleware\('([^']+)'\))?/",
            $src,
            $matches,
            PREG_SET_ORDER
        );

        $out = [];
        foreach ($matches as $m) {
            // Group 4 is the guard's name, and it exists only when the optional
            // ->middleware(...) part matched. A `!== ''` check after isset was a condition
            // that never held: the pattern does not accept an empty name in the first place.
            if (!isset($m[4])) {
                continue;
            }

            $out[$m[1] . '::' . $m[2]] = $m[4];
        }

        return $out;
    }

    /**
     * Every action declaring a permission in its body has its route declare it too — with
     * the same value.
     */
    public function testRouteTableDeclaresTheSameGuardTheActionEnforces(): void
    {
        $inBody  = self::guardsInActionBodies();
        $inTable = self::guardsInRouteTable();

        $this->assertGreaterThan(40, count($inBody), 'The action-body reader did not find enough guards.');

        $problems = [];
        foreach ($inBody as $action => $guard) {
            if (!isset($inTable[$action])) {
                $problems[] = "{$action} — the action enforces [{$guard}] and the route declares nothing.";
                continue;
            }
            if ($inTable[$action] !== $guard) {
                $problems[] = sprintf(
                    '%s — the route declares [%s] and the action enforces [%s].',
                    $action,
                    $inTable[$action],
                    $guard
                );
            }
        }

        $this->assertSame(
            [],
            $problems,
            "Divergence between the route table and the action bodies:\n  " . implode("\n  ", $problems)
        );
    }

    /**
     * And the reverse: no route declares a guard its action does not enforce.
     *
     * This direction catches the case most damaging to use: a route declaring a stricter
     * permission than the action actually needs, so an admin is barred from a page they hold
     * the right to.
     */
    public function testNoRouteDeclaresAGuardItsActionDoesNotEnforce(): void
    {
        $inBody  = self::guardsInActionBodies();
        $inTable = self::guardsInRouteTable();

        $extra = [];
        foreach ($inTable as $action => $guard) {
            // The throttle is declared on the route alone with no counterpart in the body,
            // and that is its design rather than an oversight. The parity rule above concerns
            // the duplication of **authorisation** (perm/auth), which is a deliberate,
            // temporary duplication half of which is removed later; the throttle, meanwhile,
            // was born on the route from the first day: its right place is before the
            // controller is constructed, because it counts requests rather than their
            // outcomes — and a copy of it inside the body would count the request twice.
            if (str_starts_with($guard, 'throttle:')) {
                continue;
            }

            if (!isset($inBody[$action])) {
                $extra[] = "{$action} — the route declares [{$guard}] with no trace of it in the action's body.";
            }
        }

        $this->assertSame([], $extra, "Guards declared with no counterpart:\n  " . implode("\n  ", $extra));
    }

    /**
     * No authorisation hung on a literal id, and no renumbering of keys.
     *
     * It guards two faults that collapsed together in phase A-2:
     *
     *   · BackupController granted the right to download the entire database to
     *     `getCurrentAdminId() !== 1` — that is, to a position in a queue rather than to a
     *     person.
     *   · AdminModel::deleteAdmin shifted the ids across nine tables on every deletion,
     *     making that position a moving one.
     *
     * Either alone is tolerable; together they mean deleting a row silently transfers the
     * right to download the database to somebody else. The rule now: identity is a rank
     * (role='A'), and a key never moves.
     */
    public function testNoAuthorizationIsPinnedToALiteralIdAndNoKeyIsRenumbered(): void
    {
        $problems = [];

        $sources = array_merge(
            glob(self::root() . '/app/Controllers/*.php') ?: [],
            glob(self::root() . '/app/Models/*.php') ?: [],
            glob(self::root() . '/app/Core/*.php') ?: []
        );

        foreach ($sources as $file) {
            // The comments are stripped before the check. The forbidden pattern is named
            // verbatim in the documentation of the places that fixed it — and that
            // explanation is what prevents it coming back, so it must not trip the test into
            // a false positive. (scripts/audit.php learned the same rule when its <style>
            // counter jumped from 55 to 337 because of one comment explaining where the block
            // had moved to.)
            $src   = self::stripComments((string) file_get_contents($file));
            $label = basename($file);

            // Authorisation hung on a literal id: getCurrentAdminId() compared to a number.
            if (preg_match('/getCurrentAdminId\(\)\s*[!=]==?\s*\d+/', $src)) {
                $problems[] = "{$label} — authorisation hung on a literal id rather than a rank.";
            }

            // Renumbering a primary key.
            if (preg_match('/UPDATE\s+\w+\s+SET\s+id\s*=/i', $src)) {
                $problems[] = "{$label} — it renumbers a primary key; an id is an identity, not a position.";
            }

            // A reset AUTO_INCREMENT is the other face of renumbering, and it is also an
            // implicit commit that breaks any transaction around it.
            if (str_contains($src, 'AUTO_INCREMENT =') || str_contains($src, 'AUTO_INCREMENT=')) {
                $problems[] = "{$label} — it resets AUTO_INCREMENT (an implicit commit that breaks the transaction).";
            }
        }

        $this->assertSame([], $problems, "The id coupling has returned:\n  " . implode("\n  ", $problems));
    }

    /**
     * Every throttle guard is written in the form the router understands, with sensible
     * limits.
     *
     * Router::runMiddleware throws on a malformed form — but at request time. And a typo in a
     * number ("throttle:login,5" with no window, or a window of zero) means either a 500 page
     * for every visitor or a guard that lets everything through. Both are discovered here
     * rather than there.
     */
    public function testEveryThrottleGuardIsWellFormed(): void
    {
        $problems = [];

        foreach (self::guardsInRouteTable() as $action => $guard) {
            if (!str_starts_with($guard, 'throttle:')) {
                continue;
            }

            $args = explode(',', substr($guard, 9));

            if (count($args) !== 3) {
                $problems[] = "{$action} → [{$guard}] — the form is throttle:bucket,max,windowMinutes.";
                continue;
            }

            [$bucket, $max, $window] = $args;

            if (!preg_match('/^[a-z0-9-]+$/', $bucket)) {
                $problems[] = "{$action} → an invalid bucket name [{$bucket}].";
            }
            if ((int)$max < 1) {
                $problems[] = "{$action} → a limit of [{$max}] prevents nothing.";
            }
            if ((int)$window < 1) {
                $problems[] = "{$action} → a window of [{$window}] minutes empties the counter immediately.";
            }
        }

        $this->assertSame([], $problems, "Malformed throttle guards:\n  " . implode("\n  ", $problems));
    }

    /**
     * Every sensitive entry point is throttled — not one forgotten.
     *
     * The list is written out by name deliberately rather than derived: a derivation answers
     * "what is throttled?" while the question that guards is "what **must** be throttled?".
     * Somebody adding a new entry point and not throttling it breaks no derivation, but they
     * will run into this list when they add its name to it — and that is the right place for
     * the decision to be made.
     */
    public function testEverySensitiveEntryPointIsThrottled(): void
    {
        $mustBeThrottled = [
            'ContactController::send',
            'AuthController::login',
            'AuthController::register',
            'AuthController::forgot',
            'AuthController::resetSubmit',
            'AdminAuthController::login',
            'AdminAuthController::verify2FALogin',
            'AdminAuthController::forgotPassword',
            'AdminAuthController::reauth',
        ];

        $inTable = self::guardsInRouteTable();

        $missing = [];
        foreach ($mustBeThrottled as $action) {
            $guard = $inTable[$action] ?? '';
            if (!str_starts_with($guard, 'throttle:')) {
                $missing[] = $action;
            }
        }

        $this->assertSame([], $missing, "Sensitive entry points with no throttle:\n  " . implode("\n  ", $missing));
    }

    /**
     * Every guard name in use is known to the router.
     *
     * Router::runMiddleware throws on an unknown name — which is the right behaviour, since a
     * misspelled guard means an unprotected route. But the throw happens **at request time**,
     * which is to say the first to discover it is a visitor. This test discovers it at build
     * time.
     */
    public function testEveryDeclaredGuardNameIsRecognised(): void
    {
        $unknown = [];

        foreach (self::guardsInRouteTable() as $action => $guard) {
            $known = $guard === 'auth'
                || $guard === 'admin'
                || $guard === 'root'
                || str_starts_with($guard, 'perm:')
                || str_starts_with($guard, 'throttle:');

            if (!$known) {
                $unknown[] = "{$action} → [{$guard}]";
            }
        }

        $this->assertSame([], $unknown, "Guard names the router does not know:\n  " . implode("\n  ", $unknown));
    }

    /**
     * The permission names really do exist in the admin_permissions table.
     *
     * A misspelled permission (can_manage_order instead of can_manage_orders) passes
     * silently: hasPermission reads a key that does not exist and returns false, so every
     * admin except rank A is barred — and the problem looks like "the permissions do not
     * work" rather than "a spelling mistake".
     */
    public function testPermissionNamesExistInTheDatabaseSchema(): void
    {
        $schema = (string) file_get_contents(self::root() . '/tests/fixtures/schema.sql');

        $unknown = [];
        foreach (self::guardsInRouteTable() as $action => $guard) {
            if (!str_starts_with($guard, 'perm:')) {
                continue;
            }

            $permission = substr($guard, 5);
            if (!str_contains($schema, '`' . $permission . '`')) {
                $unknown[] = "{$action} → [{$permission}]";
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "Permission names with no column in admin_permissions:\n  " . implode("\n  ", $unknown)
        );
    }
}
