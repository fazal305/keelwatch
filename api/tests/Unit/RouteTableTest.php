<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use Keelwatch\Bootstrap;
use Keelwatch\Http\Router;
use Keelwatch\Support\Logger;
use Keelwatch\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The authorization table, pinned. Adding a route, or changing who may call
 * one, fails this test until the table below is updated on purpose, so an
 * access decision is always a reviewed change.
 */
final class RouteTableTest extends TestCase
{
    private const EXPECTED = [
        // Probes and machine callers.
        'GET /healthz' => Router::PUBLIC,
        'GET /readyz' => Router::PUBLIC,
        'POST /webhooks/github' => Router::PUBLIC, // HMAC-signed, not session-authenticated
        // Session lifecycle.
        'POST /api/auth/login' => Router::PUBLIC,
        'GET /api/auth/session' => Router::VIEWER,
        'POST /api/auth/logout' => Router::VIEWER,
        'POST /api/auth/password' => Router::VIEWER,
        // Reads: any signed-in user.
        'GET /api/system/health' => Router::VIEWER,
        'GET /api/overview' => Router::VIEWER,
        'GET /api/repositories' => Router::VIEWER,
        'GET /api/repositories/{id}' => Router::VIEWER,
        'GET /api/events' => Router::VIEWER,
        'GET /api/runs' => Router::VIEWER,
        'GET /api/runs/{id}' => Router::VIEWER,
        'GET /api/findings' => Router::VIEWER,
        'GET /api/findings/{id}' => Router::VIEWER,
        'GET /api/digests' => Router::VIEWER,
        'GET /api/digests/{id}' => Router::VIEWER,
        'GET /api/analytics' => Router::VIEWER,
        'GET /api/readiness' => Router::VIEWER,
        'GET /api/readiness/repositories/{id}' => Router::VIEWER,
        'GET /api/destinations' => Router::VIEWER, // hosts and labels only; URLs are write-only
        // Every change to shared state: administrators only.
        'POST /api/runs/{id}/resume' => Router::ADMIN,
        'POST /api/runs/{id}/cancel' => Router::ADMIN,
        'POST /api/destinations' => Router::ADMIN,
        'PATCH /api/destinations/{id}' => Router::ADMIN,
        'DELETE /api/destinations/{id}' => Router::ADMIN,
        'PATCH /api/repositories/{id}/settings' => Router::ADMIN,
    ];

    /**
     * @return array<string, string>
     */
    private static function actualTable(): array
    {
        $app = Bootstrap::app(TestConfig::make(), new Logger('api', fopen('php://memory', 'wb')));
        $router = (new ReflectionProperty($app, 'router'))->getValue($app);
        $table = [];
        foreach ($router->routes() as $r) {
            $table["{$r['method']} {$r['pattern']}"] = $r['access'];
        }
        ksort($table);
        return $table;
    }

    public function testEveryRouteHasItsReviewedAccessLevel(): void
    {
        $expected = self::EXPECTED;
        ksort($expected);
        self::assertSame($expected, self::actualTable());
    }

    public function testOnlyTheLoginAndMachineEndpointsArePublic(): void
    {
        $public = array_keys(array_filter(self::actualTable(), static fn (string $a): bool => $a === Router::PUBLIC));
        sort($public);
        self::assertSame(['GET /healthz', 'GET /readyz', 'POST /api/auth/login', 'POST /webhooks/github'], $public);
    }

    public function testEveryStateChangeOutsideTheOwnSessionIsAdminOnly(): void
    {
        $selfService = ['POST /api/auth/login', 'POST /api/auth/logout', 'POST /api/auth/password', 'POST /webhooks/github'];
        foreach (self::actualTable() as $route => $access) {
            if (!str_starts_with($route, 'GET ') && !in_array($route, $selfService, true)) {
                self::assertSame(Router::ADMIN, $access, $route);
            }
        }
    }

    public function testARouteWithoutAnAccessArgumentRequiresSignIn(): void
    {
        $router = new Router();
        $router->get('/new', static fn () => null);
        self::assertSame(Router::VIEWER, $router->routes()[0]['access']);
    }
}
