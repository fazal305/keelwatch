<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use Keelwatch\App;
use Keelwatch\Health\HealthService;
use Keelwatch\Http\Request;
use Keelwatch\Support\Logger;
use Keelwatch\Tests\Support\TestConfig;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Drives the HTTP kernel with an unreachable database, which is the case
 * that must degrade gracefully without leaking connection details.
 */
final class AppTest extends TestCase
{
    /** @var resource */
    private $logStream;
    private App $app;

    protected function setUp(): void
    {
        $this->logStream = fopen('php://memory', 'w+b');
        $config = TestConfig::make(['API_CORS_ORIGINS' => 'https://dash.example.com']);
        $logger = new Logger('api', $this->logStream);
        $health = new HealthService(
            $config,
            static fn () => throw new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'unit_user'@'10.0.0.5'"),
            __DIR__,
            $logger,
        );
        $this->app = new App($config, $health, $logger);
    }

    public function testLivenessIsIndependentOfTheDatabase(): void
    {
        $response = $this->app->handle(new Request('GET', '/healthz'));

        self::assertSame(200, $response->status);
        self::assertSame(['status' => 'ok'], json_decode($response->body, true));
    }

    public function testReadinessFailsWith503WhenDatabaseIsDown(): void
    {
        $response = $this->app->handle(new Request('GET', '/readyz'));

        self::assertSame(503, $response->status);
        self::assertSame(
            ['status' => 'not_ready', 'checks' => ['database' => 'down']],
            json_decode($response->body, true),
        );
    }

    public function testHealthReportDegradesWithoutLeakingConnectionDetails(): void
    {
        $response = $this->app->handle(new Request('GET', '/api/system/health'));
        $report = json_decode($response->body, true);

        self::assertSame(200, $response->status);
        self::assertSame('down', $report['status']);
        self::assertSame(['api', 'database', 'workers', 'queue'], array_column($report['components'], 'name'));
        self::assertSame(['ok', 'down', 'unknown', 'unknown'], array_column($report['components'], 'status'));
        self::assertStringNotContainsString('10.0.0.5', $response->body);
        self::assertStringNotContainsString('unit_user', $response->body);
        self::assertStringNotContainsString('SQLSTATE', $response->body);
    }

    public function testEveryResponseCarriesCorrelationAndSecurityHeaders(): void
    {
        $response = $this->app->handle(new Request('GET', '/nope', ['x-request-id' => 'trace-abc-123']));

        self::assertSame(404, $response->status);
        self::assertSame('trace-abc-123', $response->headers['X-Request-Id']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertSame('no-store', $response->headers['Cache-Control']);

        rewind($this->logStream);
        self::assertStringContainsString('"correlation_id":"trace-abc-123"', (string) stream_get_contents($this->logStream));
    }

    public function testCorsAllowsOnlyListedOrigins(): void
    {
        $allowed = $this->app->handle(new Request('GET', '/healthz', ['origin' => 'https://dash.example.com']));
        self::assertSame('https://dash.example.com', $allowed->headers['Access-Control-Allow-Origin']);

        $denied = $this->app->handle(new Request('GET', '/healthz', ['origin' => 'https://evil.example.com']));
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $denied->headers);

        $preflight = $this->app->handle(new Request('OPTIONS', '/api/system/health', [
            'origin' => 'https://dash.example.com',
            'access-control-request-method' => 'GET',
        ]));
        self::assertSame(204, $preflight->status);
        self::assertSame('GET, POST, OPTIONS', $preflight->headers['Access-Control-Allow-Methods']);
    }
}
