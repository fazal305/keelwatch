<?php

declare(strict_types=1);

namespace Keelwatch\Dashboard;

use PDO;

/**
 * Read models for the dashboard. Every query is parameterised; values that
 * shape SQL (sort order, filters) only ever come from fixed allowlists in
 * DashboardRoutes. Checkpoint state is never returned whole (it holds diff
 * text); only small, whitelisted summaries are.
 */
final class DashboardRepository
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
    public const RUN_STATUSES = ['queued', 'running', 'checkpointed', 'completed', 'failed', 'cancelled'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ----- overview -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $one = fn (string $sql): int => (int) $this->pdo->query($sql)->fetchColumn();

        $latestFindings = $this->pdo->query(
            "SELECT f.severity, COUNT(*) AS n
             FROM analysis_findings f
             JOIN (SELECT repository_id, MAX(id) AS run_id FROM analysis_runs
                   WHERE status = 'completed' GROUP BY repository_id) latest
               ON latest.run_id = f.run_id
             GROUP BY f.severity"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'repositories' => $one('SELECT COUNT(*) FROM repositories WHERE removed_at IS NULL'),
            'runs_7d' => $one("SELECT COUNT(*) FROM analysis_runs WHERE created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY"),
            'events_24h' => $one('SELECT COUNT(*) FROM webhook_deliveries WHERE received_at > UTC_TIMESTAMP() - INTERVAL 1 DAY'),
            'attention' => [
                'failed_runs_24h' => $one("SELECT COUNT(*) FROM analysis_runs WHERE status = 'failed' AND updated_at > UTC_TIMESTAMP() - INTERVAL 1 DAY"),
                'checkpointed_runs' => $one("SELECT COUNT(*) FROM analysis_runs WHERE status = 'checkpointed'"),
                'dead_jobs' => $one("SELECT COUNT(*) FROM jobs WHERE status = 'dead'"),
                'latest_findings_by_severity' => self::severityMap($latestFindings),
            ],
            'recent_runs' => $this->runs([], null, 8)['items'],
            'recent_events' => $this->events([], null, 8)['items'],
        ];
    }

    // ----- repositories ---------------------------------------------------------------------

    /**
     * @param array{q?: string, state?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function repositories(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['state'] ?? 'active') === 'active') {
            $where[] = 'r.removed_at IS NULL';
        } elseif ($filters['state'] === 'removed') {
            $where[] = 'r.removed_at IS NOT NULL';
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = 'r.full_name LIKE ?';
            $params[] = '%' . addcslashes($filters['q'], '%_\\') . '%';
        }
        $sql = "SELECT r.id, r.full_name, r.is_private, r.llm_policy, r.analysis_enabled, r.default_branch,
                       r.removed_at, i.account_login,
                       (SELECT MAX(d.received_at) FROM webhook_deliveries d WHERE d.repository_id = r.id) AS last_event_at,
                       (SELECT MAX(ar.id) FROM analysis_runs ar WHERE ar.repository_id = r.id) AS last_run_id
                FROM repositories r JOIN installations i ON i.id = r.installation_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.full_name LIMIT 500';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = $this->repositorySummary($row);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function repository(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.full_name, r.is_private, r.llm_policy, r.analysis_enabled, r.default_branch,
                    r.removed_at, r.created_at, i.account_login,
                    (SELECT MAX(d.received_at) FROM webhook_deliveries d WHERE d.repository_id = r.id) AS last_event_at,
                    (SELECT MAX(ar.id) FROM analysis_runs ar WHERE ar.repository_id = r.id) AS last_run_id
             FROM repositories r JOIN installations i ON i.id = r.installation_id WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $summary = $this->repositorySummary($row);
        $summary['created_at'] = self::iso($row['created_at']);
        $summary['recent_runs'] = $this->runs(['repository_id' => $id], null, 15)['items'];

        $latest = $this->pdo->prepare("SELECT MAX(id) FROM analysis_runs WHERE repository_id = ? AND status = 'completed'");
        $latest->execute([$id]);
        $latestRun = $latest->fetchColumn();
        $summary['latest_findings'] = $latestRun
            ? $this->findings(['run_id' => (int) $latestRun], null, 50)['items']
            : [];
        $summary['latest_completed_run_id'] = $latestRun ? (int) $latestRun : null;
        return $summary;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function repositorySummary(array $row): array
    {
        $lastRun = null;
        if ($row['last_run_id'] !== null) {
            $stmt = $this->pdo->prepare('SELECT id, status, created_at, finished_at FROM analysis_runs WHERE id = ?');
            $stmt->execute([$row['last_run_id']]);
            $r = $stmt->fetch();
            $counts = $this->pdo->prepare('SELECT severity, COUNT(*) FROM analysis_findings WHERE run_id = ? GROUP BY severity');
            $counts->execute([$row['last_run_id']]);
            $lastRun = [
                'id' => (int) $r['id'],
                'status' => $r['status'],
                'created_at' => self::iso($r['created_at']),
                'finished_at' => self::iso($r['finished_at']),
                'findings_by_severity' => self::severityMap($counts->fetchAll(PDO::FETCH_KEY_PAIR)),
            ];
        }
        return [
            'id' => (int) $row['id'],
            'full_name' => $row['full_name'],
            'account' => $row['account_login'],
            'private' => (bool) $row['is_private'],
            'llm_policy' => $row['llm_policy'],
            'analysis_enabled' => (bool) $row['analysis_enabled'],
            'default_branch' => $row['default_branch'],
            'removed' => $row['removed_at'] !== null,
            'last_event_at' => self::iso($row['last_event_at']),
            'last_run' => $lastRun,
        ];
    }

    // ----- events -----------------------------------------------------------------------------

    /**
     * @param array{status?: string, repository_id?: int, event?: string} $filters
     * @return array{items: list<array<string, mixed>>, next_before: ?int}
     */
    public function events(array $filters, ?int $before, int $limit): array
    {
        [$where, $params] = $this->conditions([
            'd.status = ?' => $filters['status'] ?? null,
            'd.repository_id = ?' => $filters['repository_id'] ?? null,
            'd.event = ?' => $filters['event'] ?? null,
            'd.id < ?' => $before,
        ]);
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.github_delivery_id, d.event, d.action, d.status, d.ignore_reason, d.payload_bytes,
                    d.received_at, d.correlation_id, d.repository_id, r.full_name,
                    e.id AS event_id, e.type AS event_type, e.pr_number, e.head_sha, e.actor_login,
                    -- MIN, not ORDER BY id LIMIT 1: the latter tempted the planner into
                    -- scanning the runs primary key for every event (bench/dashboard.php).
                    (SELECT MIN(ar.id) FROM analysis_runs ar WHERE ar.event_id = e.id) AS run_id
             FROM webhook_deliveries d
             LEFT JOIN repositories r ON r.id = d.repository_id
             LEFT JOIN repository_events e ON e.delivery_id = d.id
             {$where} ORDER BY d.id DESC LIMIT " . ($limit + 1)
        );
        $stmt->execute($params);
        return $this->page($stmt->fetchAll(), $limit, static fn (array $r): array => [
            'id' => (int) $r['id'],
            'delivery_id' => $r['github_delivery_id'],
            'event' => $r['event'],
            'action' => $r['action'],
            'type' => $r['event_type'],
            'status' => $r['status'],
            'ignore_reason' => $r['ignore_reason'],
            'payload_bytes' => (int) $r['payload_bytes'],
            'received_at' => self::iso($r['received_at']),
            'correlation_id' => $r['correlation_id'],
            'repository' => $r['repository_id'] ? ['id' => (int) $r['repository_id'], 'full_name' => $r['full_name']] : null,
            'pull_request' => $r['pr_number'] !== null ? (int) $r['pr_number'] : null,
            'head_sha' => $r['head_sha'],
            'actor' => $r['actor_login'],
            'run_id' => $r['run_id'] !== null ? (int) $r['run_id'] : null,
        ]);
    }

    // ----- runs ----------------------------------------------------------------------------------

    /**
     * @param array{status?: string, repository_id?: int} $filters
     * @return array{items: list<array<string, mixed>>, next_before: ?int}
     */
    public function runs(array $filters, ?int $before, int $limit): array
    {
        [$where, $params] = $this->conditions([
            'ar.status = ?' => $filters['status'] ?? null,
            'ar.repository_id = ?' => $filters['repository_id'] ?? null,
            'ar.id < ?' => $before,
        ]);
        $stmt = $this->pdo->prepare(
            "SELECT ar.id, ar.status, ar.trigger_type, ar.head_sha, ar.current_phase, ar.failure_reason,
                    ar.attempt, ar.budget_ms, ar.created_at, ar.started_at, ar.finished_at,
                    TIMESTAMPDIFF(MICROSECOND, ar.started_at, ar.finished_at) DIV 1000 AS duration_ms,
                    ar.repository_id, r.full_name, r.is_private, e.type AS event_type, e.pr_number,
                    (SELECT COUNT(*) FROM analysis_findings f WHERE f.run_id = ar.id) AS findings
             FROM analysis_runs ar
             JOIN repositories r ON r.id = ar.repository_id
             LEFT JOIN repository_events e ON e.id = ar.event_id
             {$where} ORDER BY ar.id DESC LIMIT " . ($limit + 1)
        );
        $stmt->execute($params);
        return $this->page($stmt->fetchAll(), $limit, fn (array $r): array => $this->runSummary($r));
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function runSummary(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'status' => $r['status'],
            'trigger' => $r['trigger_type'],
            'event_type' => $r['event_type'],
            'pull_request' => $r['pr_number'] !== null ? (int) $r['pr_number'] : null,
            'head_sha' => $r['head_sha'],
            'current_phase' => $r['current_phase'],
            'failure_reason' => $r['failure_reason'],
            'attempt' => (int) $r['attempt'],
            'budget_ms' => (int) $r['budget_ms'],
            'repository' => ['id' => (int) $r['repository_id'], 'full_name' => $r['full_name'], 'private' => (bool) $r['is_private']],
            'findings' => (int) $r['findings'],
            'created_at' => self::iso($r['created_at']),
            'started_at' => self::iso($r['started_at']),
            'finished_at' => self::iso($r['finished_at']),
            // Computed by MySQL from the DATETIME(3) columns, so milliseconds are kept.
            'duration_ms' => $r['duration_ms'] !== null ? max(0, (int) $r['duration_ms']) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function run(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ar.*, r.full_name, r.is_private, e.type AS event_type, e.pr_number,
                    TIMESTAMPDIFF(MICROSECOND, ar.started_at, ar.finished_at) DIV 1000 AS duration_ms,
                    (SELECT COUNT(*) FROM analysis_findings f WHERE f.run_id = ar.id) AS findings
             FROM analysis_runs ar JOIN repositories r ON r.id = ar.repository_id
             LEFT JOIN repository_events e ON e.id = ar.event_id WHERE ar.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $run = $this->runSummary($row);
        $run['correlation_id'] = $row['correlation_id'];

        $cps = $this->pdo->prepare(
            'SELECT phase, attempt, status, started_at, finished_at, duration_ms, error, state
             FROM analysis_checkpoints WHERE run_id = ? ORDER BY id'
        );
        $cps->execute([$id]);
        $run['checkpoints'] = array_map(static fn (array $c): array => [
            'phase' => $c['phase'],
            'attempt' => (int) $c['attempt'],
            'status' => $c['status'],
            'started_at' => self::iso($c['started_at']),
            'finished_at' => self::iso($c['finished_at']),
            'duration_ms' => $c['duration_ms'] !== null ? (int) $c['duration_ms'] : null,
            'error' => $c['error'],
            'summary' => self::stateSummary($c['phase'], $c['status'], $c['state']),
        ], $cps->fetchAll());

        $calls = $this->pdo->prepare(
            'SELECT phase, provider, model, outcome, latency_ms, tokens_in, tokens_out, retry_no, created_at
             FROM provider_calls WHERE run_id = ? ORDER BY id'
        );
        $calls->execute([$id]);
        $run['provider_calls'] = array_map(static fn (array $c): array => [
            'phase' => $c['phase'],
            'provider' => $c['provider'],
            'model' => $c['model'],
            'outcome' => $c['outcome'],
            'latency_ms' => (int) $c['latency_ms'],
            'tokens_in' => $c['tokens_in'] !== null ? (int) $c['tokens_in'] : null,
            'tokens_out' => $c['tokens_out'] !== null ? (int) $c['tokens_out'] : null,
            'retry_no' => (int) $c['retry_no'],
            'created_at' => self::iso($c['created_at']),
        ], $calls->fetchAll());

        $digest = $this->pdo->prepare('SELECT digest_id FROM digest_runs dr JOIN digests d ON d.id = dr.digest_id WHERE dr.run_id = ? AND d.kind = \'run\' LIMIT 1');
        $digest->execute([$id]);
        $digestId = $digest->fetchColumn();
        $run['digest_id'] = $digestId !== false ? (int) $digestId : null;
        $run['can_resume'] = in_array($run['status'], ['failed', 'checkpointed'], true);
        $run['can_cancel'] = in_array($run['status'], ['queued', 'running', 'checkpointed'], true);
        return $run;
    }

    /**
     * A small, whitelisted view of a checkpoint's state. Never the patches.
     *
     * @return array<string, mixed>|null
     */
    public static function stateSummary(string $phase, string $status, mixed $rawState): ?array
    {
        $state = is_string($rawState) ? json_decode($rawState, true) : null;
        if (!is_array($state)) {
            return null;
        }
        if ($status === 'skipped') {
            return ['reason' => is_string($state['reason'] ?? null) ? mb_substr($state['reason'], 0, 300) : null];
        }
        $pick = static fn (array $keys): array => array_intersect_key($state, array_flip($keys));
        return match ($phase) {
            'extract_changes' => [
                'files' => count($state['files'] ?? []),
                'files_truncated' => (bool) ($state['files_truncated'] ?? false),
                'patch_bytes' => (int) ($state['patch_bytes'] ?? 0),
                'redactions' => (int) ($state['redactions'] ?? 0),
            ],
            'secrets', 'structure' => ['findings' => count($state['findings'] ?? [])]
                + ($phase === 'structure' ? ['metrics' => $state['metrics'] ?? null] : []),
            'dependencies' => [
                'manifests' => $state['manifests'] ?? [],
                'changes' => count($state['changes'] ?? []),
                'findings' => count($state['findings'] ?? []),
                'osv' => $state['osv'] ?? null,
            ],
            'context' => $pick(['redactions', 'files_included', 'files_omitted']),
            'llm_review' => $pick(['provider', 'model', 'tokens_in', 'tokens_out', 'unsupported_observations_dropped'])
                + ['observations' => count($state['observations'] ?? [])],
            'normalize_findings' => $pick(['candidates', 'invalid_dropped', 'inserted', 'by_severity']),
            'digest' => $pick(['digest_id', 'notifications_queued', 'notifications']),
            'load_event' => $pick(['event_type', 'pull_request']),
            default => null,
        };
    }

    // ----- run actions ---------------------------------------------------------------------------

    /**
     * Mirrors worker/keelwatch_worker/analysis.py request_resume.
     *
     * @return array{run_id: int, job_id: int}
     */
    public function resumeRun(int $id): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, repository_id, status, attempt, budget_ms, correlation_id FROM analysis_runs WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $run = $stmt->fetch();
            if ($run === false) {
                throw new RunActionRefused('not_found', "Run {$id} does not exist.");
            }
            if (!in_array($run['status'], ['failed', 'checkpointed'], true)) {
                throw new RunActionRefused('not_resumable', "Run {$id} is {$run['status']}; only failed or checkpointed runs can be resumed.");
            }
            $attempt = (int) $run['attempt'] + 1;
            $this->pdo->prepare(
                "UPDATE analysis_runs SET status = 'queued', attempt = ?, failure_reason = NULL, finished_at = NULL,
                        updated_at = UTC_TIMESTAMP(3) WHERE id = ?"
            )->execute([$attempt, $id]);
            $payload = [
                'schema_version' => 1,
                'run_id' => $id,
                'repository_id' => (int) $run['repository_id'],
                'trigger' => 'resume',
                'correlation_id' => $run['correlation_id'],
                'budget_ms' => (int) $run['budget_ms'],
            ];
            $this->pdo->prepare(
                "INSERT INTO jobs (queue, type, payload, idempotency_key, correlation_id)
                 VALUES ('analysis', 'analysis_run', ?, ?, ?)"
            )->execute([json_encode($payload), "run:{$id}:attempt:{$attempt}", $run['correlation_id']]);
            $jobId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return ['run_id' => $id, 'job_id' => $jobId];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Queued/checkpointed runs stop immediately; a running run stops at its
     * next phase boundary (the worker checks between phases).
     */
    public function cancelRun(int $id): string
    {
        $stmt = $this->pdo->prepare(
            "UPDATE analysis_runs SET status = 'cancelled', failure_reason = NULL, updated_at = UTC_TIMESTAMP(3),
                    finished_at = UTC_TIMESTAMP(3)
             WHERE id = ? AND status IN ('queued', 'running', 'checkpointed')"
        );
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 1) {
            // Queued analysis jobs for this run won't do anything now; mark them cancelled.
            $this->pdo->prepare(
                "UPDATE jobs SET status = 'cancelled', updated_at = UTC_TIMESTAMP(3), finished_at = UTC_TIMESTAMP(3)
                 WHERE queue = 'analysis' AND status = 'queued'
                   AND JSON_EXTRACT(payload, '$.run_id') = CAST(? AS UNSIGNED)"
                // PDO binds the id as a string; a JSON number never equals a
                // string, so without the CAST this silently matched nothing.
            )->execute([$id]);
            return 'cancelled';
        }
        $exists = $this->pdo->prepare('SELECT status FROM analysis_runs WHERE id = ?');
        $exists->execute([$id]);
        $status = $exists->fetchColumn();
        if ($status === false) {
            throw new RunActionRefused('not_found', "Run {$id} does not exist.");
        }
        throw new RunActionRefused('not_cancellable', "Run {$id} is already {$status}.");
    }

    // ----- findings ----------------------------------------------------------------------------------

    /**
     * @param array{severity?: string, category?: string, source?: string, repository_id?: int, run_id?: int, q?: string} $filters
     * @return array{items: list<array<string, mixed>>, next_before: ?int}
     */
    public function findings(array $filters, ?int $before, int $limit): array
    {
        [$where, $params] = $this->conditions([
            'f.severity = ?' => $filters['severity'] ?? null,
            'f.category = ?' => $filters['category'] ?? null,
            'f.source = ?' => $filters['source'] ?? null,
            'f.repository_id = ?' => $filters['repository_id'] ?? null,
            'f.run_id = ?' => $filters['run_id'] ?? null,
            'f.title LIKE ?' => ($filters['q'] ?? '') !== '' ? '%' . addcslashes($filters['q'], '%_\\') . '%' : null,
            'f.id < ?' => $before,
        ]);
        $stmt = $this->pdo->prepare(
            "SELECT f.id, f.run_id, f.repository_id, r.full_name, r.is_private, f.phase, f.severity, f.category,
                    f.confidence, f.title, f.file_path, f.line_start, f.source, f.rule_id, f.created_at,
                    NOT f.is_new AS recurring
             FROM analysis_findings f JOIN repositories r ON r.id = f.repository_id
             {$where}
             ORDER BY f.id DESC LIMIT " . ($limit + 1)
        );
        $stmt->execute($params);
        return $this->page($stmt->fetchAll(), $limit, static fn (array $r): array => [
            'id' => (int) $r['id'],
            'run_id' => (int) $r['run_id'],
            'repository' => ['id' => (int) $r['repository_id'], 'full_name' => $r['full_name'], 'private' => (bool) $r['is_private']],
            'phase' => $r['phase'],
            'severity' => $r['severity'],
            'category' => $r['category'],
            'confidence' => $r['confidence'],
            'title' => $r['title'],
            'file_path' => $r['file_path'],
            'line' => $r['line_start'] !== null ? (int) $r['line_start'] : null,
            'source' => $r['source'],
            'rule_id' => $r['rule_id'],
            'recurring' => (bool) $r['recurring'],
            'created_at' => self::iso($r['created_at']),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function finding(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.*, r.full_name, r.is_private, ar.status AS run_status, ar.head_sha, ar.correlation_id
             FROM analysis_findings f
             JOIN repositories r ON r.id = f.repository_id
             JOIN analysis_runs ar ON ar.id = f.run_id WHERE f.id = ?'
        );
        $stmt->execute([$id]);
        $f = $stmt->fetch();
        if ($f === false) {
            return null;
        }
        $history = $this->pdo->prepare(
            'SELECT run_id, created_at FROM analysis_findings WHERE repository_id = ? AND fingerprint = ? ORDER BY run_id'
        );
        $history->execute([$f['repository_id'], $f['fingerprint']]);
        $seen = $history->fetchAll();
        return [
            'id' => (int) $f['id'],
            'run' => ['id' => (int) $f['run_id'], 'status' => $f['run_status'], 'head_sha' => $f['head_sha'], 'correlation_id' => $f['correlation_id']],
            'repository' => ['id' => (int) $f['repository_id'], 'full_name' => $f['full_name'], 'private' => (bool) $f['is_private']],
            'phase' => $f['phase'],
            'fingerprint' => $f['fingerprint'],
            'severity' => $f['severity'],
            'category' => $f['category'],
            'confidence' => $f['confidence'],
            'title' => $f['title'],
            'description' => $f['description'],
            // Evidence was redacted before it was stored.
            'evidence' => $f['evidence'] !== null ? json_decode($f['evidence'], true) : null,
            'location' => $f['file_path'] !== null ? [
                'file_path' => $f['file_path'],
                'line_start' => $f['line_start'] !== null ? (int) $f['line_start'] : null,
                'line_end' => $f['line_end'] !== null ? (int) $f['line_end'] : null,
            ] : null,
            'recommendation' => $f['recommendation'],
            'source' => $f['source'],
            'rule_id' => $f['rule_id'],
            'provider' => $f['provider'],
            'model' => $f['model'],
            'created_at' => self::iso($f['created_at']),
            'first_seen_run_id' => (int) $seen[0]['run_id'],
            'seen_in_runs' => count($seen),
        ];
    }

    // ----- digests ------------------------------------------------------------------------------------

    /**
     * @param array{kind?: string, repository_id?: int} $filters
     * @return array{items: list<array<string, mixed>>, next_before: ?int}
     */
    public function digests(array $filters, ?int $before, int $limit): array
    {
        [$where, $params] = $this->conditions([
            'd.kind = ?' => $filters['kind'] ?? null,
            'd.repository_id = ?' => $filters['repository_id'] ?? null,
            'd.id < ?' => $before,
        ]);
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.kind, d.period_start, d.period_end, d.created_at, d.repository_id, r.full_name,
                    JSON_EXTRACT(d.content, '$.findings.by_severity') AS run_counts,
                    JSON_EXTRACT(d.content, '$.totals.by_severity') AS daily_counts,
                    JSON_EXTRACT(d.content, '$.totals.runs') AS daily_runs,
                    JSON_EXTRACT(d.content, '$.run_id') AS run_id,
                    (SELECT COUNT(*) FROM notification_deliveries nd WHERE nd.digest_id = d.id AND nd.status = 'sent') AS sent
             FROM digests d LEFT JOIN repositories r ON r.id = d.repository_id
             {$where} ORDER BY d.id DESC LIMIT " . ($limit + 1)
        );
        $stmt->execute($params);
        return $this->page($stmt->fetchAll(), $limit, static fn (array $r): array => [
            'id' => (int) $r['id'],
            'kind' => $r['kind'],
            'repository' => $r['repository_id'] ? ['id' => (int) $r['repository_id'], 'full_name' => $r['full_name']] : null,
            'run_id' => $r['run_id'] !== null ? (int) $r['run_id'] : null,
            'runs' => $r['daily_runs'] !== null ? (int) $r['daily_runs'] : null,
            'by_severity' => json_decode((string) ($r['run_counts'] ?? $r['daily_counts'] ?? '{}'), true) ?: [],
            'period_start' => self::iso($r['period_start']),
            'period_end' => self::iso($r['period_end']),
            'created_at' => self::iso($r['created_at']),
            'notifications_sent' => (int) $r['sent'],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function digest(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, kind, repository_id, period_start, period_end, created_at, content FROM digests WHERE id = ?');
        $stmt->execute([$id]);
        $d = $stmt->fetch();
        if ($d === false) {
            return null;
        }
        $deliveries = $this->pdo->prepare(
            'SELECT nd.attempt, nd.status, nd.http_status, nd.error, nd.attempted_at, nt.kind, nt.label
             FROM notification_deliveries nd JOIN notification_destinations nt ON nt.id = nd.destination_id
             WHERE nd.digest_id = ? ORDER BY nd.id'
        );
        $deliveries->execute([$id]);
        return [
            'id' => (int) $d['id'],
            'kind' => $d['kind'],
            'repository_id' => $d['repository_id'] !== null ? (int) $d['repository_id'] : null,
            'period_start' => self::iso($d['period_start']),
            'period_end' => self::iso($d['period_end']),
            'created_at' => self::iso($d['created_at']),
            'content' => json_decode($d['content'], true),
            'deliveries' => array_map(static fn (array $x): array => [
                'destination' => ['kind' => $x['kind'], 'label' => $x['label']],
                'attempt' => (int) $x['attempt'],
                'status' => $x['status'],
                'http_status' => $x['http_status'] !== null ? (int) $x['http_status'] : null,
                'error' => $x['error'],
                'attempted_at' => self::iso($x['attempted_at']),
            ], $deliveries->fetchAll()),
        ];
    }

    // ----- helpers -------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $conditions SQL fragment => value; null values are skipped
     * @return array{0: string, 1: list<mixed>}
     */
    private function conditions(array $conditions): array
    {
        $clauses = [];
        $params = [];
        foreach ($conditions as $sql => $value) {
            if ($value !== null) {
                $clauses[] = $sql;
                $params[] = $value;
            }
        }
        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    /**
     * @param list<array<string, mixed>> $rows fetched with LIMIT $limit + 1
     * @return array{items: list<array<string, mixed>>, next_before: ?int}
     */
    private function page(array $rows, int $limit, callable $map): array
    {
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => array_map($map, $rows),
            'next_before' => $more && $rows !== [] ? (int) end($rows)['id'] : null,
        ];
    }

    /**
     * @param array<string, int|string> $counts
     * @return array<string, int>
     */
    private static function severityMap(array $counts): array
    {
        $out = [];
        foreach (self::SEVERITIES as $s) {
            if (isset($counts[$s]) && (int) $counts[$s] > 0) {
                $out[$s] = (int) $counts[$s];
            }
        }
        return $out;
    }

    public static function iso(?string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === null) {
            return null;
        }
        $base = str_replace(' ', 'T', substr($mysqlDatetime, 0, 19));
        $fraction = strlen($mysqlDatetime) > 19 ? substr($mysqlDatetime, 19, 4) : '';
        return $base . $fraction . 'Z';
    }
}
