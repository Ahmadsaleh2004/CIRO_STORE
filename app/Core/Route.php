<?php

namespace App\Core;

/**
 * Route — one registered route, and the interface for configuring it.
 *
 * This class exists so registration can be chained:
 *
 *     $r->post('/admin/products/delete', [C::class, 'delete'])
 *       ->middleware('perm:can_manage_products')
 *       ->name('admin.products.delete');
 *
 * The router used to store routes as bare associative arrays, leaving nowhere to
 * hang anything: no guard, no name. And an array does not fail in a legible way —
 * a mistyped key silently becomes a new key, whereas calling a method that does
 * not exist on an object throws immediately.
 */
final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    private ?string $name = null;

    /**
     * @param string               $method  GET | POST | PUT | PATCH | DELETE
     * @param string               $path    A path such as /admin/users or /product/{id}
     * @param callable|array{class-string,string} $handler
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly mixed $handler
    ) {
    }

    /**
     * Adds one or more guards that run **before** the controller is constructed.
     *
     * The order is deliberate: the guard runs before `new $controllerClass()`. That
     * is a fundamental difference from calling Middleware inside the action body —
     * there the controller has already been constructed, and may already have done
     * work in its constructor, before anyone asked about the permission.
     *
     * Supported names:
     *   'auth'                   → a signed-in user
     *   'admin'                  → a signed-in admin
     *   'perm:<permission_name>' → an admin holding the permission (rank A overrides it)
     */
    public function middleware(string ...$names): self
    {
        foreach ($names as $name) {
            $this->middleware[] = $name;
        }

        return $this;
    }

    /**
     * Names the route so its URL is built from the name rather than a written string.
     *
     * Why? Because URLROOT . '/admin/users' is written out across 63 files. Changing
     * one path means chasing it through every one of them, and whichever is missed
     * becomes a broken link that only a visitor discovers.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /** @return list<string> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
