<?php

namespace Tests\Unit;

use App\Core\Route;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * The router — the entry point of every request reaching the project, with 104 routes
 * passing through it.
 *
 * It had not one test. And that is not an organisational remark: the path matching is built
 * as a regex from the path's text, and an error in it means either a route that is never
 * reached or — more dangerously — a route reached by a path that was never intended.
 *
 * The tests here touch neither the network nor the database: they examine registration,
 * matching and URL building. The actual dispatch ends the process with an exit inside
 * ErrorPage, so it is covered over HTTP in scripts/smoke-test.php.
 */
final class RouterTest extends TestCase
{
    private function router(): Router
    {
        return new Router();
    }

    /** Extracts the matching route without running it — by reaching matchPath. */
    private function findRoute(Router $router, string $method, string $path): ?Route
    {
        $match = new \ReflectionMethod(Router::class, 'matchPath');
        $match->setAccessible(true);

        foreach ($router->getRoutes() as $route) {
            $params = [];
            if (
                $route->getMethod() === strtoupper($method)
                && $match->invokeArgs($router, [$route->getPath(), $path, &$params])
            ) {
                return $route;
            }
        }

        return null;
    }

    // ── Registration ─────────────────────────────────────────

    public function testRegistersEveryHttpMethod(): void
    {
        $r = $this->router();
        $handler = static function (): void {
        };

        $r->get('/a', $handler);
        $r->post('/b', $handler);
        $r->put('/c', $handler);
        $r->patch('/d', $handler);
        $r->delete('/e', $handler);

        $methods = array_map(static fn (Route $x): string => $x->getMethod(), $r->getRoutes());

        // PUT/PATCH/DELETE were not supported at all before this phase, so every update or
        // delete operation was forced to be a POST.
        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $methods);
    }

    public function testRoutesAreChainable(): void
    {
        $r = $this->router();
        $route = $r->get('/x', static function (): void {
        })->name('x.index')->middleware('auth');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('x.index', $route->getName());
        $this->assertSame(['auth'], $route->getMiddleware());
    }

    // ── Matching ─────────────────────────────────────────────

    public function testMatchesAnExactPath(): void
    {
        $r = $this->router();
        $r->get('/products', static function (): void {
        });

        $this->assertNotNull($this->findRoute($r, 'GET', '/products'));
        $this->assertNull($this->findRoute($r, 'GET', '/product'));
        $this->assertNull($this->findRoute($r, 'GET', '/products/extra'));
    }

    public function testMethodIsPartOfTheMatch(): void
    {
        $r = $this->router();
        $r->get('/only-get', static function (): void {
        });

        $this->assertNotNull($this->findRoute($r, 'GET', '/only-get'));
        $this->assertNull($this->findRoute($r, 'POST', '/only-get'));
    }

    /**
     * HEAD is treated as GET.
     *
     * The standard (RFC 9110 §9.3.2) requires that every resource supporting GET supports
     * HEAD. Without that, **every route in the project** answered HEAD with a 404 — and
     * monitoring tools, health checkers and load balancers use it because it is cheaper, so
     * all of them would have read the site as dead while it was alive.
     *
     * And the evidence was in the project's own log: "[Cairo Store] 404: HEAD /".
     */
    public function testHeadIsTreatedAsGet(): void
    {
        $r = $this->router();
        $r->get('/health', static function (): void {
        });

        $normalize = new \ReflectionMethod(Router::class, 'normalizePath');
        $normalize->setAccessible(true);

        // dispatch ends execution, so the normalisation and the matching are examined
        // together in its place.
        $this->assertNotNull($this->findRoute($r, 'GET', '/health'));
        $this->assertNull(
            $this->findRoute($r, 'HEAD', '/health'),
            'The matching itself does not know HEAD — the conversion happens in dispatch.'
        );

        // What actually matters: that dispatch converts HEAD to GET before matching.
        $source = file_get_contents((new \ReflectionClass(Router::class))->getFileName());
        $this->assertStringContainsString(
            "if (\$requestMethod === 'HEAD')",
            (string) $source,
            'The HEAD-to-GET conversion has disappeared from dispatch.'
        );
    }

    public function testMethodMatchingIsCaseInsensitive(): void
    {
        $r = $this->router();
        $r->post('/p', static function (): void {
        });

        // Some proxies send the method in lower case; rejecting it is a fault, not
        // protection.
        $this->assertNotNull($this->findRoute($r, 'post', '/p'));
    }

    /**
     * The most important test in the file.
     *
     * The previous version built the pattern from the raw path text with no escaping:
     *     $pattern = preg_replace('/\{(\w+)\}/', '([^/]+)', $routePath);
     * so the dot in a path like /handlers/notify_handler.php stayed a regex dot matching
     * **any character** — that is, /handlers/notify_handlerXphp reached the same endpoint.
     * And that is a path actually registered in this project.
     */
    public function testLiteralDotsAreNotTreatedAsRegexWildcards(): void
    {
        $r = $this->router();
        $r->post('/handlers/notify_handler.php', static function (): void {
        });

        $this->assertNotNull($this->findRoute($r, 'POST', '/handlers/notify_handler.php'));
        $this->assertNull(
            $this->findRoute($r, 'POST', '/handlers/notify_handlerXphp'),
            'The dot was treated as a regex metacharacter — an unintended path became reachable.'
        );
    }

    public function testExtractsPathParameters(): void
    {
        $r = $this->router();
        $r->get('/product/{id}', static function (): void {
        });

        $match = new \ReflectionMethod(Router::class, 'matchPath');
        $match->setAccessible(true);

        $params = [];
        $ok = $match->invokeArgs($r, ['/product/{id}', '/product/42', &$params]);

        $this->assertTrue($ok);
        $this->assertSame(['42'], $params);
    }

    public function testAParameterDoesNotSpanASlash(): void
    {
        $r = $this->router();
        $r->get('/product/{id}', static function (): void {
        });

        // {id} means "one segment", so were it to match the slash, a single route would
        // swallow a whole tree of routes beneath it.
        $this->assertNull($this->findRoute($r, 'GET', '/product/42/reviews'));
    }

    // ── Groups ───────────────────────────────────────────────

    public function testGroupAppliesPrefixAndMiddleware(): void
    {
        $r = $this->router();
        $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
            $r->get('/users', static function (): void {
            });
        });

        $routes = $r->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertSame('/admin/users', $routes[0]->getPath());
        $this->assertSame(['admin'], $routes[0]->getMiddleware());
    }

    public function testGroupMiddlewareCombinesWithRouteMiddleware(): void
    {
        $r = $this->router();
        $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
            $r->post('/products/delete', static function (): void {
            })->middleware('perm:can_manage_products');
        });

        $this->assertSame(
            ['admin', 'perm:can_manage_products'],
            $r->getRoutes()[0]->getMiddleware(),
            'The group\'s guard must precede the route\'s — the more general before the more specific.'
        );
    }

    public function testGroupsNest(): void
    {
        $r = $this->router();
        $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
            $r->group(['prefix' => '/orders', 'middleware' => ['perm:can_manage_orders']], static function (Router $r): void {
                $r->post('/take', static function (): void {
                });
            });
        });

        $route = $r->getRoutes()[0];
        $this->assertSame('/admin/orders/take', $route->getPath());
        $this->assertSame(['admin', 'perm:can_manage_orders'], $route->getMiddleware());
    }

    public function testGroupStackUnwindsAfterTheGroupCloses(): void
    {
        $r = $this->router();
        $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
            $r->get('/inside', static function (): void {
            });
        });
        $r->get('/outside', static function (): void {
        });

        $routes = $r->getRoutes();
        $this->assertSame('/admin/inside', $routes[0]->getPath());
        $this->assertSame('/outside', $routes[1]->getPath(), 'The group\'s prefix leaked past it.');
        $this->assertSame([], $routes[1]->getMiddleware(), 'The group\'s guard leaked past it.');
    }

    // ── The guards ───────────────────────────────────────────

    public function testUnknownMiddlewareNameThrows(): void
    {
        $r = $this->router();
        $route = $r->get('/x', static function (): void {
        })->middleware('totally-made-up');

        $run = new \ReflectionMethod(Router::class, 'runMiddleware');
        $run->setAccessible(true);

        // Failing loudly is deliberate: a misspelled guard means an unprotected route, and
        // ignoring it silently is the worst thing that can be done here.
        $this->expectException(\InvalidArgumentException::class);
        $run->invoke($r, $route);
    }

    // ── Names ────────────────────────────────────────────────

    public function testUnknownRouteNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->router()->route('nope.not.here');
    }

    /**
     * Building a URL from a name actually works.
     *
     * The first version of this function searched a $named map that nobody filled: the name
     * is set **after** registration through ->name(), so addRoute has no way to know it. So
     * route() threw on every name however correct — a function that never worked, with no
     * test revealing it because the one test checked the throw on an **unknown** name, which
     * is what it did in both cases.
     */
    public function testBuildsAUrlFromARouteName(): void
    {
        $r = $this->router();
        $r->get('/products', static function (): void {
        })->name('products.index');

        $this->assertSame(URLROOT . '/products', $r->route('products.index'));
    }

    public function testSubstitutesPathParametersWhenBuildingAUrl(): void
    {
        $r = $this->router();
        $r->get('/product/{id}', static function (): void {
        })->name('product.show');

        $this->assertSame(URLROOT . '/product/42', $r->route('product.show', ['id' => 42]));
    }

    public function testEncodesParameterValuesInBuiltUrls(): void
    {
        $r = $this->router();
        $r->get('/search/{term}', static function (): void {
        })->name('search');

        // An unencoded value breaks the URL or opens the door to path injection.
        $this->assertSame(URLROOT . '/search/a%2Fb%20c', $r->route('search', ['term' => 'a/b c']));
    }
}
