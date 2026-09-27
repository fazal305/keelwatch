<?php

declare(strict_types=1);

namespace Keelwatch\Http;

use Closure;

final class Router
{
    /** @var array<string, array<string, Closure(Request): Response>> path => method => handler */
    private array $routes = [];

    /**
     * @param Closure(Request): Response $handler
     */
    public function get(string $path, Closure $handler): void
    {
        $this->routes[$path]['GET'] = $handler;
    }

    /**
     * @param Closure(Request): Response $handler
     */
    public function post(string $path, Closure $handler): void
    {
        $this->routes[$path]['POST'] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $methods = $this->routes[$request->path] ?? null;
        if ($methods === null) {
            return Response::error(404, 'not_found', 'No route matches this path.');
        }

        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $handler = $methods[$method] ?? null;
        if ($handler === null) {
            return Response::error(405, 'method_not_allowed', 'Method not allowed for this path.')
                ->withHeader('Allow', implode(', ', array_keys($methods)));
        }

        return $handler($request);
    }
}
