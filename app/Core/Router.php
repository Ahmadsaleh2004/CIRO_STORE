<?php

namespace App\Core;

class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * سياق المجموعة المفتوحة حالياً: بادئة المسار وحُرّاسها.
     *
     * @var list<array{prefix: string, middleware: list<string>}>
     */
    private array $groupStack = [];

    public function get(string $path, callable|array $handler): Route
    {
        return $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): Route
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * PUT و PATCH و DELETE أُضيفت لأن غيابها كان يجبر كل عملية تعديل أو
     * حذف على أن تكون POST — فيصير جدول المسارات غير قادر على التمييز
     * بين «أنشئ» و«عدّل» و«احذف»، ويضيع نصف معنى الـHTTP.
     *
     * المشروع اليوم كله GET/POST، فالإضافة لا تغيّر سلوكاً قائماً.
     */
    public function put(string $path, callable|array $handler): Route
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    public function patch(string $path, callable|array $handler): Route
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): Route
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * مجموعة مسارات تتشارك بادئةً وحُرّاساً.
     *
     *     $r->group(['prefix' => '/admin', 'middleware' => ['admin']], function ($r) {
     *         $r->get('/users', [AdminUsersController::class, 'index']);
     *     });
     *
     * القيمة ليست الاختصار بل **أن السياسة تُعلَن مرّة**. كانت حراسة
     * الأدمن معلّقة على أن يرث كل كنترولر AdminController — أي أن نسيان
     * الوراثة في كنترولر جديد يفتح صفحاته للجميع بصمت. المجموعة تجعل
     * الحراسة صفةَ المسار لا صفةَ شجرة الوراثة.
     *
     * @param array{prefix?: string, middleware?: list<string>|string} $attributes
     */
    public function group(array $attributes, callable $definitions): void
    {
        $middleware = $attributes['middleware'] ?? [];

        $this->groupStack[] = [
            'prefix'     => rtrim($attributes['prefix'] ?? '', '/'),
            'middleware' => is_array($middleware) ? $middleware : [$middleware],
        ];

        $definitions($this);

        array_pop($this->groupStack);
    }

    /**
     * يبني رابط مسار مسمّى.
     *
     * @param array<string, string|int> $params قيم معاملات المسار {id} وأخواتها
     * @throws \InvalidArgumentException إن كان الاسم غير مسجَّل — وهو خطأ
     *         برمجي لا حالة وقت تشغيل، فالفشل الصاخب أفضل من رابط مكسور.
     */
    public function route(string $name, array $params = []): string
    {
        // البحث في القائمة لا في خريطة أسماء منفصلة، **وهذا ليس تفضيلاً**:
        // الاسم يُضبط بعد التسجيل عبر ->name()، فلا سبيل لأن يعرفه
        // addRoute. كانت هنا خريطة $named لا يملؤها أحد، فكانت route()
        // ترمي على كل اسم مهما كان صحيحاً — دالة لا تعمل أبداً.
        $target = null;
        foreach ($this->routes as $route) {
            if ($route->getName() === $name) {
                $target = $route;
                break;
            }
        }

        if ($target === null) {
            throw new \InvalidArgumentException("Route [{$name}] is not defined.");
        }

        $path = $target->getPath();
        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
        }

        return URLROOT . $path;
    }

    /** @return list<Route> */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    private function addRoute(string $method, string $path, callable|array $handler): Route
    {
        $prefix = '';
        $inherited = [];
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $inherited = array_merge($inherited, $group['middleware']);
        }

        $route = new Route(strtoupper($method), $prefix . $path, $handler);
        if ($inherited !== []) {
            $route->middleware(...$inherited);
        }

        $this->routes[] = $route;

        return $route;
    }

    /**
     * ينفّذ الطلب الحالي.
     *
     * الفرق الجوهري عن النسخة السابقة في **ترتيب المطابقة**. كانت
     * الحلقة تفحص الطريقة والمسار معاً:
     *
     *     if ($route['method'] === $requestMethod && matchPath(...))
     *
     * فطلب POST إلى مسار مسجَّل لـGET وحده لا يطابق شيئاً، ويسقط إلى
     * 404 — «الصفحة غير موجودة». وهي كذبة: الصفحة موجودة، والطريقة هي
     * الخاطئة. والفرق ليس شكلياً؛ 404 يقول للمطوّر «راجع تهجئة المسار»
     * وهو يبحث في المكان الخطأ، بينما 405 يشير إلى العلّة مباشرة
     * ويحمل ترويسة Allow تقول ما هو المسموح.
     *
     * الآن المطابقة على مرحلتين: المسار أولاً، ثم الطريقة ضمن ما طابق.
     */
    public function dispatch(string $uri, string $method): void
    {
        $path = $this->normalizePath($uri);
        $requestMethod = strtoupper($method);

        /** @var list<Route> $pathMatches */
        $pathMatches = [];
        $params = [];

        foreach ($this->routes as $route) {
            $routeParams = [];
            if ($this->matchPath($route->getPath(), $path, $routeParams)) {
                $pathMatches[] = $route;

                if ($route->getMethod() === $requestMethod) {
                    $params = $routeParams;
                    $this->runMiddleware($route);
                    $this->invoke($route, $params);
                    return;
                }
            }
        }

        // المسار موجود لكن بطريقة أخرى → 405 لا 404.
        if ($pathMatches !== []) {
            $allowed = array_values(array_unique(array_map(
                static fn (Route $r): string => $r->getMethod(),
                $pathMatches
            )));

            ErrorPage::methodNotAllowed($allowed, $requestMethod . ' ' . $path);
        }

        // راوت غير مسجَّل — حالة مختلفة عن «الراوت مسجَّل لكن ملف الـview
        // غائب» التي تعالجها Controller::view()، لكن ما يراه الزائر واحد.
        ErrorPage::notFound($requestMethod . ' ' . $path);
    }

    /**
     * يزيل بادئة المجلد الفرعي إن كان المشروع مستضافاً تحت مسار
     * (مثل /STORE/public/) ويضمن أن الناتج يبدأ بشرطة مائلة.
     */
    private function normalizePath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        $scriptName = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '/' && $scriptName !== '\\' && $path !== null && $path !== false) {
            if (strpos($path, $scriptName) === 0) {
                $path = substr($path, strlen($scriptName));
            }
        }

        if ($path === '' || $path === false || $path === null) {
            $path = '/';
        }

        return $path;
    }

    /**
     * ينفّذ حُرّاس المسار قبل بناء الكنترولر.
     *
     * كلٌّ منها يوقف التنفيذ بنفسه عند الرفض (redirect أو JSON أو 403)،
     * فلا حاجة لقيمة إرجاع: الوصول إلى السطر التالي يعني النجاح.
     */
    private function runMiddleware(Route $route): void
    {
        foreach ($route->getMiddleware() as $name) {
            if ($name === 'auth') {
                Middleware::requireLogin();
                continue;
            }

            if ($name === 'admin') {
                Middleware::requireAdmin();
                continue;
            }

            if (str_starts_with($name, 'perm:')) {
                Middleware::requirePermission(substr($name, 5));
                continue;
            }

            // اسم حارس غير معروف خطأ برمجي لا حالة وقت تشغيل. الفشل
            // الصاخب مقصود: حارس مكتوب خطأً يعني مساراً بلا حماية،
            // وتجاهله بصمت هو أسوأ ما يمكن فعله هنا.
            throw new \InvalidArgumentException("Unknown route middleware [{$name}].");
        }
    }

    /** @param list<string> $params */
    private function invoke(Route $route, array $params): void
    {
        $handler = $route->getHandler();

        if (is_callable($handler)) {
            call_user_func_array($handler, $params);
            return;
        }

        [$controllerClass, $action] = $handler;

        if (!class_exists($controllerClass)) {
            ErrorPage::serverError("كلاس الكنترولر غير موجود: {$controllerClass}", 500);
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $action)) {
            ErrorPage::serverError("الفعل غير موجود: {$controllerClass}::{$action}", 500);
        }

        call_user_func_array([$controller, $action], $params);
    }

    /**
     * يطابق نمط المسار بالمسار المطلوب ويستخرج معاملاته.
     *
     * @param list<string> $params
     */
    private function matchPath(string $routePath, string $requestPath, array &$params = []): bool
    {
        $params = [];

        // النصّ الثابت يُهرَّب كي لا تُفسَّر نقطة أو قوس فيه كرمز regex.
        // النسخة السابقة كانت تبني النمط من المسار الخام، فمسار يحوي
        // نقطة (مثل /handlers/notify_handler.php) كانت نقطته تطابق أي
        // محرف — أي أن /handlers/notify_handlerXphp كان يُقبل أيضاً.
        // المعامل يُطابَق أولاً، وكل ما بينها نصّ ثابت يُهرَّب. البناء
        // بالتقسيم لا بـpreg_replace_callback عمداً: الأخيرة تسلّم
        // مجموعات التقاط قد تكون فارغة أو غائبة حسب أي بديل طابق، وهو
        // تمييز لا يستطيع أي محلّل ثابت تتبّعه — ولا القارئ.
        $pattern = '';
        $offset  = 0;

        while (preg_match('/\{[a-zA-Z0-9_]+\}/', $routePath, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $literal = substr($routePath, $offset, $m[0][1] - $offset);
            $pattern .= preg_quote($literal, '#') . '([^/]+)';
            $offset   = $m[0][1] + strlen($m[0][0]);
        }

        $pattern .= preg_quote(substr($routePath, $offset), '#');

        if (preg_match('#^' . $pattern . '$#', $requestPath, $matches) !== 1) {
            return false;
        }

        array_shift($matches);
        $params = array_values(array_map(static fn ($v): string => (string) $v, $matches));

        return true;
    }
}
