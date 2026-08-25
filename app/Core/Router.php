<?php

namespace App\Core;

class Router
{
    private array $routes = [];

    /**
     * Add a GET route.
     *
     * @param string $path
     * @param callable|array $handler
     * @return void
     */
    public function get(string $path, callable|array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * Add a POST route.
     *
     * @param string $path
     * @param callable|array $handler
     * @return void
     */
    public function post(string $path, callable|array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * Add a route.
     *
     * @param string $method
     * @param string $path
     * @param callable|array $handler
     * @return void
     */
    private function addRoute(string $method, string $path, callable|array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'path'    => $path,
            'handler' => $handler
        ];
    }

    /**
     * Dispatch the current request.
     *
     * @param string $uri
     * @param string $method
     * @return void
     */
    public function dispatch(string $uri, string $method): void
    {
        $path = parse_url($uri, PHP_URL_PATH);

        // Normalize subdirectory offset if hosted in subfolder (e.g. /STORE/public/)
        $scriptName = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '/' && $scriptName !== '\\' && strpos($path, $scriptName) === 0) {
            $path = substr($path, strlen($scriptName));
        }

        if ($path === '' || $path === false) {
            $path = '/';
        }

        $requestMethod = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] === $requestMethod && $this->matchPath($route['path'], $path, $params)) {
                if (is_callable($route['handler'])) {
                    call_user_func_array($route['handler'], $params);
                    return;
                }

                if (is_array($route['handler'])) {
                    [$controllerClass, $action] = $route['handler'];
                    if (class_exists($controllerClass)) {
                        $controller = new $controllerClass();
                        if (method_exists($controller, $action)) {
                            call_user_func_array([$controller, $action], $params);
                            return;
                        }
                    }
                }
            }
        }

        // راوت غير مسجَّل — حالة مختلفة عن «الراوت مسجَّل لكن ملف الـview
        // غائب» التي تعالجها Controller::view()، لكن ما يراه الزائر واحد.
        // كان هنا echo لنص عارٍ بلا <html> ولا لغة ولا رابط عودة.
        ErrorPage::notFound($requestMethod . ' ' . $path);
    }

    /**
     * Match path against route patterns.
     *
     * @param string $routePath
     * @param string $requestPath
     * @param array $params
     * @return bool
     */
    private function matchPath(string $routePath, string $requestPath, &$params = []): bool
    {
        $params = [];
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $requestPath, $matches)) {
            array_shift($matches);
            $params = $matches;
            return true;
        }

        return false;
    }
}
