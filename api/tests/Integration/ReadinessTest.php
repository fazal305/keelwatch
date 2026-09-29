<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Integration;

use Keelwatch\App;
use Keelwatch\Auth\AuthService;
use Keelwatch\Bootstrap;
use Keelwatch\Dashboard\DashboardRoutes;
use Keelwatch\Database\Migrator;
use Keelwatch\Health\HealthService;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Support\Logger;
use PDO;

final class ReadinessTest extends DatabaseTestCase
{
    private const HOST = 'keelwatch.test';

    private App $app;
    private string $cookie;
    private int $orgA;
    private int $orgB;
    private int $mature;
    private int $fresh;
    private int $other;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->pdo, Bootstrap::MIGRATIONS))->migrate();
        $this->config = $this->config->with(['sessionCookieSecure' => false]);
        $connect = fn (): PDO => $this->pdo;
        $logger = new Logger('api', fopen('php://memory', 'wb'));
        $this->app = new App($this->config, new HealthService($this->config, $connect, Bootstrap::MIGRATIONS, $logger), $logger, null, new AuthService($this->config, $connect), new DashboardRoutes($connect, $logger));
        $pw = bin2hex(random_bytes(12));
        AuthService::createUser($this->pdo, 'vic', $pw, 'viewer');
        $r = $this->app->handle(new Request('POST', '/api/auth/login', ['host' => self::HOST, 'origin' => 'http://' . self::HOST], [], json_encode(['username' => 'vic', 'password' => $pw]), '198.51.100.50'));
        preg_match('/^kw_session=([0-9a-f]{64})/', $r->headers['Set-Cookie'], $m);
        $this->cookie = 'kw_session=' . $m[1];

        $this->pdo->exec("INSERT INTO installations (github_installation_id, account_login, account_type) VALUES (8001, 'alice-dev', 'User'), (8002, 'beta-org', 'Organization')");
        $this->orgA = (int) $this->pdo->query("SELECT id FROM installations WHERE github_installation_id = 8001")->fetchColumn();
        $this->orgB = (int) $this->pdo->query("SELECT id FROM installations WHERE github_installation_id = 8002")->fetchColumn();
        $this->pdo->exec("INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private, created_at) VALUES ({$this->orgA}, 31, 'alice-dev/mature', 0, UTC_TIMESTAMP(3) - INTERVAL 60 DAY)");
        $this->mature = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private) VALUES ({$this->orgA}, 32, 'alice-dev/fresh', 1)");
        $this->fresh = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private) VALUES ({$this->orgB}, 33, 'beta-org/site', 0)");
        $this->other = (int) $this->pdo->lastInsertId();
    }

    private function event(int $repo, string $type, int $daysAgo): void
    {
        $this->seq++;
        $this->pdo->exec("INSERT INTO webhook_deliveries (github_delivery_id, event, repository_id, status, payload_bytes, correlation_id) VALUES ('rd-{$this->seq}', 'push', {$repo}, 'accepted', 10, 'corr-ready-{$this->seq}')");
        $delivery = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO repository_events (delivery_id, repository_id, schema_version, type, actor_login, occurred_at, envelope) VALUES ({$delivery}, {$repo}, 1, '{$type}', 'someone', UTC_TIMESTAMP(3) - INTERVAL {$daysAgo} DAY, '{}')");
    }

    /**
     * A completed run with a structure checkpoint (and optionally a dependencies one).
     */
    private function analysed(int $repo, int $daysAgo, int $changed, int $sourceAdded, int $testFiles, string $osv = 'not_needed'): int
    {
        $this->seq++;
        $this->pdo->prepare("INSERT INTO analysis_runs (repository_id, trigger_type, idempotency_key, status, budget_ms, correlation_id, created_at, started_at, finished_at) VALUES (?, 'webhook', ?, 'completed', 120000, 'corr-ready', UTC_TIMESTAMP(3) - INTERVAL ? DAY, UTC_TIMESTAMP(3) - INTERVAL ? DAY, UTC_TIMESTAMP(3) - INTERVAL ? DAY)")
            ->execute([$repo, "rk-{$this->seq}", $daysAgo, $daysAgo, $daysAgo]);
        $run = (int) $this->pdo->lastInsertId();
        $state = json_encode(['metrics' => ['lines_added' => $changed, 'lines_deleted' => 0, 'source_lines_added' => $sourceAdded, 'test_files' => $testFiles], 'findings' => []]);
        $this->pdo->prepare("INSERT INTO analysis_checkpoints (run_id, phase, status, state) VALUES (?, 'structure', 'completed', ?)")->execute([$run, $state]);
        $this->pdo->prepare("INSERT INTO analysis_checkpoints (run_id, phase, status, state) VALUES (?, 'dependencies', 'completed', ?)")->execute([$run, json_encode(['osv' => $osv])]);
        return $run;
    }

    private function finding(int $run, int $repo, string $severity, string $source, ?string $ruleId): void
    {
        $this->seq++;
        $this->pdo->prepare("INSERT INTO analysis_findings (run_id, repository_id, phase, fingerprint, severity, category, confidence, title, description, source, rule_id) VALUES (?, ?, 'secrets', ?, ?, 'security', 'high', 't', 'd', ?, ?)")
            ->execute([$run, $repo, hash('sha256', "f{$this->seq}"), $severity, $source, $ruleId]);
    }

    private function get(string $path, array $query = []): Response
    {
        return $this->app->handle(new Request('GET', $path, ['host' => self::HOST, 'cookie' => $this->cookie], $query, '', '198.51.100.50'));
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
     * @return array<string, array<string, mixed>>
     */
    private function signals(int $repo): array
    {
        return $this->json($this->get("/api/readiness/repositories/{$repo}"))['signals'];
    }

    public function testAMatureRepositoryIsEvaluatedOnEveryCriterion(): void
    {
        // Active in 5 distinct weeks out of 8 watched.
        foreach ([2, 9, 16, 30, 44] as $d) {
            $this->event($this->mature, 'push', $d);
        }
        $this->event($this->mature, 'pull_request.opened', 2);
        // 6 analysed changes: 5 add 50+ source lines, 3 of those touched tests.
        $this->analysed($this->mature, 40, 120, 80, 1);
        $this->analysed($this->mature, 30, 300, 60, 0);
        $this->analysed($this->mature, 20, 90, 55, 2);
        $this->analysed($this->mature, 15, 40, 10, 0);
        $this->analysed($this->mature, 10, 700, 400, 0);
        $latest = $this->analysed($this->mature, 3, 200, 70, 1, 'queried');
        $this->finding($latest, $this->mature, 'high', 'rule', 'secrets.github-token');

        $s = $this->signals($this->mature);
        self::assertSame(['met', 'Last change 2 days ago.'], [$s['recent_activity']['status'], $s['recent_activity']['evidence']]);
        self::assertSame(['met', 'Active in 5 of 8 watched weeks.'], [$s['steady_activity']['status'], $s['steady_activity']['evidence']]);
        self::assertSame(['met', '3 of 5 qualifying changes touched tests.'], [$s['tests_with_changes']['status'], $s['tests_with_changes']['evidence']]);
        // Nearest-rank median of 40, 90, 120, 200, 300, 700 is the 3rd value.
        self::assertSame(['met', 'Median change: 120 lines across 6 analysed changes.'], [$s['reviewable_size']['status'], $s['reviewable_size']['evidence']]);
        self::assertSame(['not_met', '1 critical or high finding in the latest completed analysis.'], [$s['no_open_serious']['status'], $s['no_open_serious']['evidence']]);
        self::assertSame(['not_met', '1 secret finding across 6 completed analyses.'], [$s['no_secrets']['status'], $s['no_secrets']['evidence']]);
        self::assertSame(['met', 'None across 6 analyses that checked dependencies.'], [$s['deps_clean']['status'], $s['deps_clean']['evidence']]);
    }

    public function testThresholdsCutBothWays(): void
    {
        $this->event($this->mature, 'push', 50); // only one active week, and not recent
        foreach ([1, 2, 3, 4, 5] as $i) {
            $this->analysed($this->mature, 40 + $i, 900, 100, 0, 'unavailable: timeout');
        }
        $run = $this->analysed($this->mature, 35, 900, 100, 0, 'queried');
        $this->finding($run, $this->mature, 'medium', 'osv', null);

        $s = $this->signals($this->mature);
        self::assertSame('not_met', $s['recent_activity']['status']);
        self::assertSame('not_met', $s['steady_activity']['status']);
        self::assertSame(['not_met', '0 of 6 qualifying changes touched tests.'], [$s['tests_with_changes']['status'], $s['tests_with_changes']['evidence']]);
        self::assertSame('not_met', $s['reviewable_size']['status']);
        self::assertSame('met', $s['no_open_serious']['status']);
        // Runs whose vulnerability lookup was unavailable don't count as checked.
        self::assertSame(['not_met', '1 known-vulnerable dependency across 1 analysis that checked dependencies.'], [$s['deps_clean']['status'], $s['deps_clean']['evidence']]);
    }

    public function testANewRepositoryHasNotEnoughDataRatherThanAVerdict(): void
    {
        $s = $this->signals($this->fresh);
        foreach ($s as $key => $signal) {
            self::assertSame('insufficient', $signal['status'], $key);
            self::assertNull($signal['value'], $key);
        }
        self::assertSame('No changes yet; watched for 0 days.', $s['recent_activity']['evidence']);
    }

    public function testOneFindingIsConclusiveButOneCleanAnalysisIsNot(): void
    {
        $this->analysed($this->other, 1, 100, 10, 0, 'queried');
        $s = $this->signals($this->other);
        self::assertSame(['insufficient', 'None so far, across 1 completed analysis; needs 3 for a clean result.'], [$s['no_secrets']['status'], $s['no_secrets']['evidence']]);
        self::assertSame('insufficient', $s['deps_clean']['status']);

        $run = $this->analysed($this->other, 0, 100, 10, 0, 'queried');
        $this->finding($run, $this->other, 'critical', 'rule', 'secrets.private-key');
        $s = $this->signals($this->other);
        self::assertSame(['not_met', '1 secret finding across 2 completed analyses.'], [$s['no_secrets']['status'], $s['no_secrets']['evidence']]);

        $this->analysed($this->other, 0, 100, 10, 0, 'queried');
        self::assertSame(['met', 'None across 3 analyses that checked dependencies.'], [$this->signals($this->other)['deps_clean']['status'], $this->signals($this->other)['deps_clean']['evidence']]);
    }

    public function testOverviewGroupsByAccountAndCarriesTheCriteriaButNoScore(): void
    {
        $body = $this->json($this->get('/api/readiness'));
        self::assertSame(90, $body['window_days']);
        self::assertSame(['recent_activity', 'steady_activity', 'tests_with_changes', 'reviewable_size', 'no_open_serious', 'no_secrets', 'deps_clean'], array_column($body['criteria'], 'key'));
        foreach ($body['criteria'] as $c) {
            self::assertNotEmpty($c['definition']);
            self::assertNotEmpty($c['limitation']);
        }
        self::assertSame(['alice-dev', 'beta-org'], array_column($body['accounts'], 'account_login'));
        self::assertSame(['alice-dev/fresh', 'alice-dev/mature'], array_column($body['accounts'][0]['repositories'], 'full_name'));
        self::assertDoesNotMatchRegularExpression('/"(score|rating|grade|rank)"/', $this->get('/api/readiness')->body, 'no combined score by design');

        $only = $this->json($this->get('/api/readiness', ['installation_id' => (string) $this->orgB]));
        self::assertSame(['beta-org'], array_column($only['accounts'], 'account_login'));
        self::assertSame(['alice-dev', 'beta-org'], array_column($only['account_options'], 'account_login'), 'the picker still lists every account');
    }

    public function testParametersAndAccess(): void
    {
        self::assertSame(422, $this->get('/api/readiness', ['installation_id' => 'x'])->status);
        self::assertSame(422, $this->get('/api/readiness', ['sort' => 'score'])->status);
        self::assertSame(404, $this->get('/api/readiness/repositories/999999')->status);
        $anon = $this->app->handle(new Request('GET', '/api/readiness', ['host' => self::HOST], [], '', '198.51.100.50'));
        self::assertSame(401, $anon->status);
    }
}
