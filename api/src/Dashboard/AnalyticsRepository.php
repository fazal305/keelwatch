<?php

declare(strict_types=1);

namespace Keelwatch\Dashboard;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Daily trends for the analytics page. Days are UTC calendar days.
 *
 * Honesty rules:
 * - a day before Keelwatch started watching the scope is `null` ("no data"),
 *   never 0, so a short history isn't drawn as a quiet one;
 * - durations come from completed runs only (a failed or cancelled run's
 *   duration says nothing about how long analysis takes);
 * - a percentile over fewer than MIN_DURATION_SAMPLES runs is not reported.
 */
final class AnalyticsRepository
{
    public const RANGES = [7, 30, 90];
    public const MIN_DURATION_SAMPLES = 3;
    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
    private const CATEGORIES = ['security', 'dependency', 'logic', 'architecture', 'quality'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null null when the repository doesn't exist
     */
    public function summary(int $days, ?int $repositoryId, ?DateTimeImmutable $now = null): ?array
    {
        $utc = new DateTimeZone('UTC');
        $now ??= new DateTimeImmutable('now', $utc);
        $today = $now->setTime(0, 0);
        $start = $today->sub(new DateInterval('P' . ($days - 1) . 'D'));
        $end = $today->add(new DateInterval('P1D'));
        [$scopeSql, $scopeArgs] = $repositoryId === null ? ['', []] : [' AND r.repository_id = ?', [$repositoryId]];
        $range = [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];

        // When watching began for this scope: the earliest repository row.
        $stmt = $this->pdo->prepare('SELECT MIN(created_at) FROM repositories' . ($repositoryId === null ? '' : ' WHERE id = ?'));
        $stmt->execute($repositoryId === null ? [] : [$repositoryId]);
        $since = $stmt->fetchColumn();
        if (!$since && $repositoryId !== null) {
            return null; // no such repository
        }
        $watchingSince = $since ? (new DateTimeImmutable((string) $since, $utc))->setTime(0, 0) : null;

        // Runs per day, split so failures can be emphasised.
        $stmt = $this->pdo->prepare(
            "SELECT DATE(r.created_at) AS d, COUNT(*) AS runs, SUM(r.status = 'failed') AS failed
               FROM analysis_runs r
              WHERE r.created_at >= ? AND r.created_at < ?{$scopeSql}
              GROUP BY d"
        );
        $stmt->execute([...$range, ...$scopeArgs]);
        $runs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $runs[$row['d']] = ['runs' => (int) $row['runs'], 'failed' => (int) $row['failed']];
        }

        // Completed-run durations, bucketed by day in PHP for percentiles.
        $stmt = $this->pdo->prepare(
            "SELECT DATE(r.created_at) AS d,
                    TIMESTAMPDIFF(MICROSECOND, r.started_at, r.finished_at) DIV 1000 AS ms
               FROM analysis_runs r
              WHERE r.status = 'completed' AND r.started_at IS NOT NULL AND r.finished_at IS NOT NULL
                AND r.created_at >= ? AND r.created_at < ?{$scopeSql}"
        );
        $stmt->execute([...$range, ...$scopeArgs]);
        $durations = [];
        $allDurations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ms = max(0, (int) $row['ms']);
            $durations[$row['d']][] = $ms;
            $allDurations[] = $ms;
        }

        // Findings per day: "new" = first time this fingerprint appears for the
        // repository. Stored as is_new when the worker saves findings
        // (migration 0012); computing it per read cost ~450 ms at 60k findings.
        $fScope = $repositoryId === null ? '' : ' AND f.repository_id = ?';
        $stmt = $this->pdo->prepare(
            "SELECT DATE(f.created_at) AS d, COUNT(*) AS total, SUM(f.is_new) AS new_count
               FROM analysis_findings f
              WHERE f.created_at >= ? AND f.created_at < ?{$fScope}
              GROUP BY d"
        );
        $stmt->execute([...$range, ...$scopeArgs]);
        $findings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $findings[$row['d']] = ['total' => (int) $row['total'], 'new' => (int) $row['new_count']];
        }

        $breakdown = function (string $column, array $keys) use ($range, $fScope, $scopeArgs): array {
            $stmt = $this->pdo->prepare(
                "SELECT f.{$column} AS k, COUNT(*) AS n FROM analysis_findings f
                  WHERE f.created_at >= ? AND f.created_at < ?{$fScope} GROUP BY f.{$column}"
            );
            $stmt->execute([...$range, ...$scopeArgs]);
            $counts = array_fill_keys($keys, 0);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['k']] = (int) $row['n'];
            }
            return $counts;
        };

        $series = [];
        for ($day = $start; $day < $end; $day = $day->add(new DateInterval('P1D'))) {
            $key = $day->format('Y-m-d');
            $known = $watchingSince !== null && $day >= $watchingSince;
            $d = $durations[$key] ?? [];
            $enough = count($d) >= self::MIN_DURATION_SAMPLES;
            $series[] = [
                'date' => $key,
                'runs' => $known ? ($runs[$key]['runs'] ?? 0) : null,
                'runs_failed' => $known ? ($runs[$key]['failed'] ?? 0) : null,
                'findings_new' => $known ? ($findings[$key]['new'] ?? 0) : null,
                'findings_recurring' => $known ? (($findings[$key]['total'] ?? 0) - ($findings[$key]['new'] ?? 0)) : null,
                'completed_runs' => count($d),
                'duration_p50_ms' => $enough ? self::percentile($d, 50) : null,
                'duration_p95_ms' => $enough ? self::percentile($d, 95) : null,
            ];
        }

        $sum = static fn (string $field): int => array_sum(array_map(static fn (array $s): int => (int) $s[$field], $series));

        return [
            'range' => ['days' => $days, 'start' => $start->format('Y-m-d'), 'end' => $today->format('Y-m-d'), 'timezone' => 'UTC'],
            'repository_id' => $repositoryId,
            'watching_since' => $watchingSince?->format('Y-m-d'),
            'min_duration_samples' => self::MIN_DURATION_SAMPLES,
            'totals' => [
                'runs' => $sum('runs'),
                'runs_failed' => $sum('runs_failed'),
                'findings_new' => $sum('findings_new'),
                'findings_recurring' => $sum('findings_recurring'),
                'completed_runs' => count($allDurations),
                'duration_p50_ms' => count($allDurations) >= self::MIN_DURATION_SAMPLES ? self::percentile($allDurations, 50) : null,
                'notifications' => $repositoryId === null ? $this->notifications($range) : null,
            ],
            'findings_by_severity' => $breakdown('severity', self::SEVERITIES),
            'findings_by_category' => $breakdown('category', self::CATEGORIES),
            'days' => $series,
        ];
    }

    /**
     * Delivery outcomes are per installation (daily digests have no single
     * repository), so they are only reported for the unfiltered view.
     *
     * @param array{string, string} $range
     * @return array{sent: int, failed: int}
     */
    private function notifications(array $range): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed
               FROM notification_deliveries WHERE attempted_at >= ? AND attempted_at < ?"
        );
        $stmt->execute($range);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['sent' => (int) $row['sent'], 'failed' => (int) $row['failed']];
    }

    /**
     * Nearest-rank percentile: always an observed value, never interpolated.
     *
     * @param list<int> $values
     */
    public static function percentile(array $values, int $p): int
    {
        sort($values);
        $rank = (int) ceil($p / 100 * count($values));
        return $values[max(0, $rank - 1)];
    }
}
