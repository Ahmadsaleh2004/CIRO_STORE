<?php

namespace App\Core;

class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * The currently open group's context: the path prefix and its guards.
     *
     * @var list<array{prefix: string, middleware: list<string>}>
     */
    private array $groupStack = [];

    /**
     * @param callable|array{class-string, string} $handler A controller and its action, or a closure
     */
    public function get(string $path, callable|array $handler): Route
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * @param callable|array{class-string, string} $handler A controller and its action, or a closure
     */
    public function post(string $path, callable|array $handler): Route
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * PUT, PATCH and DELETE were added because their absence forced every update or
     * delete to be a POST — leaving the route table unable to distinguish "create" from
     * "update" from "delete", and losing half the meaning of HTTP.
     *
     * The project today is entirely GET/POST, so the addition changes no existing
     * behaviour.
     *
     * @param callable|array{class-string, string} $handler A controller and its action, or a closure
     */
    public function put(string $path, callable|array $handler): Route
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * @param callable|array{class-string, string} $handler A controller and its action, or a closure
     */
    public function patch(string $path, callable|array $handler): Route
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * @param callable|array{class-string, string} $handler A controller and its action, or a closure
     */
    public function delete(string $path, callable|array $handler): Route
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * A group of routes sharing a prefix and guards.
     *
     *     $r->group(['prefix' => '/admin', 'middleware' => ['admin']], function ($r) {
     *         $r->get('/users', [AdminUsersController::class, 'index']);
     *     });
     *
     * The value is not the brevity but **that the policy is declared once**. Admin
     * guarding used to hang on every controller extending AdminController — meaning
     * forgetting that inheritance in a new controller opens its pages to everyone,
     * silently. A group makes guarding a property of the route rather than of the
     * inheritance tree.
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
     * Builds the URL for a named route.
     *
     * @param array<string, string|int> $params Values for the route parameters {id} and their like
     * @throws \InvalidArgumentException If the name is not registered — a programming
     *         error rather than a runtime condition, so failing loudly beats a broken link.
     */
    public function route(string $name, array $params = []): string
    {
        // Searching the list rather than a separate name map, **and this is not a
        // preference**: the name is set after registration through ->name(), so there is
        // no way addRoute could know it. There used to be a $named map here that nobody
        // filled, so route() threw on every name however correct — a function that never
        // worked at all.
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

    /**
     * @param callable|array{class-string, string} $handler A controller and its action, or a closure
     */
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
     * Dispatches the current request.
     *
     * The fundamental difference from the previous version is **the matching order**.
     * The loop used to check the method and the path together:
     *
     *     if ($route['method'] === $requestMethod && matchPath(...))
     *
     * so a POST to a path registered for GET alone matched nothing and fell through to
     * a 404 — "page not found". Which is a lie: the page exists, and it is the method
     * that is wrong. The difference is not cosmetic; a 404 tells the developer "check
     * the path spelling" and they look in the wrong place, while a 405 points straight
     * at the cause and carries an Allow header saying what is permitted.
     *
     * Matching now happens in two stages: the path first, then the method among what
     * matched.
     */
    public function dispatch(string $uri, string $method): void
    {
        $path = $this->normalizePath($uri);
        $requestMethod = strtoupper($method);

        // ── HEAD is treated as GET ───────────────────────────
        //
        // The standard (RFC 9110 §9.3.2) requires that every resource supporting GET
        // supports HEAD with the same headers and no body. PHP and Apache drop the body
        // on their own, so nothing is needed beyond accepting the method.
        //
        // Without this line **every route in the project** answered HEAD with a 404
        // (and now a 405, after the matching fix). The effect is not theoretical:
        // monitoring tools, health checkers and load balancers use HEAD because it is
        // cheaper, and all of them would have read the site as dead while it was alive.
        //
        // The evidence is in the project's own log: "[Cairo Store] 404: HEAD /".
        if ($requestMethod === 'HEAD') {
            $requestMethod = 'GET';
        }

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

        // The path exists but under another method → 405, not 404.
        if ($pathMatches !== []) {
            $allowed = array_values(array_unique(array_map(
                static fn (Route $r): string => $r->getMethod(),
                $pathMatches
            )));

            ErrorPage::methodNotAllowed($allowed, $requestMethod . ' ' . $path);
        }

        // An unregistered route — a different case from "the route is registered but the
        // view file is missing", which Controller::view() handles, though what the
        // visitor sees is the same.
        ErrorPage::notFound($requestMethod . ' ' . $path);
    }

    /**
     * Strips the subdirectory prefix when the project is hosted under a path (such as
     * /STORE/public/) and guarantees the result begins with a slash.
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
     * Runs the route's guards before the controller is constructed.
     *
     * Each of them halts execution itself on a refusal (a redirect, JSON, or a 403),
     * so no return value is needed: reaching the next line means success.
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

            if ($name === 'root') {
                Middleware::requireRoot();
                continue;
            }

            if (str_starts_with($name, 'perm:')) {
                Middleware::requirePermission(substr($name, 5));
                continue;
            }

            // throttle:bucket,max,windowMinutes
            //
            // The arguments live in the guard's name rather than in separate
            // configuration, because the limit is part of the route's definition and not
            // of a global setting: "sign-in, five attempts per quarter hour" is a sentence
            // read at the route itself, and whoever adds a new route sees the limit in
            // front of them and decides it rather than forgetting it.
            if (str_starts_with($name, 'throttle:')) {
                $args = explode(',', substr($name, 9));
                if (count($args) !== 3) {
                    throw new \InvalidArgumentException(
                        "Malformed throttle middleware [{$name}] — expected throttle:bucket,max,windowMinutes."
                    );
                }
                Middleware::throttle(trim($args[0]), (int)$args[1], (int)$args[2]);
                continue;
            }

            // An unknown guard name is a programming error, not a runtime condition.
            // Failing loudly is deliberate: a misspelled guard means an unprotected route,
            // and ignoring it silently is the worst thing that could be done here.
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
            ErrorPage::serverError("Controller class not found: {$controllerClass}", 500);
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $action)) {
            ErrorPage::serverError("Action not found: {$controllerClass}::{$action}", 500);
        }

        call_user_func_array([$controller, $action], $params);
    }

    /**
     * Matches the route pattern against the requested path and extracts its parameters.
     *
     * @param list<string> $params
     */
    private function matchPath(string $routePath, string $requestPath, array &$params = []): bool
    {
        $params = [];

        // The literal text is escaped so a dot or bracket in it is not read as a regex
        // metacharacter. The previous version built the pattern from the raw path, so a
        // path containing a dot (/handlers/notify_handler.php, for instance) had that dot
        // match any character — meaning /handlers/notify_handlerXphp was accepted too.
        // Parameters are matched first, and everything between them is literal text that
        // gets escaped. Building by splitting rather than with preg_replace_callback is
        // deliberate: the latter hands over capture groups that may be empty or absent
        // depending on which alternative matched, a distinction no static analyser can
        // follow — nor can the reader.
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
