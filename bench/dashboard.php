<?php

declare(strict_types=1);

/**
 * Dashboard API and webhook timings at scale, in-process through the real
 * kernel (Bootstrap::app) against the TEST database (DB_TEST_NAME), which is
 * dropped, migrated and seeded with a synthetic 90-day history first. The
 * development database is never touched. This measures PHP + MySQL work per
 * request; it excludes HTTP server and network overhead.
 *
 *   php bench/dashboard.php [--scale=1] [--iterations=15] [--only=/api/findings]
 */

require __DIR__ . '/../api/vendor/autoload.php';

use Keelwatch\Auth\AuthService;
use Keelwatch\Bootstrap;
use Keelwatch\Config;
use Keelwatch\Database\Connection;
use Keelwatch\Database\Migrator;
use Keelwatch\Http\Request;
use Keelwatch\Support\Env;
use Keelwatch\Support\Logger;
use Keelwatch\Webhook\Signature;

$opts = getopt('', ['scale::', 'iterations::', 'only::', 'no-seed']);
$scale = max(0.01, (float) ($opts['scale'] ?? 1));
$iterations = max(3, (int) ($opts['iterations'] ?? 15));

$env = Env::load(Bootstrap::ROOT . '/.env');
$testDb = trim($env['DB_TEST_NAME'] ?? '');
$base = Config::fromEnv($env);
if ($testDb === '' || $testDb === $base->dbName) {
    fwrite(STDERR, "DB_TEST_NAME must be set and differ from DB_NAME; refusing to touch the dev database.\n");
    exit(2);
}
$config = $base->with(['dbName' => $testDb, 'sessionCookieSecure' => false]);
$pdo = Connection::open($config);

$counts = [
    'repositories' => 25,
    'events' => (int) (40000 * $scale),
    'runs' => (int) (20000 * $scale),
    'findings' => (int) (60000 * $scale),
    'digests' => (int) (5000 * $scale),
];

if (!isset($opts['no-seed'])) {
    $t = hrtime(true);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    (new Migrator($pdo, Bootstrap::MIGRATIONS))->migrate();
    seed($pdo, $counts);
    printf("Seeded %s in %.1f s\n", json_encode($counts), (hrtime(true) - $t) / 1e9);
}
// Fresh statistics so the planner sees the seeded volume (ANALYZE returns rows: consume them).
$pdo->query('ANALYZE TABLE repositories, repository_events, webhook_deliveries, analysis_runs, analysis_checkpoints, analysis_findings, digests, jobs')->fetchAll();

$logger = new Logger('api', fopen('php://memory', 'wb'));
$app = Bootstrap::app($config, $logger);
$host = 'keelwatch.bench';
$password = bin2hex(random_bytes(12));
$pdo->exec("DELETE FROM users WHERE username = 'bench-viewer'");
AuthService::createUser($pdo, 'bench-viewer', $password, 'viewer');
$login = $app->handle(new Request('POST', '/api/auth/login', ['host' => $host, 'origin' => "http://{$host}"], [], json_encode(['username' => 'bench-viewer', 'password' => $password]), '198.51.100.99'));
preg_match('/^kw_session=([0-9a-f]{64})/', $login->headers['Set-Cookie'] ?? '', $m) || exit("sign-in failed: {$login->status}\n");
$cookie = 'kw_session=' . $m[1];

$ids = [
    'repo' => (int) $pdo->query('SELECT MIN(id) FROM repositories')->fetchColumn(),
    'run' => (int) $pdo->query("SELECT MAX(id) FROM analysis_runs WHERE status = 'completed'")->fetchColumn(),
    'finding' => (int) $pdo->query('SELECT MAX(id) FROM analysis_findings')->fetchColumn(),
    'digest' => (int) $pdo->query('SELECT MAX(id) FROM digests')->fetchColumn(),
];

$endpoints = [
    ['/api/overview', []],
    ['/api/repositories', []],
    ["/api/repositories/{$ids['repo']}", []],
    ['/api/runs', []],
    ['/api/runs', ['status' => 'failed']],
    ['/api/runs', ['repository_id' => (string) $ids['repo']]],
    ["/api/runs/{$ids['run']}", []],
    ['/api/findings', []],
    ['/api/findings', ['severity' => 'high']],
    ['/api/findings', ['q' => 'token']],
    ['/api/findings', ['repository_id' => (string) $ids['repo'], 'severity' => 'critical']],
    ["/api/findings/{$ids['finding']}", []],
    ['/api/events', []],
    ['/api/events', ['repository_id' => (string) $ids['repo']]],
    ['/api/digests', []],
    ["/api/digests/{$ids['digest']}", []],
    ['/api/analytics', ['days' => '30']],
    ['/api/analytics', ['days' => '90']],
    ['/api/analytics', ['days' => '90', 'repository_id' => (string) $ids['repo']]],
    ['/api/readiness', []],
    ["/api/readiness/repositories/{$ids['repo']}", []],
    ['/api/destinations', []],
    ['/api/system/health', []],
];

$pct = static function (array $v, int $p): float {
    sort($v);
    return $v[(int) max(0, ceil($p / 100 * count($v)) - 1)];
};

printf("\n%-62s %8s %8s %8s %9s\n", 'GET endpoint (in-process, ms)', 'p50', 'p95', 'max', 'bytes');
foreach ($endpoints as [$path, $query]) {
    $label = $path . ($query ? '?' . http_build_query($query) : '');
    if (isset($opts['only']) && !str_contains($label, (string) $opts['only'])) {
        continue;
    }
    $times = [];
    $bytes = 0;
    for ($i = 0; $i < $iterations + 2; $i++) {
        $t = hrtime(true);
        $r = $app->handle(new Request('GET', $path, ['host' => $host, 'cookie' => $cookie], $query, '', '198.51.100.99'));
        $ms = (hrtime(true) - $t) / 1e6;
        if ($r->status !== 200) {
            exit("{$label} returned {$r->status}: {$r->body}\n");
        }
        $bytes = strlen($r->body);
        if ($i >= 2) {
            $times[] = $ms; // first two are warm-up
        }
    }
    printf("%-62s %8.1f %8.1f %8.1f %9d\n", $label, $pct($times, 50), $pct($times, 95), max($times), $bytes);
}

// Webhook: the full verify -> dedupe -> store path for a push delivery.
if (!isset($opts['only']) || str_contains('/webhooks/github', (string) $opts['only'])) {
    $secret = $config->webhookSecrets[0] ?? null;
    if ($secret === null) {
        echo "\nWebhook: skipped (GITHUB_WEBHOOK_SECRET not set)\n";
    } else {
        $body = (string) file_get_contents(Bootstrap::ROOT . '/api/tests/fixtures/github/push.json');
        $times = [];
        $statuses = [];
        for ($i = 0; $i < 200; $i++) {
            $headers = [
                'host' => $host,
                'content-type' => 'application/json',
                'x-github-event' => 'push',
                'x-github-delivery' => 'bench-' . bin2hex(random_bytes(8)),
                'x-hub-signature-256' => Signature::sign($body, $secret),
            ];
            $t = hrtime(true);
            $r = $app->handle(new Request('POST', '/webhooks/github', $headers, [], $body, '198.51.100.98'));
            $times[] = (hrtime(true) - $t) / 1e6;
            $statuses[$r->status] = ($statuses[$r->status] ?? 0) + 1;
        }
        printf("\nWebhook push (200 deliveries, in-process): p50 %.1f ms  p95 %.1f ms  max %.1f ms  statuses %s\n", $pct($times, 50), $pct($times, 95), max($times), json_encode($statuses));
    }
}

/**
 * Synthetic history. Deterministic shape (seeded RNG), realistic skew: a few
 * busy repositories, recurring fingerprints, ~10% failed runs.
 *
 * @param array<string, int> $n
 */
function seed(PDO $pdo, array $n): void
{
    mt_srand(42);
    $bulk = static function (string $sql, array $rows, int $cols) use ($pdo): void {
        foreach (array_chunk($rows, 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, $cols, '?')) . ')'));
            $pdo->prepare($sql . ' VALUES ' . $placeholders)->execute(array_merge(...$chunk));
        }
    };
    $ago = static fn (float $days): string => gmdate('Y-m-d H:i:s', (int) (time() - $days * 86400));
    $weightedRepo = static fn (array $repos): int => $repos[(int) floor(count($repos) * (mt_rand() / mt_getrandmax()) ** 2)];

    $pdo->exec("INSERT INTO installations (github_installation_id, account_login, account_type) VALUES (990001, 'bench-org', 'Organization')");
    $inst = (int) $pdo->lastInsertId();
    $rows = [];
    for ($i = 1; $i <= $n['repositories']; $i++) {
        $rows[] = [$inst, 990000 + $i, "bench-org/service-{$i}", $i % 3 === 0 ? 1 : 0, 'main', $i % 3 === 0 ? 'none' : 'public_only', $ago(120)];
    }
    $bulk('INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private, default_branch, llm_policy, created_at)', $rows, 7);
    $repos = array_map('intval', $pdo->query('SELECT id FROM repositories ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

    // Deliveries + events.
    $rows = [];
    for ($i = 0; $i < $n['events']; $i++) {
        $rows[] = ["bench-d-{$i}", 'push', $weightedRepo($repos), 'accepted', 2048, "corr-bench-{$i}", $ago(mt_rand(0, 89999) / 1000)];
    }
    $bulk('INSERT INTO webhook_deliveries (github_delivery_id, event, repository_id, status, payload_bytes, correlation_id, received_at)', $rows, 7);
    $pdo->exec("INSERT INTO repository_events (delivery_id, repository_id, schema_version, type, actor_login, ref, head_sha, occurred_at, envelope)
                SELECT id, repository_id, 1, IF(id % 4 = 0, 'pull_request.opened', 'push'), 'someone', 'refs/heads/main', SHA1(id), received_at, '{}'
                  FROM webhook_deliveries WHERE github_delivery_id LIKE 'bench-d-%'");

    // Runs.
    $rows = [];
    for ($i = 0; $i < $n['runs']; $i++) {
        $roll = mt_rand(1, 100);
        $status = $roll <= 85 ? 'completed' : ($roll <= 95 ? 'failed' : ($roll <= 98 ? 'cancelled' : 'checkpointed'));
        $d = mt_rand(0, 89999) / 1000;
        $rows[] = [$weightedRepo($repos), 'webhook', "bench-run-{$i}", $status, 120000, "corr-bench-run-{$i}", $status === 'failed' || $status === 'checkpointed' ? 'bench: synthetic failure' : null, $ago($d), $ago($d), in_array($status, ['completed', 'failed'], true) ? $ago($d - mt_rand(800, 9000) / 86400000) : null];
    }
    $bulk('INSERT INTO analysis_runs (repository_id, trigger_type, idempotency_key, status, budget_ms, correlation_id, failure_reason, created_at, started_at, finished_at)', $rows, 10);

    // Checkpoints used by run detail, analytics and readiness.
    $pdo->exec("INSERT INTO analysis_checkpoints (run_id, phase, status, duration_ms, state)
                SELECT id, 'structure', 'completed', 40,
                       JSON_OBJECT('metrics', JSON_OBJECT('lines_added', id % 700, 'lines_deleted', id % 90, 'source_lines_added', id % 300, 'test_files', IF(id % 3 = 0, 0, 2)), 'findings', JSON_ARRAY())
                  FROM analysis_runs WHERE status = 'completed'");
    $pdo->exec("INSERT INTO analysis_checkpoints (run_id, phase, status, duration_ms, state)
                SELECT id, 'dependencies', 'completed', 120, JSON_OBJECT('osv', IF(id % 11 = 0, 'unavailable: timeout', 'queried'))
                  FROM analysis_runs WHERE status = 'completed'");

    // Findings: recurring fingerprints per repository.
    $runs = $pdo->query("SELECT id, repository_id, created_at FROM analysis_runs WHERE status = 'completed'")->fetchAll(PDO::FETCH_NUM);
    $sev = ['critical', 'high', 'high', 'medium', 'medium', 'medium', 'low', 'low', 'info', 'info'];
    $cat = ['security', 'dependency', 'logic', 'architecture', 'quality'];
    $rows = [];
    $seen = [];
    for ($i = 0; $i < $n['findings']; $i++) {
        [$run, $repo, $created] = $runs[mt_rand(0, count($runs) - 1)];
        $fp = hash('sha256', "{$repo}-" . mt_rand(1, 300));
        if (isset($seen["{$run}-{$fp}"])) {
            continue; // unique per run
        }
        $seen["{$run}-{$fp}"] = true;
        $kind = mt_rand(1, 20);
        [$source, $rule] = $kind === 1 ? ['osv', null] : ($kind === 2 ? ['rule', 'secrets.github-token'] : ['rule', 'structure.large-change']);
        $rows[] = [$run, $repo, 'structure', $fp, $sev[mt_rand(0, 9)], $cat[mt_rand(0, 4)], 'medium', $kind === 2 ? 'Possible token in source' : "Synthetic finding {$i}", 'Synthetic benchmark finding.', "src/module_{$i}.js", mt_rand(1, 400), $source, $rule, $created];
    }
    $bulk('INSERT INTO analysis_findings (run_id, repository_id, phase, fingerprint, severity, category, confidence, title, description, file_path, line_start, source, rule_id, created_at)', $rows, 14);
    // The worker sets is_new as it stores findings; bulk-seeded rows get the same definition here.
    $pdo->exec('UPDATE analysis_findings f
                  JOIN (SELECT repository_id, fingerprint, MIN(run_id) AS first_run FROM analysis_findings GROUP BY repository_id, fingerprint) first
                    ON first.repository_id = f.repository_id AND first.fingerprint = f.fingerprint
                   SET f.is_new = (f.run_id = first.first_run)');

    // Digests.
    $rows = [];
    foreach (array_slice($runs, 0, $n['digests']) as $k => [$run, $repo, $created]) {
        $rows[] = [$inst, $repo, 'run', "bench-digest-{$k}", $created, $created, json_encode(['run_id' => $run, 'counts' => ['total' => 3], 'gaps' => []])];
    }
    $bulk('INSERT INTO digests (installation_id, repository_id, kind, digest_key, period_start, period_end, content)', $rows, 7);
    $pdo->exec("INSERT INTO digest_runs (digest_id, run_id) SELECT id, CAST(JSON_EXTRACT(content, '$.run_id') AS UNSIGNED) FROM digests");
}
