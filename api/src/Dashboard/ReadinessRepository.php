<?php

declare(strict_types=1);

namespace Keelwatch\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Readiness insights: a checklist of transparent signals per repository,
 * grouped by the GitHub account that installed Keelwatch.
 *
 * Deliberate choices (see docs/adr/0004-readiness-signals.md):
 * - No combined score. Each criterion is met, below, or "not enough data",
 *   with its definition, threshold, sample and limitation returned alongside.
 * - Signals describe repositories as Keelwatch observed them since watching
 *   began. They are not a measure of any person's ability, and commit
 *   authors are never profiled individually.
 * - A criterion without its minimum sample says "not enough data" rather
 *   than guessing in either direction.
 */
final class ReadinessRepository
{
    public const WINDOW_DAYS = 90;

    /**
     * The configured evaluation criteria. Thresholds are defaults; they are
     * returned with every response so the UI always shows the rules applied.
     */
    public const CRITERIA = [
        'recent_activity' => [
            'label' => 'Changed recently',
            'definition' => 'At least one push or pull request event in the last 30 days.',
            'threshold' => 30,
            'limitation' => 'Only activity GitHub reported to Keelwatch counts; work in other repositories or before watching began is invisible.',
        ],
        'steady_activity' => [
            'label' => 'Steady activity',
            'definition' => 'Activity in at least half of the watched weeks (last 90 days).',
            'threshold' => 0.5,
            'min_sample' => 4,
            'limitation' => 'Measures cadence, not effort or value. Holidays, batching work, or squash-merging all lower it.',
        ],
        'tests_with_changes' => [
            'label' => 'Tests change with code',
            'definition' => 'At least half of the analysed changes that add 50+ source lines also touch a test file.',
            'threshold' => 0.5,
            'min_sample' => 5,
            'limitation' => 'Tests are recognised by path and name conventions (tests/, *.test.js, *Test.php, test_*.py…). Existing tests may already cover a change; unconventional layouts are missed.',
        ],
        'reviewable_size' => [
            'label' => 'Reviewable change size',
            'definition' => 'The median analysed change is at most 400 changed lines (generated files and lockfiles excluded).',
            'threshold' => 400,
            'min_sample' => 5,
            'limitation' => 'Size is a proxy for reviewability. Some large changes (renames, migrations, vendored code) are legitimate.',
        ],
        'no_open_serious' => [
            'label' => 'No open serious findings',
            'definition' => 'The latest completed analysis reported no critical or high findings.',
            'threshold' => 0,
            'limitation' => 'Findings are signals from rules, vulnerability data and optional AI review, each with its own confidence; some are false positives and absence is not proof of safety.',
        ],
        'no_secrets' => [
            'label' => 'No exposed secrets',
            'definition' => 'No secret-scanner findings in analysed changes during the last 90 days (a clean result needs 3+ analyses; any finding counts at once).',
            'threshold' => 0,
            'min_sample' => 3,
            'limitation' => 'Pattern-based scanning of changed lines only; it cannot see secrets already in the repository or in history before watching began.',
        ],
        'deps_clean' => [
            'label' => 'No known-vulnerable dependencies added',
            'definition' => 'No dependency added or upgraded in the last 90 days matched a known vulnerability (OSV.dev) (a clean result needs 3+ checked analyses; any match counts at once).',
            'threshold' => 0,
            'min_sample' => 3,
            'limitation' => 'Only exact versions in changed manifests are checked. Existing dependencies and version ranges are not assessed.',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(?int $installationId = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sql = "SELECT i.id AS installation_id, i.account_login, i.account_type, i.status AS installation_status,
                       r.id, r.full_name, r.is_private, r.created_at, r.analysis_enabled
                  FROM repositories r JOIN installations i ON i.id = r.installation_id
                 WHERE r.removed_at IS NULL" . ($installationId === null ? '' : ' AND i.id = ?') . '
                 ORDER BY i.account_login, r.full_name';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($installationId === null ? [] : [$installationId]);
        $repos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $signals = $this->signalsFor(array_map(static fn (array $r): int => (int) $r['id'], $repos), $repos, $now);

        $accounts = [];
        foreach ($repos as $r) {
            $key = (int) $r['installation_id'];
            $accounts[$key] ??= [
                'id' => $key,
                'account_login' => $r['account_login'],
                'account_type' => $r['account_type'],
                'status' => $r['installation_status'],
                'repositories' => [],
            ];
            $accounts[$key]['repositories'][] = self::repoSummary($r) + ['signals' => $signals[(int) $r['id']]];
        }

        return [
            'window_days' => self::WINDOW_DAYS,
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'criteria' => self::criteria(),
            'accounts' => array_values($accounts),
            // Every account, independent of the filter, for the account picker.
            'account_options' => array_map(
                static fn (array $a): array => ['id' => (int) $a['id'], 'account_login' => $a['account_login']],
                $this->pdo->query('SELECT id, account_login FROM installations ORDER BY account_login')->fetchAll(PDO::FETCH_ASSOC),
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function repository(int $id, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stmt = $this->pdo->prepare(
            'SELECT i.id AS installation_id, i.account_login, r.id, r.full_name, r.is_private, r.created_at, r.analysis_enabled
               FROM repositories r JOIN installations i ON i.id = r.installation_id WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return [
            'window_days' => self::WINDOW_DAYS,
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'criteria' => self::criteria(),
            'account' => ['id' => (int) $r['installation_id'], 'account_login' => $r['account_login']],
            'repository' => self::repoSummary($r),
            'signals' => $this->signalsFor([$id], [$r], $now)[$id],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function criteria(): array
    {
        $out = [];
        foreach (self::CRITERIA as $key => $c) {
            $out[] = ['key' => $key] + $c;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function repoSummary(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'full_name' => $r['full_name'],
            'is_private' => (bool) $r['is_private'],
            'analysis_enabled' => (bool) $r['analysis_enabled'],
            'watching_since' => DashboardRepository::iso($r['created_at']),
        ];
    }

    /**
     * Batched: a fixed number of grouped queries however many repositories.
     *
     * @param list<int> $ids
     * @param list<array<string, mixed>> $repos
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function signalsFor(array $ids, array $repos, DateTimeImmutable $now): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $windowStart = $now->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d H:i:s');

        $grouped = function (string $sql, array $args): array {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($args);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int) $row['repository_id']][] = $row;
            }
            return $out;
        };

        // Activity: events in the window, distinct ISO weeks, the latest one.
        $events = $grouped(
            "SELECT repository_id, COUNT(*) AS events, COUNT(DISTINCT YEARWEEK(occurred_at, 3)) AS weeks,
                    MAX(occurred_at) AS last_at, SUM(type = 'push') AS pushes, SUM(type LIKE 'pull_request.%') AS prs
               FROM repository_events
              WHERE repository_id IN ({$in}) AND occurred_at >= ?
                AND (type = 'push' OR type LIKE 'pull_request.%')
              GROUP BY repository_id",
            [...$ids, $windowStart],
        );

        // Change shape of every completed analysis in the window.
        $shapes = $grouped(
            "SELECT r.repository_id,
                    CAST(JSON_EXTRACT(c.state, '$.metrics.lines_added') AS UNSIGNED)
                      + CAST(JSON_EXTRACT(c.state, '$.metrics.lines_deleted') AS UNSIGNED) AS changed,
                    CAST(JSON_EXTRACT(c.state, '$.metrics.source_lines_added') AS UNSIGNED) AS source_added,
                    CAST(JSON_EXTRACT(c.state, '$.metrics.test_files') AS UNSIGNED) AS test_files
               FROM analysis_checkpoints c JOIN analysis_runs r ON r.id = c.run_id
              WHERE r.repository_id IN ({$in}) AND c.phase = 'structure' AND c.status = 'completed'
                AND r.created_at >= ?",
            [...$ids, $windowStart],
        );

        // Latest completed run per repository and its serious findings.
        $latest = $grouped(
            "SELECT r.repository_id, r.id AS run_id, r.created_at,
                    (SELECT COUNT(*) FROM analysis_findings f WHERE f.run_id = r.id AND f.severity IN ('critical', 'high')) AS serious
               FROM analysis_runs r
              WHERE r.repository_id IN ({$in}) AND r.status = 'completed'
                AND r.id = (SELECT MAX(r2.id) FROM analysis_runs r2 WHERE r2.repository_id = r.repository_id AND r2.status = 'completed')",
            $ids,
        );

        // Window findings from secret scanning and vulnerability data.
        $window = $grouped(
            "SELECT f.repository_id, SUM(f.rule_id LIKE 'secrets.%') AS secrets, SUM(f.source = 'osv') AS vulnerable
               FROM analysis_findings f
              WHERE f.repository_id IN ({$in}) AND f.created_at >= ?
              GROUP BY f.repository_id",
            [...$ids, $windowStart],
        );

        // Completed runs in the window, and how many actually checked dependencies.
        $runs = $grouped(
            "SELECT r.repository_id, COUNT(*) AS completed,
                    SUM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.state, '$.osv')), '') NOT LIKE 'unavailable%'
                        AND c.status = 'completed') AS deps_checked
               FROM analysis_runs r
               LEFT JOIN analysis_checkpoints c ON c.run_id = r.id AND c.phase = 'dependencies'
              WHERE r.repository_id IN ({$in}) AND r.status = 'completed' AND r.created_at >= ?
              GROUP BY r.repository_id",
            [...$ids, $windowStart],
        );

        $out = [];
        foreach ($repos as $repo) {
            $id = (int) $repo['id'];
            $since = new DateTimeImmutable((string) $repo['created_at'], new DateTimeZone('UTC'));
            $watchedDays = max(0, (int) floor(($now->getTimestamp() - $since->getTimestamp()) / 86400));
            $watchedWeeks = (int) floor(min($watchedDays, self::WINDOW_DAYS) / 7);
            $ev = $events[$id][0] ?? null;
            $out[$id] = [
                'recent_activity' => self::recentActivity($ev, $watchedDays, $now),
                'steady_activity' => self::steadyActivity($ev, $watchedWeeks),
                'tests_with_changes' => self::testsWithChanges($shapes[$id] ?? []),
                'reviewable_size' => self::reviewableSize($shapes[$id] ?? []),
                'no_open_serious' => self::noOpenSerious($latest[$id][0] ?? null),
                'no_secrets' => self::countCriterion(
                    (int) ($window[$id][0]['secrets'] ?? 0),
                    (int) ($runs[$id][0]['completed'] ?? 0),
                    self::CRITERIA['no_secrets']['min_sample'],
                    ['completed analysis', 'completed analyses'],
                    ['secret finding', 'secret findings'],
                    'No completed analysis in the last 90 days.',
                ),
                'deps_clean' => self::countCriterion(
                    (int) ($window[$id][0]['vulnerable'] ?? 0),
                    (int) ($runs[$id][0]['deps_checked'] ?? 0),
                    self::CRITERIA['deps_clean']['min_sample'],
                    ['analysis that checked dependencies', 'analyses that checked dependencies'],
                    ['known-vulnerable dependency', 'known-vulnerable dependencies'],
                    'No analysis in the last 90 days could check dependencies.',
                ),
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(string $status, ?float $value, string $evidence, int $sample): array
    {
        return ['status' => $status, 'value' => $value, 'evidence' => $evidence, 'sample' => $sample];
    }

    private static function recentActivity(?array $ev, int $watchedDays, DateTimeImmutable $now): array
    {
        $threshold = self::CRITERIA['recent_activity']['threshold'];
        if ($ev !== null) {
            $last = new DateTimeImmutable((string) $ev['last_at'], new DateTimeZone('UTC'));
            $ago = (int) floor(($now->getTimestamp() - $last->getTimestamp()) / 86400);
            $text = $ago === 0 ? 'Last change today.' : "Last change {$ago} " . ($ago === 1 ? 'day' : 'days') . ' ago.';
            return self::result($ago <= $threshold ? 'met' : 'not_met', $ago, $text, (int) $ev['events']);
        }
        if ($watchedDays < $threshold) {
            return self::result('insufficient', null, "No changes yet; watched for {$watchedDays} " . ($watchedDays === 1 ? 'day' : 'days') . '.', 0);
        }
        return self::result('not_met', null, 'No push or pull request events in the last 90 days.', 0);
    }

    private static function steadyActivity(?array $ev, int $watchedWeeks): array
    {
        $c = self::CRITERIA['steady_activity'];
        if ($watchedWeeks < $c['min_sample']) {
            return self::result('insufficient', null, "Watched for {$watchedWeeks} full " . ($watchedWeeks === 1 ? 'week' : 'weeks') . "; needs {$c['min_sample']}.", $watchedWeeks);
        }
        $active = min($watchedWeeks, (int) ($ev['weeks'] ?? 0));
        $share = $active / $watchedWeeks;
        return self::result($share >= $c['threshold'] ? 'met' : 'not_met', round($share, 3), "Active in {$active} of {$watchedWeeks} watched weeks.", $watchedWeeks);
    }

    /**
     * @param list<array<string, mixed>> $shapes
     */
    private static function testsWithChanges(array $shapes): array
    {
        $c = self::CRITERIA['tests_with_changes'];
        $qualifying = array_values(array_filter($shapes, static fn (array $s): bool => (int) $s['source_added'] >= 50));
        $n = count($qualifying);
        if ($n < $c['min_sample']) {
            return self::result('insufficient', null, "{$n} analysed " . ($n === 1 ? 'change adds' : 'changes add') . " 50+ source lines; needs {$c['min_sample']}.", $n);
        }
        $withTests = count(array_filter($qualifying, static fn (array $s): bool => (int) $s['test_files'] > 0));
        $share = $withTests / $n;
        return self::result($share >= $c['threshold'] ? 'met' : 'not_met', round($share, 3), "{$withTests} of {$n} qualifying changes touched tests.", $n);
    }

    /**
     * @param list<array<string, mixed>> $shapes
     */
    private static function reviewableSize(array $shapes): array
    {
        $c = self::CRITERIA['reviewable_size'];
        $n = count($shapes);
        if ($n < $c['min_sample']) {
            return self::result('insufficient', null, "{$n} analysed " . ($n === 1 ? 'change' : 'changes') . "; needs {$c['min_sample']}.", $n);
        }
        $median = AnalyticsRepository::percentile(array_map(static fn (array $s): int => (int) $s['changed'], $shapes), 50);
        return self::result($median <= $c['threshold'] ? 'met' : 'not_met', $median, "Median change: {$median} lines across {$n} analysed changes.", $n);
    }

    private static function noOpenSerious(?array $latest): array
    {
        if ($latest === null) {
            return self::result('insufficient', null, 'No completed analysis yet.', 0);
        }
        $serious = (int) $latest['serious'];
        return self::result(
            $serious === 0 ? 'met' : 'not_met',
            $serious,
            $serious === 0 ? 'Latest completed analysis has none.' : "{$serious} critical or high " . ($serious === 1 ? 'finding' : 'findings') . ' in the latest completed analysis.',
            1,
        );
    }

    /**
     * @param array{string, string} $sampleNoun singular, plural
     * @param array{string, string} $noun singular, plural
     */
    /**
     * Asymmetric on purpose: one finding is conclusive, but a clean result
     * over too few analyses is not evidence of anything yet.
     *
     * @param array{string, string} $sampleNoun singular, plural
     * @param array{string, string} $noun singular, plural
     */
    private static function countCriterion(int $found, int $sample, int $minSample, array $sampleNoun, array $noun, string $noSample): array
    {
        if ($sample === 0) {
            return self::result('insufficient', null, $noSample, 0);
        }
        $samples = "{$sample} " . $sampleNoun[$sample === 1 ? 0 : 1];
        if ($found > 0) {
            return self::result('not_met', $found, "{$found} " . $noun[$found === 1 ? 0 : 1] . " across {$samples}.", $sample);
        }
        if ($sample < $minSample) {
            return self::result('insufficient', null, "None so far, across {$samples}; needs {$minSample} for a clean result.", $sample);
        }
        return self::result('met', 0, "None across {$samples}.", $sample);
    }
}
