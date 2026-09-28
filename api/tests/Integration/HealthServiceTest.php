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

    public function testQueueReportsDepthLagAndDeadLetters(): void
    {
        (new Migrator($this->pdo, Bootstrap::MIGRATIONS))->migrate();

        $queue = $this->component($this->health->report(), 'queue');
        self::assertSame(['ok', 'No jobs yet.', []], [$queue['status'], $queue['summary'], $queue['queues']]);

        $insert = function (string $key, string $queueName, string $extra = ''): void {
            $this->pdo->exec(
                "INSERT INTO jobs SET queue = '{$queueName}', type = 't', payload = '{}',
                    idempotency_key = '{$key}', correlation_id = 'corr-00000001'{$extra}"
            );
        };
        $insert('fresh', 'events');
        $insert('retry-later', 'events', ', run_after = UTC_TIMESTAMP(3) + INTERVAL 1 HOUR');
        $queue = $this->component($this->health->report(), 'queue');
        self::assertSame('ok', $queue['status']);
        self::assertSame(
            ['name' => 'events', 'queued' => 2, 'due' => 1, 'running' => 0, 'dead' => 0],
            array_diff_key($queue['queues'][0], ['oldest_due_age_s' => true]),
        );
        self::assertLessThan(5, $queue['queues'][0]['oldest_due_age_s'], 'a job delayed for retry is not lag');

        $insert('old', 'analysis', ', run_after = UTC_TIMESTAMP(3) - INTERVAL 10 MINUTE');
        $insert('dead', 'analysis', ", status = 'dead'");
        $queue = $this->component($this->health->report(), 'queue');

        self::assertSame('degraded', $queue['status']);
        self::assertStringContainsString('1 dead-lettered job(s)', $queue['summary']);
        self::assertStringContainsString('analysis queue lag over 300s', $queue['summary']);
        self::assertGreaterThan(590, $queue['queues'][0]['oldest_due_age_s']);
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
