<?php

namespace App\Core;

class App
{
    protected Router $router;

    public function __construct()
    {
        $this->router = new Router();
    }

    /**
     * Run the application by dispatching incoming request.
     *
     * @return void
     */
    public function run(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $this->router->dispatch($uri, $method);
    }

    /**
     * Get router instance.
     *
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }
}
