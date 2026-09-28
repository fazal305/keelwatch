<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Integration;

use Keelwatch\Bootstrap;
use Keelwatch\Database\Migrator;
use Keelwatch\Health\HealthService;
use Keelwatch\Support\Logger;

final class HealthServiceTest extends DatabaseTestCase
{
    private HealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->health = new HealthService(
            $this->config,
            fn () => $this->pdo,
            Bootstrap::MIGRATIONS,
            new Logger('api', fopen('php://memory', 'wb')),
        );
    }

    public function testPendingMigrationsDegradeTheDatabaseAndBlockReadiness(): void
    {
        $report = $this->health->report();
        $database = $this->component($report, 'database');

        self::assertSame('degraded', $database['status']);
        self::assertSame(count(glob(Bootstrap::MIGRATIONS . '/*.sql') ?: []), $database['pending_migrations']);

        // An API on an out-of-date schema must not receive traffic.
        self::assertSame(
            ['ready' => false, 'checks' => ['database' => 'degraded']],
            $this->health->readiness(),
        );

        (new Migrator($this->pdo, Bootstrap::MIGRATIONS))->migrate();
        self::assertTrue($this->health->readiness()['ready']);
    }

    public function testWorkerStatesFollowHeartbeats(): void
    {
        (new Migrator($this->pdo, Bootstrap::MIGRATIONS))->migrate();

        $workers = $this->component($this->health->report(), 'workers');
        self::assertSame('down', $workers['status']);
        self::assertSame('No worker has reported a heartbeat yet.', $workers['summary']);

        $this->pdo->exec(
            "INSERT INTO worker_heartbeats (worker_id, pid, version, status, started_at, last_seen_at)
             VALUES ('fresh', 1, '0.1.0', 'running', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)),
                    ('old', 2, '0.1.0', 'running', UTC_TIMESTAMP(3) - INTERVAL 1 HOUR, UTC_TIMESTAMP(3) - INTERVAL 10 MINUTE)"
        );
        $this->pdo->exec(
            "INSERT INTO worker_heartbeats (worker_id, pid, version, status, started_at, last_seen_at, stopped_at)
             VALUES ('done', 3, '0.1.0', 'stopped', UTC_TIMESTAMP(3) - INTERVAL 2 HOUR, UTC_TIMESTAMP(3) - INTERVAL 1 HOUR, UTC_TIMESTAMP(3) - INTERVAL 1 HOUR)"
        );

        $report = $this->health->report();
        $workers = $this->component($report, 'workers');
        $byId = array_column($workers['workers'], 'status', 'id');

        self::assertSame('ok', $report['status']);
        self::assertSame('ok', $workers['status']);
        // Most recently seen first.
        self::assertSame(['fresh' => 'running', 'old' => 'stale', 'done' => 'stopped'], $byId);
        self::assertSame('1 worker(s) running.', $workers['summary']);

        $this->pdo->exec("UPDATE worker_heartbeats SET last_seen_at = UTC_TIMESTAMP(3) - INTERVAL 5 MINUTE WHERE worker_id = 'fresh'");
        $workers = $this->component($this->health->report(), 'workers');
        self::assertSame('down', $workers['status']);
        self::assertSame('No worker is currently running.', $workers['summary']);
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function component(array $report, string $name): array
    {
        foreach ($report['components'] as $component) {
            if ($component['name'] === $name) {
                return $component;
            }
        }
        self::fail("component {$name} missing");
    }
}
