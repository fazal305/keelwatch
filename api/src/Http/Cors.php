<?php

declare(strict_types=1);

namespace Keelwatch\Http;

/**
 * Exact-match origin allowlist. No wildcards: the dashboard is the only
 * browser client, and its origin is known per environment.
 */
final class Cors
{
    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    public function isPreflight(Request $request): bool
    {
        return $request->method === 'OPTIONS' && $request->header('access-control-request-method') !== null;
    }

    public function preflight(Request $request): Response
    {
        return $this->apply($request, Response::noContent())
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-Request-Id')
            ->withHeader('Access-Control-Max-Age', '600');
    }

    public function apply(Request $request, Response $response): Response
    {
        $origin = $request->header('origin');
        $response = $response->withHeader('Vary', 'Origin');
        if ($origin === null || !in_array($origin, $this->allowedOrigins, true)) {
            return $response;
        }
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Expose-Headers', 'X-Request-Id');
    }
}
