<?php

declare(strict_types=1);

namespace Keelwatch\Http;

use Closure;

/**
 * Method + path routing with numeric path parameters ("/api/runs/{id}") and
 * per-route access rules. Parameters only ever match digits, so a path
 * segment can't smuggle anything else into a handler.
 */
final class Router
{
    public const PUBLIC = 'public';
    public const VIEWER = 'viewer';
    public const ADMIN = 'admin';

    /** @var list<array{method: string, pattern: string, regex: string, access: string, handler: Closure}> */
    private array $routes = [];

    public function get(string $pattern, Closure $handler, string $access = self::PUBLIC): void
    {
        $this->add('GET', $pattern, $handler, $access);
    }

    public function post(string $pattern, Closure $handler, string $access = self::PUBLIC): void
    {
        $this->add('POST', $pattern, $handler, $access);
    }

    public function patch(string $pattern, Closure $handler, string $access = self::PUBLIC): void
    {
        $this->add('PATCH', $pattern, $handler, $access);
    }

    public function delete(string $pattern, Closure $handler, string $access = self::PUBLIC): void
    {
        $this->add('DELETE', $pattern, $handler, $access);
    }

    /**
     * @return array{handler: Closure, params: array<string, int>, access: string}|Response
     *         the matched route, or a 404/405 response
     */
    public function match(Request $request): array|Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = [];
            foreach ($m as $name => $value) {
                if (is_string($name)) {
                    $params[$name] = (int) $value;
                }
            }
            return ['handler' => $route['handler'], 'params' => $params, 'access' => $route['access']];
        }
        if ($allowed !== []) {
            return Response::error(405, 'method_not_allowed', 'Method not allowed for this path.')
                ->withHeader('Allow', implode(', ', array_unique($allowed)));
        }
        return Response::error(404, 'not_found', 'No route matches this path.');
    }

    /**
     * Convenience for routes that need no access checks (used by tests).
     */
    public function dispatch(Request $request): Response
    {
        $match = $this->match($request);
        if ($match instanceof Response) {
            return $match;
        }
        return ($match['handler'])($request, $match['params'], null);
    }

    private function add(string $method, string $pattern, Closure $handler, string $access): void
    {
        $regex = '#^' . preg_replace('#\\\{([a-z_]+)\\\}#', '(?P<$1>[1-9][0-9]{0,18})', preg_quote($pattern, '#')) . '$#D';
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'access' => $access,
            'handler' => $handler,
        ];
    }
}
