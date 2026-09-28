<?php

declare(strict_types=1);

namespace Keelwatch\Health;

use Closure;
use Keelwatch\Config;
use Keelwatch\Database\Migrator;
use Keelwatch\Support\Logger;
use PDO;
use Throwable;

/**
 * Builds liveness, readiness and the component report behind the
 * System Health page. Probe failures become component states; they are
 * never thrown to the caller and never expose connection details.
 */
final class HealthService
{
    public const OK = 'ok';
    public const DEGRADED = 'degraded';
    public const DOWN = 'down';
    public const UNKNOWN = 'unknown';

    /**
     * @param Closure(): PDO $connect
     */
    public function __construct(
        private readonly Config $config,
        private readonly Closure $connect,
        private readonly string $migrationsDirectory,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{ready: bool, checks: array<string, string>}
     */
    public function readiness(): array
    {
        $database = $this->probeDatabase();
        $ready = $database['status'] === self::OK;

        return [
            'ready' => $ready,
            'checks' => ['database' => $database['status']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $database = $this->probeDatabase();
        $pdo = $database['pdo'];
        $workers = $pdo instanceof PDO
            ? $this->probeWorkers($pdo)
            : [
                'name' => 'workers',
                'status' => self::UNKNOWN,
                'summary' => 'Cannot read worker heartbeats while the database is unreachable.',
                'workers' => [],
            ];
        $queue = $pdo instanceof PDO
            ? $this->probeQueue($pdo)
            : [
                'name' => 'queue',
                'status' => self::UNKNOWN,
                'summary' => 'Cannot read the job queue while the database is unreachable.',
                'queues' => [],
            ];

        unset($database['pdo']);

        $components = [
            [
                'name' => 'api',
                'status' => self::OK,
                'summary' => 'Serving requests.',
                'version' => $this->config->appVersion,
            ],
            $database,
            $workers,
            $queue,
        ];

        return [
            'status' => self::overall($components),
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'environment' => $this->config->appEnv,
            'components' => $components,
        ];
    }

    /**
     * @param list<array{status: string}> $components
     */
    public static function overall(array $components): string
    {
        $statuses = array_column($components, 'status');
        foreach ($components as $component) {
            if (($component['name'] ?? '') === 'database' && $component['status'] === self::DOWN) {
                return self::DOWN;
            }
        }
        foreach ($statuses as $status) {
            if ($status !== self::OK) {
                return self::DEGRADED;
            }
        }
        return self::OK;
    }

    /**
     * @return array<string, mixed>
     */
    private function probeDatabase(): array
    {
        $started = hrtime(true);
        try {
            $pdo = ($this->connect)();
            $pdo->query('SELECT 1')->fetchColumn();
            $latencyMs = round((hrtime(true) - $started) / 1e6, 1);
            $pending = (new Migrator($pdo, $this->migrationsDirectory))->pending();
        } catch (Throwable $e) {
            $this->logger->warning('database probe failed', [
                'exception' => $e::class,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
            return [
                'name' => 'database',
                'status' => self::DOWN,
                'summary' => 'Database is unreachable.',
                'latency_ms' => null,
                'pending_migrations' => null,
                'pdo' => null,
            ];
        }

        $status = $pending === [] ? self::OK : self::DEGRADED;
        $summary = $pending === []
            ? 'Connected. Schema is up to date.'
            : sprintf('Connected, but %d migration(s) have not been applied.', count($pending));

        return [
            'name' => 'database',
            'status' => $status,
            'summary' => $summary,
            'latency_ms' => $latencyMs,
            'pending_migrations' => count($pending),
            'pdo' => $pdo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeWorkers(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                'SELECT worker_id, version, status, started_at, last_seen_at,
                        TIMESTAMPDIFF(MICROSECOND, last_seen_at, UTC_TIMESTAMP(3)) / 1000000 AS age_s
                 FROM worker_heartbeats
                 WHERE status <> \'stopped\' OR stopped_at > UTC_TIMESTAMP(3) - INTERVAL 1 DAY
                 ORDER BY last_seen_at DESC
                 LIMIT 50'
            )->fetchAll();
        } catch (Throwable $e) {
            $this->logger->warning('worker heartbeat query failed', [
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);
            return [
                'name' => 'workers',
                'status' => self::UNKNOWN,
                'summary' => 'Worker heartbeats could not be read.',
                'workers' => [],
            ];
        }

        $workers = [];
        $running = 0;
        foreach ($rows as $row) {
            $age = max(0.0, (float) $row['age_s']);
            $state = WorkerStatus::classify((string) $row['status'], $age, $this->config->workerStaleAfterS);
            if ($state === WorkerStatus::RUNNING) {
                $running++;
            }
            $workers[] = [
                'id' => (string) $row['worker_id'],
                'status' => $state,
                'version' => (string) $row['version'],
                'started_at' => self::isoUtc((string) $row['started_at']),
                'last_seen_at' => self::isoUtc((string) $row['last_seen_at']),
                'last_seen_age_s' => round($age, 1),
            ];
        }

        if ($running > 0) {
            $status = self::OK;
            $summary = sprintf('%d worker(s) running.', $running);
        } elseif ($workers === []) {
            $status = self::DOWN;
            $summary = 'No worker has reported a heartbeat yet.';
        } else {
            $status = self::DOWN;
            $summary = 'No worker is currently running.';
        }

        return [
            'name' => 'workers',
            'status' => $status,
            'summary' => $summary,
            'stale_after_s' => $this->config->workerStaleAfterS,
            'workers' => $workers,
        ];
    }

    /**
     * Per-queue depth and lag. Lag is how long the oldest *due* job has been
     * waiting (now - run_after), so jobs deliberately delayed for a retry
     * don't count as lag until they are due.
     *
     * @return array<string, mixed>
     */
    private function probeQueue(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                "SELECT queue,
                        SUM(status = 'queued') AS queued,
                        SUM(status = 'queued' AND run_after <= UTC_TIMESTAMP(3)) AS due,
                        SUM(status = 'running') AS running,
                        SUM(status = 'dead') AS dead,
                        TIMESTAMPDIFF(MICROSECOND,
                            MIN(CASE WHEN status = 'queued' AND run_after <= UTC_TIMESTAMP(3) THEN run_after END),
                            UTC_TIMESTAMP(3)) / 1000000 AS oldest_due_age_s
                 FROM jobs
                 GROUP BY queue
                 ORDER BY queue"
            )->fetchAll();
        } catch (Throwable $e) {
            $this->logger->warning('queue probe failed', ['exception' => $e::class, 'code' => $e->getCode()]);
            return [
                'name' => 'queue',
                'status' => self::UNKNOWN,
                'summary' => 'The job queue could not be read.',
                'queues' => [],
            ];
        }

        $queues = [];
        $dead = 0;
        $lagging = [];
        foreach ($rows as $row) {
            $age = $row['oldest_due_age_s'] === null ? null : round(max(0.0, (float) $row['oldest_due_age_s']), 1);
            $queues[] = [
                'name' => (string) $row['queue'],
                'queued' => (int) $row['queued'],
                'due' => (int) $row['due'],
                'running' => (int) $row['running'],
                'dead' => (int) $row['dead'],
                'oldest_due_age_s' => $age,
            ];
            $dead += (int) $row['dead'];
            if ($age !== null && $age > $this->config->queueLagWarnS) {
                $lagging[] = (string) $row['queue'];
            }
        }

        $problems = [];
        if ($dead > 0) {
            $problems[] = sprintf('%d dead-lettered job(s) need attention', $dead);
        }
        if ($lagging !== []) {
            $problems[] = sprintf('%s queue lag over %ds', implode(', ', $lagging), $this->config->queueLagWarnS);
        }

        return [
            'name' => 'queue',
            'status' => $problems === [] ? self::OK : self::DEGRADED,
            'summary' => $problems === [] ? ($queues === [] ? 'No jobs yet.' : 'Jobs are flowing.') : ucfirst(implode('; ', $problems)) . '.',
            'lag_warn_after_s' => $this->config->queueLagWarnS,
            'queues' => $queues,
        ];
    }

    private static function isoUtc(string $mysqlDatetime): string
    {
        return str_replace(' ', 'T', substr($mysqlDatetime, 0, 19)) . 'Z';
    }
}
