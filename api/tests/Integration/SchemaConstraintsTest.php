<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Integration;

use Keelwatch\Bootstrap;
use Keelwatch\Database\Migrator;
use PDOException;

/**
 * Proves the database itself rejects bad data, independent of application
 * code: unique keys, check constraints, foreign-key behaviour, UTC defaults.
 */
final class SchemaConstraintsTest extends DatabaseTestCase
{
    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->pdo, Bootstrap::MIGRATIONS))->migrate();
    }

    public function testDuplicateDeliveryIdIsRejected(): void
    {
        $repo = $this->repository();
        $this->delivery('dup-1', $repo);

        $this->assertRejected(fn () => $this->delivery('dup-1', $repo), '23000');
    }

    public function testIgnoredDeliveryNeedsAReasonAndAcceptedMustNotHaveOne(): void
    {
        $this->assertRejected(fn () => $this->pdo->exec(
            "INSERT INTO webhook_deliveries (github_delivery_id, event, status, payload_bytes, correlation_id)
             VALUES ('ign-1', 'issues', 'ignored', 10, 'corr-00000001')"
        ));
        $this->assertRejected(fn () => $this->pdo->exec(
            "INSERT INTO webhook_deliveries (github_delivery_id, event, status, ignore_reason, payload_bytes, correlation_id)
             VALUES ('acc-1', 'push', 'accepted', 'why', 10, 'corr-00000001')"
        ));
    }

    public function testRepositoryFullNameMustLookLikeOwnerSlashName(): void
    {
        $installation = $this->installation();
        $this->assertRejected(fn () => $this->pdo->exec(
            "INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private)
             VALUES ({$installation}, 999, '../etc/passwd', 0)"
        ));
    }

    public function testEventEnvelopeMustBeAnObjectAndShasMustBeLowercaseHex(): void
    {
        $repo = $this->repository();
        $delivery = $this->delivery('env-1', $repo);

        $this->assertRejected(fn () => $this->event($delivery, $repo, '[]', self::SHA_A));
        $this->assertRejected(fn () => $this->event($delivery, $repo, '{}', strtoupper(self::SHA_A)));
        $this->assertRejected(fn () => $this->event($delivery, $repo, '{}', self::SHA_A . "\n"));
        self::assertSame(1, $this->event($delivery, $repo, '{}', self::SHA_A));
    }

    public function testJobIdempotencyKeyMakesDuplicateEnqueueANoOp(): void
    {
        $insert = "INSERT IGNORE INTO jobs (queue, type, payload, idempotency_key, correlation_id)
                   VALUES ('events', 'github_event', '{}', 'delivery:abc', 'corr-00000001')";

        self::assertSame(1, $this->pdo->exec($insert));
        self::assertSame(0, $this->pdo->exec($insert));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
    }

    public function testJobStateRules(): void
    {
        // running requires a lease; queued must not hold one
        $this->assertRejected(fn () => $this->job("status = 'running'"));
        $this->assertRejected(fn () => $this->job("locked_by = 'w1', locked_until = UTC_TIMESTAMP(3)"));
        // unknown queue, attempts beyond max, non-object payload
        $this->assertRejected(fn () => $this->job("queue = 'github.events'"));
        $this->assertRejected(fn () => $this->job('attempts = 6, max_attempts = 5'));
        $this->assertRejected(fn () => $this->job("payload = '[1]'"));

        self::assertSame(1, $this->job("status = 'running', locked_by = 'w1', locked_until = UTC_TIMESTAMP(3) + INTERVAL 1 MINUTE"));
    }

    public function testRunNeedsSaneBudgetAndAReasonWhenItFails(): void
    {
        $repo = $this->repository();
        $this->assertRejected(fn () => $this->insertRun($repo, 'r1', "budget_ms = 500"));
        $this->assertRejected(fn () => $this->insertRun($repo, 'r2', "status = 'failed'"));
        $this->assertRejected(fn () => $this->insertRun($repo, 'r3', "status = 'checkpointed'"));
        self::assertSame(1, $this->insertRun($repo, 'r4', "status = 'failed', failure_reason = 'budget_exhausted:llm_reasoning'"));
        $this->assertRejected(fn () => $this->insertRun($repo, 'r4', ''), '23000');
    }

    public function testFindingProvenanceAndShapeRules(): void
    {
        $repo = $this->repository();
        $this->insertRun($repo, 'rf', '');
        $run = (int) $this->pdo->lastInsertId();

        $this->assertRejected(fn () => $this->finding($run, $repo, "source = 'llm', provider = 'groq'"));
        $this->assertRejected(fn () => $this->finding($run, $repo, "source = 'rule'"));
        $this->assertRejected(fn () => $this->finding($run, $repo, "source = 'osv', fingerprint = 'XYZ'"));
        $this->assertRejected(fn () => $this->finding($run, $repo, "source = 'osv', line_start = 10, line_end = 9"));
        $this->assertRejected(fn () => $this->finding($run, $repo, "source = 'osv', line_start = 0"));

        self::assertSame(1, $this->finding($run, $repo, "source = 'rule', rule_id = 'secrets.generic'"));
        $this->assertRejected(fn () => $this->finding($run, $repo, "source = 'rule', rule_id = 'secrets.generic'"), '23000');
    }

    public function testNotificationDestinationHostMustMatchItsKind(): void
    {
        $installation = $this->installation();
        $insert = fn (string $kind, string $host) => $this->pdo->exec(
            "INSERT INTO notification_destinations (installation_id, kind, label, url_ciphertext, url_host)
             VALUES ({$installation}, '{$kind}', 'team', x'00', '{$host}')"
        );

        $this->assertRejected(fn () => $insert('slack', '169.254.169.254'));
        $this->assertRejected(fn () => $insert('discord', 'hooks.slack.com'));
        $this->assertRejected(fn () => $insert('slack', 'hooks.slack.com.evil.example'));
        self::assertSame(1, $insert('slack', 'hooks.slack.com'));
        self::assertSame(1, $insert('discord', 'discord.com'));
    }

    public function testDeletingARunCascadesButRepositoriesWithHistoryAreProtected(): void
    {
        $repo = $this->repository();
        $this->insertRun($repo, 'rc', '');
        $run = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO analysis_checkpoints (run_id, phase, status) VALUES ({$run}, 'extraction', 'completed')");
        $this->finding($run, $repo, "source = 'osv'");

        $this->assertRejected(fn () => $this->pdo->exec("DELETE FROM repositories WHERE id = {$repo}"), '23000');

        $this->pdo->exec("DELETE FROM analysis_runs WHERE id = {$run}");
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM analysis_checkpoints')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM analysis_findings')->fetchColumn());
    }

    public function testDefaultTimestampsAreUtcEvenIfTheSessionZoneIsNot(): void
    {
        $this->pdo->exec("SET time_zone = '+05:00'");
        $id = $this->installation();
        $this->pdo->exec("SET time_zone = '+00:00'");

        $skew = (float) $this->pdo->query(
            "SELECT ABS(TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP(3))) FROM installations WHERE id = {$id}"
        )->fetchColumn();
        self::assertLessThan(5, $skew, 'created_at default must be UTC regardless of session time zone');
    }

    // ----- helpers ------------------------------------------------------

    private function assertRejected(callable $statement, string $sqlState = 'HY000'): void
    {
        try {
            $statement();
        } catch (PDOException $e) {
            // CHECK violations are 3819 (SQLSTATE HY000); duplicates/FKs are 23000.
            self::assertSame($sqlState, (string) $e->getCode(), $e->getMessage());
            return;
        }
        self::fail('Expected the database to reject the statement.');
    }

    private function installation(): int
    {
        static $n = 0;
        $n++;
        $this->pdo->exec(
            "INSERT INTO installations (github_installation_id, account_login, account_type)
             VALUES (" . (1000 + $n + random_int(0, 1_000_000)) . ", 'example-org', 'Organization')"
        );
        return (int) $this->pdo->lastInsertId();
    }

    private function repository(): int
    {
        $installation = $this->installation();
        $this->pdo->exec(
            "INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private)
             VALUES ({$installation}, " . random_int(1, PHP_INT_MAX >> 1) . ", 'example-org/example-repo', 0)"
        );
        return (int) $this->pdo->lastInsertId();
    }

    private function delivery(string $githubId, int $repo): int
    {
        $this->pdo->exec(
            "INSERT INTO webhook_deliveries (github_delivery_id, event, action, repository_id, status, payload_bytes, correlation_id)
             VALUES ('{$githubId}', 'push', NULL, {$repo}, 'accepted', 100, 'corr-00000001')"
        );
        return (int) $this->pdo->lastInsertId();
    }

    private function event(int $delivery, int $repo, string $envelope, string $sha): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO repository_events (delivery_id, repository_id, schema_version, type, head_sha, occurred_at, envelope)
             VALUES (?, ?, 1, 'push', ?, UTC_TIMESTAMP(3), ?)"
        );
        $stmt->execute([$delivery, $repo, $sha, $envelope]);
        return $stmt->rowCount();
    }

    private function job(string $overrides): int
    {
        static $n = 0;
        $n++;
        $this->pdo->exec(
            "INSERT INTO jobs (queue, type, payload, idempotency_key, correlation_id)
             VALUES ('analysis', 'analysis_run', '{}', 'job-{$n}-" . bin2hex(random_bytes(4)) . "', 'corr-00000001')"
        );
        $id = (int) $this->pdo->lastInsertId();
        return $this->pdo->exec("UPDATE jobs SET {$overrides} WHERE id = {$id}");
    }

    private function insertRun(int $repo, string $key, string $overrides): int
    {
        return $this->insertSet('analysis_runs', [
            'repository_id' => (string) $repo,
            'trigger_type' => "'webhook'",
            'idempotency_key' => "'{$key}'",
            'budget_ms' => '120000',
            'correlation_id' => "'corr-00000001'",
        ], $overrides);
    }

    private function finding(int $run, int $repo, string $overrides): int
    {
        return $this->insertSet('analysis_findings', [
            'run_id' => (string) $run,
            'repository_id' => (string) $repo,
            'phase' => "'security'",
            'fingerprint' => "'" . str_repeat('a', 64) . "'",
            'severity' => "'high'",
            'category' => "'security'",
            'confidence' => "'medium'",
            'title' => "'t'",
            'description' => "'d'",
        ], $overrides);
    }

    /**
     * Inserts defaults merged with "col = expr, col = expr" overrides, so an
     * override replaces a default instead of naming the column twice.
     *
     * @param array<string, string> $defaults column => SQL expression
     */
    private function insertSet(string $table, array $defaults, string $overrides): int
    {
        foreach (preg_split('/,\s*(?=[a-z_]+\s*=)/', $overrides, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            [$column, $expr] = array_map('trim', explode('=', $pair, 2));
            $defaults[$column] = $expr;
        }
        $set = implode(', ', array_map(
            static fn (string $col, string $expr): string => "{$col} = {$expr}",
            array_keys($defaults),
            $defaults,
        ));
        return $this->pdo->exec("INSERT INTO {$table} SET {$set}");
    }
}
