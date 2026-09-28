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

/**
 * The dashboard JSON API through the real kernel and MySQL.
 */
final class DashboardTest extends DatabaseTestCase
{
    private const HOST = 'keelwatch.test';
    private const PATCH_MARKER = 'UNIQUE-PATCH-TEXT-THAT-MUST-NOT-LEAK';

    private App $app;
    /** @var array{cookie: string, csrf: string} */
    private array $admin;
    /** @var array{cookie: string, csrf: string} */
    private array $viewer;
    private int $publicRepo;
    private int $privateRepo;

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
        $adminPw = bin2hex(random_bytes(12));
        $viewerPw = bin2hex(random_bytes(12));
        AuthService::createUser($this->pdo, 'ada', $adminPw, 'admin');
        AuthService::createUser($this->pdo, 'vic', $viewerPw, 'viewer');
        $this->admin = $this->signIn('ada', $adminPw);
        $this->viewer = $this->signIn('vic', $viewerPw);
    }

    // ----- helpers -----------------------------------------------------------------

    /**
     * @param array{cookie: string, csrf: string}|null $as
     * @param array<string, string> $query
     */
    private function call(string $method, string $path, ?array $as = null, array $query = []): Response
    {
        $headers = ['host' => self::HOST, 'origin' => 'http://' . self::HOST];
        if ($as !== null) {
            $headers['cookie'] = $as['cookie'];
            $headers['x-csrf-token'] = $as['csrf'];
        }
        return $this->app->handle(new Request($method, $path, $headers, $query, '', '198.51.100.20'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response, int $status = 200): array
    {
        self::assertSame($status, $response->status, $response->body);
        return json_decode($response->body, true);
    }

    /**
     * @return array{cookie: string, csrf: string}
     */
    private function signIn(string $user, string $password): array
    {
        $r = $this->app->handle(new Request('POST', '/api/auth/login', ['host' => self::HOST, 'origin' => 'http://' . self::HOST], [], json_encode(['username' => $user, 'password' => $password]), '198.51.100.20'));
        preg_match('/^kw_session=([0-9a-f]{64})/', $r->headers['Set-Cookie'], $m);
        return ['cookie' => 'kw_session=' . $m[1], 'csrf' => json_decode($r->body, true)['csrf_token']];
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO installations (github_installation_id, account_login, account_type) VALUES (5001, 'example-org', 'Organization')");
        $inst = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private, default_branch, llm_policy) VALUES ({$inst}, 1, 'example-org/public-site', 0, 'main', 'public_only')");
        $this->publicRepo = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private, default_branch) VALUES ({$inst}, 2, 'example-org/internal_tool', 1, 'main')");
        $this->privateRepo = (int) $this->pdo->lastInsertId();

        foreach ([1, 2, 3] as $i) {
            $this->pdo->exec("INSERT INTO webhook_deliveries (github_delivery_id, event, action, repository_id, status, payload_bytes, correlation_id) VALUES ('d-{$i}', 'pull_request', 'opened', {$this->publicRepo}, 'accepted', 100, 'corr-dash-000{$i}')");
            $delivery = (int) $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO repository_events (delivery_id, repository_id, schema_version, type, pr_number, head_sha, occurred_at, envelope) VALUES ({$delivery}, {$this->publicRepo}, 1, 'pull_request.opened', {$i}, '" . str_repeat((string) $i, 40) . "', UTC_TIMESTAMP(3), '{}')");
        }
        $this->pdo->exec("INSERT INTO webhook_deliveries (github_delivery_id, event, status, ignore_reason, payload_bytes, correlation_id) VALUES ('d-ping', 'ping', 'ignored', 'ping', 20, 'corr-dash-ping')");

        $run = function (int $repo, string $status, string $key, ?string $reason = null): int {
            $this->pdo->prepare("INSERT INTO analysis_runs (repository_id, trigger_type, idempotency_key, status, budget_ms, correlation_id, failure_reason, started_at, finished_at) VALUES (?, 'webhook', ?, ?, 120000, 'corr-dash-run1', ?, UTC_TIMESTAMP(3) - INTERVAL 5 SECOND, IF(? IN ('completed','failed'), UTC_TIMESTAMP(3), NULL))")
                ->execute([$repo, $key, $status, $reason, $status]);
            return (int) $this->pdo->lastInsertId();
        };
        $completed = $run($this->publicRepo, 'completed', 'r1');
        $run($this->publicRepo, 'failed', 'r2', 'dependencies: manifest could not be parsed');
        $run($this->privateRepo, 'checkpointed', 'r3', 'budget_exhausted:extract_changes');
        $run($this->privateRepo, 'queued', 'r4');

        $state = json_encode(['files' => [['path' => 'src/a.js', 'patch' => '+' . self::PATCH_MARKER]], 'files_truncated' => false, 'patch_bytes' => 40, 'redactions' => 1]);
        $this->pdo->prepare("INSERT INTO analysis_checkpoints (run_id, phase, status, duration_ms, state) VALUES (?, 'extract_changes', 'completed', 120, ?)")->execute([$completed, $state]);
        $this->pdo->prepare("INSERT INTO analysis_checkpoints (run_id, phase, status, state) VALUES (?, 'llm_review', 'skipped', ?)")->execute([$completed, json_encode(['reason' => 'llm_not_configured'])]);

        $finding = function (string $sev, string $title, string $fp) use ($completed): void {
            $this->pdo->prepare("INSERT INTO analysis_findings (run_id, repository_id, phase, fingerprint, severity, category, confidence, title, description, evidence, file_path, line_start, line_end, source, rule_id) VALUES (?, ?, 'secrets', ?, ?, 'security', 'high', ?, 'd', ?, 'src/a.js', 3, 3, 'rule', 'secrets.test')")
                ->execute([$completed, $this->publicRepo, str_repeat($fp, 64), $sev, $title, json_encode(['kind' => 'code', 'snippet' => 'key = "[REDACTED:github_token]"', 'redacted' => true])]);
        };
        $finding('critical', 'Possible github token committed', 'a');
        $finding('low', '100% of lines changed', 'b');
        $finding('medium', 'Raw HTML sink', 'c');

        $this->pdo->prepare("INSERT INTO digests (installation_id, repository_id, kind, digest_key, period_start, period_end, content) VALUES (?, ?, 'run', 'run:1', UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)")
            ->execute([$inst, $this->publicRepo, json_encode(['run_id' => $completed, 'findings' => ['by_severity' => ['critical' => 1]]])]);
        $digest = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO digest_runs (digest_id, run_id) VALUES ({$digest}, {$completed})");
    }

    private function runId(string $key): int
    {
        return (int) $this->pdo->query("SELECT id FROM analysis_runs WHERE idempotency_key = '{$key}'")->fetchColumn();
    }

    // ----- tests ---------------------------------------------------------------------

    public function testEveryDashboardEndpointNeedsASession(): void
    {
        foreach (['/api/overview', '/api/repositories', '/api/events', '/api/runs', '/api/findings', '/api/digests', '/api/runs/1', '/api/findings/1'] as $path) {
            self::assertSame(401, $this->call('GET', $path)->status, $path);
        }
        self::assertSame(401, $this->call('POST', '/api/runs/1/resume')->status);
    }

    public function testEmptySystemReturnsRealEmptyStates(): void
    {
        $overview = $this->json($this->call('GET', '/api/overview', $this->viewer));

        self::assertSame(0, $overview['repositories']);
        self::assertSame([], $overview['recent_runs']);
        self::assertSame([], $overview['attention']['latest_findings_by_severity']);
        self::assertSame(['items' => [], 'next_before' => null], $this->json($this->call('GET', '/api/findings', $this->viewer)));
        self::assertSame(['items' => []], $this->json($this->call('GET', '/api/repositories', $this->viewer)));
    }

    public function testOverviewAndRepositories(): void
    {
        $this->seed();
        $overview = $this->json($this->call('GET', '/api/overview', $this->viewer));

        self::assertSame(2, $overview['repositories']);
        self::assertSame(['critical' => 1, 'medium' => 1, 'low' => 1], $overview['attention']['latest_findings_by_severity']);
        self::assertSame(1, $overview['attention']['checkpointed_runs']);
        self::assertCount(4, $overview['recent_runs']);

        $repos = $this->json($this->call('GET', '/api/repositories', $this->viewer, ['q' => 'internal']))['items'];
        self::assertSame(['example-org/internal_tool'], array_column($repos, 'full_name'));
        self::assertTrue($repos[0]['private']);

        // LIKE wildcards in the search box are literal, not patterns.
        self::assertSame([], $this->json($this->call('GET', '/api/repositories', $this->viewer, ['q' => '%']))['items']);
        self::assertSame(['example-org/internal_tool'], array_column($this->json($this->call('GET', '/api/repositories', $this->viewer, ['q' => 'internal_']))['items'], 'full_name'));

        $detail = $this->json($this->call('GET', "/api/repositories/{$this->publicRepo}", $this->viewer));
        self::assertCount(3, $detail['latest_findings']);
        self::assertCount(2, $detail['recent_runs']);
        self::assertSame(404, $this->call('GET', '/api/repositories/999999', $this->viewer)->status);
    }

    public function testEventsFilterAndPaginate(): void
    {
        $this->seed();
        $ignored = $this->json($this->call('GET', '/api/events', $this->viewer, ['status' => 'ignored']))['items'];
        self::assertSame(['ping'], array_column($ignored, 'ignore_reason'));

        $page1 = $this->json($this->call('GET', '/api/events', $this->viewer, ['limit' => '2']));
        self::assertCount(2, $page1['items']);
        self::assertNotNull($page1['next_before']);
        $page2 = $this->json($this->call('GET', '/api/events', $this->viewer, ['limit' => '2', 'before' => (string) $page1['next_before']]));
        self::assertCount(2, $page2['items']);
        self::assertNull($page2['next_before']);
        self::assertSame([], array_intersect(array_column($page1['items'], 'id'), array_column($page2['items'], 'id')));
    }

    public function testInvalidOrUnknownFiltersAreRejectedNotIgnored(): void
    {
        $bad = $this->json($this->call('GET', '/api/findings', $this->viewer, ['severity' => 'urgent', 'limit' => '1000', 'sort' => 'id']), 422);
        self::assertSame(['severity', 'limit', 'sort'], array_keys($bad['error']['fields']));
        self::assertSame(422, $this->call('GET', '/api/runs', $this->viewer, ['repository_id' => '1 OR 1=1'])->status);
    }

    public function testRunDetailShowsTheTimelineButNeverStoredDiffs(): void
    {
        $this->seed();
        $id = $this->runId('r1');
        $response = $this->call('GET', "/api/runs/{$id}", $this->viewer);
        $run = $this->json($response);

        self::assertStringNotContainsString(self::PATCH_MARKER, $response->body);
        self::assertSame(['extract_changes', 'llm_review'], array_column($run['checkpoints'], 'phase'));
        self::assertSame(['files' => 1, 'files_truncated' => false, 'patch_bytes' => 40, 'redactions' => 1], $run['checkpoints'][0]['summary']);
        self::assertSame(['reason' => 'llm_not_configured'], $run['checkpoints'][1]['summary']);
        self::assertSame(3, $run['findings']);
        self::assertNotNull($run['digest_id']);
        self::assertFalse($run['can_resume']);
        self::assertGreaterThanOrEqual(4000, $run['duration_ms']);
    }

    public function testFindingsFiltersDetailAndHistory(): void
    {
        $this->seed();
        $critical = $this->json($this->call('GET', '/api/findings', $this->viewer, ['severity' => 'critical']))['items'];
        self::assertSame(['Possible github token committed'], array_column($critical, 'title'));

        $pct = $this->json($this->call('GET', '/api/findings', $this->viewer, ['q' => '100%']))['items'];
        self::assertSame(['100% of lines changed'], array_column($pct, 'title'));

        $detail = $this->json($this->call('GET', "/api/findings/{$critical[0]['id']}", $this->viewer));
        self::assertSame('[REDACTED:github_token]', substr($detail['evidence']['snippet'], 7, 23));
        self::assertSame(1, $detail['seen_in_runs']);
        self::assertSame(['file_path' => 'src/a.js', 'line_start' => 3, 'line_end' => 3], $detail['location']);
    }

    public function testOnlyAdminsCanResumeAndOnlyResumableRuns(): void
    {
        $this->seed();
        $checkpointed = $this->runId('r3');

        self::assertSame(403, $this->call('POST', "/api/runs/{$checkpointed}/resume", $this->viewer)->status);

        $ok = $this->json($this->call('POST', "/api/runs/{$checkpointed}/resume", $this->admin), 202);
        self::assertSame('queued', $ok['status']);
        $job = $this->pdo->query("SELECT idempotency_key, payload FROM jobs WHERE id = {$ok['job_id']}")->fetch();
        self::assertSame("run:{$checkpointed}:attempt:2", $job['idempotency_key']);
        self::assertSame('resume', json_decode($job['payload'], true)['trigger']);
        self::assertSame('queued', $this->pdo->query("SELECT status FROM analysis_runs WHERE id = {$checkpointed}")->fetchColumn());

        self::assertSame(409, $this->call('POST', '/api/runs/' . $this->runId('r1') . '/resume', $this->admin)->status);
        self::assertSame(404, $this->call('POST', '/api/runs/999999/resume', $this->admin)->status);
    }

    public function testCancelStopsQueuedWorkAndRefusesFinishedRuns(): void
    {
        $this->seed();
        $queued = $this->runId('r4');
        $this->pdo->exec("INSERT INTO jobs (queue, type, payload, idempotency_key, correlation_id) VALUES ('analysis', 'analysis_run', '{\"run_id\": {$queued}}', 'run:{$queued}:attempt:1', 'corr-dash-run1')");

        self::assertSame(403, $this->call('POST', "/api/runs/{$queued}/cancel", $this->viewer)->status);
        self::assertSame('cancelled', $this->json($this->call('POST', "/api/runs/{$queued}/cancel", $this->admin))['status']);
        self::assertSame('cancelled', $this->pdo->query("SELECT status FROM jobs WHERE idempotency_key = 'run:{$queued}:attempt:1'")->fetchColumn());
        self::assertSame(409, $this->call('POST', "/api/runs/{$queued}/cancel", $this->admin)->status, 'already cancelled');
        self::assertSame(409, $this->call('POST', '/api/runs/' . $this->runId('r1') . '/cancel', $this->admin)->status);
    }

    public function testDigestsListAndDetail(): void
    {
        $this->seed();
        $list = $this->json($this->call('GET', '/api/digests', $this->viewer))['items'];
        self::assertSame(['critical' => 1], $list[0]['by_severity']);

        $detail = $this->json($this->call('GET', "/api/digests/{$list[0]['id']}", $this->viewer));
        self::assertSame('run', $detail['kind']);
        self::assertSame([], $detail['deliveries']);
    }
}
