<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Integration;

use Keelwatch\App;
use Keelwatch\Auth\AuthService;
use Keelwatch\Bootstrap;
use Keelwatch\Dashboard\AnalyticsRepository;
use Keelwatch\Dashboard\DashboardRoutes;
use Keelwatch\Database\Migrator;
use Keelwatch\Health\HealthService;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Support\Logger;
use PDO;

/**
 * Trend data through the real kernel and MySQL, seeded relative to "now" so
 * the day buckets are known.
 */
final class AnalyticsTest extends DatabaseTestCase
{
    private const HOST = 'keelwatch.test';

    private App $app;
    /** @var array{cookie: string, csrf: string} */
    private array $viewer;
    private int $repoA;
    private int $repoB;
    private int $installation;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->pdo, Bootstrap::MIGRATIONS))->migrate();
        $this->config = $this->config->with(['sessionCookieSecure' => false]);
        $connect = fn (): PDO => $this->pdo;
        $logger = new Logger('api', fopen('php://memory', 'wb'));
        $this->app = new App(
            $this->config,
            new HealthService($this->config, $connect, Bootstrap::MIGRATIONS, $logger),
            $logger,
            null,
            new AuthService($this->config, $connect),
            new DashboardRoutes($connect, $logger),
        );
        $pw = bin2hex(random_bytes(12));
        AuthService::createUser($this->pdo, 'vic', $pw, 'viewer');
        $r = $this->app->handle(new Request('POST', '/api/auth/login', ['host' => self::HOST, 'origin' => 'http://' . self::HOST], [], json_encode(['username' => 'vic', 'password' => $pw]), '198.51.100.40'));
        preg_match('/^kw_session=([0-9a-f]{64})/', $r->headers['Set-Cookie'], $m);
        $this->viewer = ['cookie' => 'kw_session=' . $m[1], 'csrf' => json_decode($r->body, true)['csrf_token']];

        $this->pdo->exec("INSERT INTO installations (github_installation_id, account_login, account_type) VALUES (9001, 'example-org', 'Organization')");
        $this->installation = (int) $this->pdo->lastInsertId();
        // Watching began two days ago (repo A) and today (repo B).
        $this->pdo->exec("INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private, created_at) VALUES ({$this->installation}, 21, 'example-org/a', 0, UTC_TIMESTAMP(3) - INTERVAL 2 DAY)");
        $this->repoA = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private) VALUES ({$this->installation}, 22, 'example-org/b', 1)");
        $this->repoB = (int) $this->pdo->lastInsertId();
    }

    private function addRun(int $repo, string $status, int $daysAgo, ?int $durationMs = null): int
    {
        static $n = 0;
        $n++;
        $this->pdo->prepare(
            "INSERT INTO analysis_runs (repository_id, trigger_type, idempotency_key, status, budget_ms, correlation_id, failure_reason, created_at, started_at, finished_at)
             VALUES (?, 'webhook', ?, ?, 120000, 'corr-analytics', ?,
                     UTC_TIMESTAMP(3) - INTERVAL ? DAY,
                     UTC_TIMESTAMP(3) - INTERVAL ? DAY,
                     IF(? IS NULL, NULL, UTC_TIMESTAMP(3) - INTERVAL ? DAY + INTERVAL ? MICROSECOND))"
        )->execute([$repo, "k-{$n}-" . bin2hex(random_bytes(4)), $status, $status === 'failed' ? 'boom' : null, $daysAgo, $daysAgo, $durationMs, $daysAgo, ($durationMs ?? 0) * 1000]);
        return (int) $this->pdo->lastInsertId();
    }

    private function finding(int $run, int $repo, string $fingerprintSeed, string $severity, string $category, int $daysAgo): void
    {
        $this->pdo->prepare(
            "INSERT INTO analysis_findings (run_id, repository_id, phase, fingerprint, severity, category, confidence, title, description, source, rule_id, created_at)
             VALUES (?, ?, 'secrets', ?, ?, ?, 'high', 'Test finding', 'd', 'rule', 'test-rule', UTC_TIMESTAMP(3) - INTERVAL ? DAY)"
        )->execute([$run, $repo, hash('sha256', $fingerprintSeed), $severity, $category, $daysAgo]);
    }

    private function get(array $query, ?array $as = null): Response
    {
        $as ??= $this->viewer;
        return $this->app->handle(new Request('GET', '/api/analytics', ['host' => self::HOST, 'cookie' => $as['cookie']], $query, '', '198.51.100.40'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $r, int $status = 200): array
    {
        self::assertSame($status, $r->status, $r->body);
        return json_decode($r->body, true);
    }

    /**
     * @param list<array<string, mixed>> $days
     * @return array<string, mixed>
     */
    private static function day(array $days, int $daysAgo): array
    {
        return $days[count($days) - 1 - $daysAgo];
    }

    public function testDaysBeforeWatchingAreNoDataNotZero(): void
    {
        $this->addRun($this->repoA, 'completed', 1, 1500);
        $body = $this->json($this->get(['days' => '7']));

        self::assertCount(7, $body['days']);
        self::assertSame('UTC', $body['range']['timezone']);
        self::assertSame(gmdate('Y-m-d'), $body['range']['end']);
        self::assertSame(gmdate('Y-m-d', strtotime('-2 days')), $body['watching_since']);
        self::assertNull(self::day($body['days'], 3)['runs'], 'before watching began: no data');
        self::assertSame(0, self::day($body['days'], 2)['runs'], 'watched but quiet: zero');
        self::assertSame(1, self::day($body['days'], 1)['runs']);
    }

    public function testRunsFailuresAndDurationPercentiles(): void
    {
        foreach ([1000, 2000, 9000] as $ms) {
            $this->addRun($this->repoA, 'completed', 0, $ms);
        }
        $this->addRun($this->repoA, 'failed', 0, 60000); // excluded from durations
        $this->addRun($this->repoA, 'completed', 1, 5000);
        $this->addRun($this->repoA, 'completed', 1, 7000);

        $body = $this->json($this->get([]));
        self::assertCount(30, $body['days']);
        $today = self::day($body['days'], 0);
        self::assertSame(4, $today['runs']);
        self::assertSame(1, $today['runs_failed']);
        self::assertSame(3, $today['completed_runs']);
        self::assertSame(2000, $today['duration_p50_ms']);
        self::assertSame(9000, $today['duration_p95_ms']);

        $yesterday = self::day($body['days'], 1);
        self::assertSame(2, $yesterday['completed_runs']);
        self::assertNull($yesterday['duration_p50_ms'], 'fewer than 3 samples: no percentile');

        self::assertSame(6, $body['totals']['runs']);
        self::assertSame(1, $body['totals']['runs_failed']);
        self::assertSame(5, $body['totals']['completed_runs']);
        self::assertSame(5000, $body['totals']['duration_p50_ms']);
    }

    public function testNewVersusRecurringFindingsAndBreakdowns(): void
    {
        $first = $this->addRun($this->repoA, 'completed', 2, 100);
        $second = $this->addRun($this->repoA, 'completed', 0, 100);
        $this->finding($first, $this->repoA, 'leak', 'critical', 'security', 2);
        $this->finding($second, $this->repoA, 'leak', 'critical', 'security', 0); // same issue again
        $this->finding($second, $this->repoA, 'dep', 'high', 'dependency', 0);

        $body = $this->json($this->get(['days' => '7']));
        self::assertSame(['new' => 1, 'recurring' => 0], ['new' => self::day($body['days'], 2)['findings_new'], 'recurring' => self::day($body['days'], 2)['findings_recurring']]);
        self::assertSame(['new' => 1, 'recurring' => 1], ['new' => self::day($body['days'], 0)['findings_new'], 'recurring' => self::day($body['days'], 0)['findings_recurring']]);
        self::assertSame(2, $body['totals']['findings_new']);
        self::assertSame(1, $body['totals']['findings_recurring']);
        self::assertSame(['critical' => 2, 'high' => 1, 'medium' => 0, 'low' => 0, 'info' => 0], $body['findings_by_severity']);
        self::assertSame(2, $body['findings_by_category']['security']);
        self::assertSame(0, $body['findings_by_category']['quality']);
    }

    public function testRepositoryFilterScopesEverything(): void
    {
        $a = $this->addRun($this->repoA, 'completed', 0, 100);
        $this->addRun($this->repoB, 'failed', 0, 100);
        $this->finding($a, $this->repoA, 'x', 'low', 'quality', 0);

        $b = $this->json($this->get(['days' => '7', 'repository_id' => (string) $this->repoB]));
        self::assertSame(1, $b['totals']['runs']);
        self::assertSame(1, $b['totals']['runs_failed']);
        self::assertSame(0, $b['totals']['findings_new']);
        self::assertNull($b['totals']['notifications'], 'deliveries are not per repository');
        self::assertSame(gmdate('Y-m-d'), $b['watching_since']);
        self::assertNull(self::day($b['days'], 1)['runs']);

        $all = $this->json($this->get(['days' => '7']));
        self::assertSame(['sent' => 0, 'failed' => 0], $all['totals']['notifications']);
    }

    public function testParametersAreValidated(): void
    {
        self::assertArrayHasKey('days', $this->json($this->get(['days' => '14']), 422)['error']['fields']);
        self::assertArrayHasKey('from', $this->json($this->get(['from' => '2026-01-01']), 422)['error']['fields']);
        self::assertSame(404, $this->get(['repository_id' => '999999'])->status);
        $anon = $this->app->handle(new Request('GET', '/api/analytics', ['host' => self::HOST], [], '', '198.51.100.40'));
        self::assertSame(401, $anon->status);
    }

    public function testPercentileIsNearestRank(): void
    {
        self::assertSame(3, AnalyticsRepository::percentile([5, 1, 3], 50));
        self::assertSame(5, AnalyticsRepository::percentile([5, 1, 3], 95));
        self::assertSame(7, AnalyticsRepository::percentile([7], 50));
    }
}
