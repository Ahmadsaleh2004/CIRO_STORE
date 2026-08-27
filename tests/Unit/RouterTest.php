<?php

namespace Tests\Unit;

use App\Core\Route;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * الراوتر — مدخل كل طلب يصل إلى المشروع، و104 مسار تمرّ عبره.
 *
 * كان بلا اختبار واحد. وهذه ليست ملاحظة تنظيمية: مطابقة المسار تُبنى
 * بـregex من نصّ المسار، وخطأ فيها يعني إما راوتاً لا يُبلَغ أبداً أو —
 * وهو الأخطر — راوتاً يُبلَغ بمسار لم يُقصد.
 *
 * الاختبارات هنا لا تلمس الشبكة ولا القاعدة: تفحص التسجيل والمطابقة
 * وبناء الروابط. أما التنفيذ الفعلي (dispatch) فيوقف العملية بـexit
 * داخل ErrorPage، فيُغطّى عبر HTTP في scripts/smoke-test.php.
 */
final class RouterTest extends TestCase
{
    private function router(): Router
    {
        return new Router();
    }

    /** يستخرج المسار المطابق دون تنفيذه — عبر الوصول إلى matchPath. */
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

    // ── التسجيل ──────────────────────────────────────────────

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

        // PUT/PATCH/DELETE لم تكن مدعومة إطلاقاً قبل هذه المرحلة، فكانت
        // كل عملية تعديل أو حذف مضطرّة لأن تكون POST.
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

    // ── المطابقة ─────────────────────────────────────────────

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

    public function testMethodMatchingIsCaseInsensitive(): void
    {
        $r = $this->router();
        $r->post('/p', static function (): void {
        });

        // بعض الوكلاء يرسلون الطريقة بحروف صغيرة؛ رفضها عطل لا حماية.
        $this->assertNotNull($this->findRoute($r, 'post', '/p'));
    }

    /**
     * أخطر اختبار في الملف.
     *
     * النسخة السابقة كانت تبني النمط من نصّ المسار الخام بلا تهريب:
     *     $pattern = preg_replace('/\{(\w+)\}/', '([^/]+)', $routePath);
     * فالنقطة في مسار مثل /handlers/notify_handler.php تبقى نقطةَ regex
     * تطابق **أي محرف** — أي أن /handlers/notify_handlerXphp كان يصل
     * إلى النقطة نفسها. وهذا مسار مسجَّل فعلاً في هذا المشروع.
     */
    public function testLiteralDotsAreNotTreatedAsRegexWildcards(): void
    {
        $r = $this->router();
        $r->post('/handlers/notify_handler.php', static function (): void {
        });

        $this->assertNotNull($this->findRoute($r, 'POST', '/handlers/notify_handler.php'));
        $this->assertNull(
            $this->findRoute($r, 'POST', '/handlers/notify_handlerXphp'),
            'النقطة عوملت كرمز regex — مسار لم يُقصد صار قابلاً للبلوغ.'
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

        // {id} تعني «مقطعاً واحداً»، فلو طابقت الشرطة لابتلع مسارٌ واحد
        // شجرةَ مسارات كاملة تحته.
        $this->assertNull($this->findRoute($r, 'GET', '/product/42/reviews'));
    }

    // ── المجموعات ────────────────────────────────────────────

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
            'حارس المجموعة يجب أن يسبق حارس المسار — الأعمّ قبل الأخصّ.'
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
        $this->assertSame('/outside', $routes[1]->getPath(), 'بادئة المجموعة تسرّبت إلى ما بعدها.');
        $this->assertSame([], $routes[1]->getMiddleware(), 'حارس المجموعة تسرّب إلى ما بعدها.');
    }

    // ── الحُرّاس ─────────────────────────────────────────────

    public function testUnknownMiddlewareNameThrows(): void
    {
        $r = $this->router();
        $route = $r->get('/x', static function (): void {
        })->middleware('totally-made-up');

        $run = new \ReflectionMethod(Router::class, 'runMiddleware');
        $run->setAccessible(true);

        // الفشل الصاخب مقصود: حارس مكتوب خطأً يعني مساراً بلا حماية،
        // وتجاهله بصمت أسوأ ما يمكن فعله هنا.
        $this->expectException(\InvalidArgumentException::class);
        $run->invoke($r, $route);
    }

    // ── الأسماء ──────────────────────────────────────────────

    public function testUnknownRouteNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->router()->route('nope.not.here');
    }

    /**
     * بناء الرابط من الاسم يعمل فعلاً.
     *
     * النسخة الأولى من هذه الدالة كانت تبحث في خريطة $named لا يملؤها
     * أحد: الاسم يُضبط **بعد** التسجيل عبر ->name()، فلا سبيل لأن يعرفه
     * addRoute. فكانت route() ترمي على كل اسم مهما كان صحيحاً — دالة
     * لا تعمل أبداً، ولا اختبار يكشف ذلك لأن الاختبار الوحيد كان يفحص
     * الرمي على اسم **مجهول**، وهو ما كانت تفعله في الحالتين.
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

        // قيمة غير مُرمَّزة تكسر الرابط أو تفتح باب حقن مسار.
        $this->assertSame(URLROOT . '/search/a%2Fb%20c', $r->route('search', ['term' => 'a/b c']));
    }
}
