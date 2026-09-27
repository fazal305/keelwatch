<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use Keelwatch\Database\Migrator;
use Keelwatch\Health\HealthService;
use Keelwatch\Health\WorkerStatus;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Http\Router;
use Keelwatch\Support\CorrelationId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmallPartsTest extends TestCase
{
    public function testCorrelationIdReusesSafeIdsAndReplacesUnsafeOnes(): void
    {
        self::assertSame('abc-123_XYZ.9', CorrelationId::resolve('abc-123_XYZ.9'));

        foreach ([null, '', 'short', "evil\nline-injection", str_repeat('a', 129), '<script>alert(1)</script>'] as $bad) {
            $id = CorrelationId::resolve($bad);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        }
    }

    public function testRouterReturns404And405WithAllowHeader(): void
    {
        $router = new Router();
        $router->get('/thing', static fn (): Response => Response::json(['ok' => true]));

        self::assertSame(404, $router->dispatch(new Request('GET', '/missing'))->status);

        $notAllowed = $router->dispatch(new Request('DELETE', '/thing'));
        self::assertSame(405, $notAllowed->status);
        self::assertSame('GET', $notAllowed->headers['Allow']);

        self::assertSame(200, $router->dispatch(new Request('HEAD', '/thing'))->status);
    }

    /**
     * @return iterable<string, array{string, float, string}>
     */
    public static function workerCases(): iterable
    {
        yield 'fresh running' => ['running', 5.0, WorkerStatus::RUNNING];
        yield 'exactly at threshold' => ['running', 35.0, WorkerStatus::RUNNING];
        yield 'stale running' => ['running', 35.1, WorkerStatus::STALE];
        yield 'stopping fresh' => ['stopping', 1.0, WorkerStatus::STOPPING];
        yield 'stopped stays stopped' => ['stopped', 9999.0, WorkerStatus::STOPPED];
    }

    #[DataProvider('workerCases')]
    public function testWorkerStatusClassification(string $reported, float $age, string $expected): void
    {
        self::assertSame($expected, WorkerStatus::classify($reported, $age, 35));
    }

    public function testOverallStatusRollup(): void
    {
        $ok = ['name' => 'api', 'status' => 'ok'];

        self::assertSame('ok', HealthService::overall([$ok, ['name' => 'database', 'status' => 'ok']]));
        self::assertSame('down', HealthService::overall([$ok, ['name' => 'database', 'status' => 'down']]));
        self::assertSame('degraded', HealthService::overall([
            $ok,
            ['name' => 'database', 'status' => 'ok'],
            ['name' => 'workers', 'status' => 'down'],
        ]));
        self::assertSame('degraded', HealthService::overall([$ok, ['name' => 'database', 'status' => 'degraded']]));
    }

    public function testMigrationStatementSplitting(): void
    {
        $sql = "-- header comment\nCREATE TABLE a (id INT);\n\nCREATE TABLE b (\n  id INT\n);\n-- trailing\n";

        self::assertSame(
            ['CREATE TABLE a (id INT)', "CREATE TABLE b (\n  id INT\n)"],
            Migrator::splitStatements($sql),
        );
    }
}
