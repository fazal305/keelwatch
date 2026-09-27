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
        $workers = $database['pdo'] instanceof PDO
            ? $this->probeWorkers($database['pdo'])
            : [
                'name' => 'workers',
                'status' => self::UNKNOWN,
                'summary' => 'Cannot read worker heartbeats while the database is unreachable.',
                'workers' => [],
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

    private static function isoUtc(string $mysqlDatetime): string
    {
        return str_replace(' ', 'T', substr($mysqlDatetime, 0, 19)) . 'Z';
    }
}
