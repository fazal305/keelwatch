<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Integration;

use Keelwatch\App;
use Keelwatch\Bootstrap;
use Keelwatch\Config;
use Keelwatch\Database\Migrator;
use Keelwatch\Health\HealthService;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Support\Logger;
use Keelwatch\Tests\Support\ContractValidator;
use Keelwatch\Webhook\Signature;
use Keelwatch\Webhook\WebhookHandler;
use PDO;
use PDOException;

/**
 * The full webhook path: HTTP kernel → handler → real MySQL.
 */
final class WebhookTest extends DatabaseTestCase
{
    private string $secret;
    /** @var resource */
    private $logStream;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->pdo, Bootstrap::MIGRATIONS))->migrate();
        $this->secret = bin2hex(random_bytes(32));
        $this->logStream = fopen('php://memory', 'w+b');
    }

    // ----- accepted path -------------------------------------------------

    public function testSignedPushIsRecordedNormalizedAndEnqueuedAtomically(): void
    {
        $response = $this->send('push', $this->fixture('push'), 'd-push-1');

        self::assertSame(202, $response->status, $response->body);
        self::assertSame(['status' => 'accepted', 'delivery_id' => 'd-push-1'], json_decode($response->body, true));
        self::assertMatchesRegularExpression('/verify;dur=[\d.]+, db;dur=[\d.]+, total;dur=[\d.]+/', $response->headers['Server-Timing']);

        $delivery = $this->row('SELECT * FROM webhook_deliveries');
        self::assertSame('accepted', $delivery['status']);
        self::assertNotNull($delivery['repository_id']);

        $repo = $this->row('SELECT * FROM repositories');
        self::assertSame('example-org/example-repo', $repo['full_name']);
        self::assertSame('public_only', $repo['llm_policy']);

        $event = $this->row('SELECT * FROM repository_events');
        self::assertSame('push', $event['type']);
        self::assertSame('2026-09-27 12:05:00.000', $event['occurred_at']);
        self::assertNull(ContractValidator::errors('github-event', json_decode($event['envelope'], true)));

        $job = $this->row('SELECT * FROM jobs');
        self::assertSame('events', $job['queue']);
        self::assertSame('queued', $job['status']);
        self::assertSame("event:{$event['id']}", $job['idempotency_key']);
        $payload = json_decode($job['payload'], true);
        self::assertNull(ContractValidator::errors('event-job', $payload));
        self::assertSame((int) $event['id'], $payload['event_id']);
        self::assertSame($delivery['correlation_id'], $job['correlation_id']);
    }

    public function testNoEmailAddressIsStoredAnywhere(): void
    {
        $this->send('push', $this->fixture('push'), 'd-email-1');
        $this->send('pull_request', $this->fixture('pull_request.opened'), 'd-email-2');

        foreach (['webhook_deliveries', 'repository_events', 'jobs', 'repositories', 'installations'] as $table) {
            $dump = json_encode($this->pdo->query("SELECT * FROM {$table}")->fetchAll());
            self::assertStringNotContainsString('@example.com', $dump, "email found in {$table}");
        }
    }

    public function testRedeliveryOfTheSameDeliveryIdIsANoOp(): void
    {
        $body = $this->fixture('pull_request.opened');
        self::assertSame(202, $this->send('pull_request', $body, 'd-dup')->status);

        $again = $this->send('pull_request', $body, 'd-dup');

        self::assertSame(200, $again->status);
        self::assertSame('duplicate', json_decode($again->body, true)['status']);
        foreach (['webhook_deliveries', 'repository_events', 'jobs'] as $table) {
            self::assertSame(1, $this->rows($table), "{$table} must hold exactly one row");
        }
    }

    // ----- authentication -----------------------------------------------

    public function testBadOrMissingSignatureIsRejectedBeforeAnythingIsStored(): void
    {
        $body = $this->fixture('push');

        $forged = $this->send('push', $body, 'd-forged', ['x-hub-signature-256' => Signature::sign($body, 'not-the-real-secret-xx')]);
        $missing = $this->send('push', $body, 'd-missing', ['x-hub-signature-256' => null]);

        self::assertSame(401, $forged->status);
        self::assertSame(401, $missing->status);
        self::assertSame(0, $this->rows('webhook_deliveries'));
        self::assertSame(0, $this->rows('repositories'));
        self::assertSame(2, (int) $this->pdo->query('SELECT SUM(failures) FROM webhook_auth_failures')->fetchColumn());

        $log = $this->log();
        self::assertStringContainsString('"reason":"bad_signature"', $log);
        self::assertStringNotContainsString($this->secret, $log);
    }

    public function testRepeatedFailuresAreThrottledButValidDeliveriesStillFlow(): void
    {
        $body = $this->fixture('push');
        $forge = fn (int $i): Response => $this->send('push', $body, "d-f{$i}", ['x-hub-signature-256' => Signature::sign($body, 'wrong-secret-value-xxxx')]);

        $statuses = array_map(static fn (Response $r): int => $r->status, array_map($forge, [1, 2, 3, 4]));
        self::assertSame([401, 401, 401, 429], $statuses);
        self::assertMatchesRegularExpression('/^\d+$/', $forge(5)->headers['Retry-After']);

        // Same client IP, valid signature: never throttled.
        self::assertSame(202, $this->send('push', $body, 'd-valid-after-throttle')->status);
    }

    // ----- cheap rejections ---------------------------------------------

    public function testCheapChecksRejectWithoutStoringAnything(): void
    {
        $body = $this->fixture('push');

        self::assertSame(415, $this->send('push', $body, 'd-ct', ['content-type' => 'application/x-www-form-urlencoded'])->status);
        self::assertSame(400, $this->send('push', $body, 'd-ev', ['x-github-event' => 'Push; DROP'])->status);
        self::assertSame(400, $this->send('push', $body, 'bad id with spaces')->status);
        self::assertSame(413, $this->send('push', $body, 'd-big', [], ['webhookMaxBodyBytes' => 100])->status);
        self::assertSame(413, $this->send('push', $body, 'd-cl', ['content-length' => '999999999'])->status);
        self::assertSame(400, $this->send('push', '{not json', 'd-json')->status);

        self::assertSame(0, $this->rows('webhook_deliveries'));
        self::assertSame(0, $this->rows('webhook_auth_failures'), 'cheap rejections are not counted as auth failures');
    }

    // ----- ignored and installation events --------------------------------

    public function testPingUnsupportedAndDeletedBranchAreRecordedAsIgnored(): void
    {
        $cases = [
            ['ping', 'ping', 'ping'],
            ['issues', 'issues.opened', 'unsupported_event'],
            ['pull_request', 'pull_request.labeled', 'unsupported_action'],
            ['push', 'push.deleted', 'branch_deleted'],
        ];
        foreach ($cases as $i => [$event, $fixture, $reason]) {
            $response = $this->send($event, $this->fixture($fixture), "d-ign-{$i}");
            self::assertSame(200, $response->status);
            self::assertSame($reason, json_decode($response->body, true)['reason']);
        }

        self::assertSame(4, $this->rows('webhook_deliveries'));
        self::assertSame(0, $this->rows('repository_events'));
        self::assertSame(0, $this->rows('jobs'));
    }

    public function testInstallationLifecycleSyncsRepositoriesWithPrivacyDefaults(): void
    {
        self::assertSame(202, $this->send('installation', $this->fixture('installation.created'), 'd-inst')->status);

        $repos = $this->pdo->query('SELECT full_name, is_private, llm_policy, removed_at FROM repositories ORDER BY full_name')->fetchAll();
        self::assertSame(
            [
                ['full_name' => 'example-org/example-repo', 'is_private' => 0, 'llm_policy' => 'public_only', 'removed_at' => null],
                ['full_name' => 'example-org/internal-tool', 'is_private' => 1, 'llm_policy' => 'none', 'removed_at' => null],
            ],
            $repos,
        );
        self::assertSame(0, $this->rows('jobs'), 'installation events do not trigger analysis');

        $this->send('installation_repositories', $this->fixture('installation_repositories.removed'), 'd-inst-rm');
        self::assertNotNull($this->row("SELECT removed_at FROM repositories WHERE full_name = 'example-org/internal-tool'")['removed_at']);
    }

    public function testPushToADisabledRepositoryIsIgnored(): void
    {
        $this->send('installation', $this->fixture('installation.created'), 'd-inst');
        $this->pdo->exec("UPDATE repositories SET analysis_enabled = 0 WHERE full_name = 'example-org/example-repo'");

        $response = $this->send('push', $this->fixture('push'), 'd-disabled');

        self::assertSame('analysis_disabled', json_decode($response->body, true)['reason']);
        self::assertSame(0, $this->rows('repository_events'));
        self::assertSame(0, $this->rows('jobs'));
    }

    // ----- malformed-but-signed and failure modes -------------------------

    public function testSignedPayloadWithUnexpectedShapeIsIgnoredNotRetriedForever(): void
    {
        $payload = json_decode($this->fixture('push'), true);
        $payload['after'] = strtoupper($payload['after']);
        $response = $this->send('push', json_encode($payload), 'd-bad-sha');

        self::assertSame(200, $response->status);
        self::assertSame('invalid_payload', json_decode($response->body, true)['reason']);
        self::assertSame(0, $this->rows('repository_events'));
    }

    public function testFailureInsideTheTransactionLeavesNothingHalfApplied(): void
    {
        // Owner type is only read inside the transaction, after the delivery
        // row is written; the rollback must undo it before recording "ignored".
        $payload = json_decode($this->fixture('push'), true);
        $payload['repository']['owner']['type'] = 'Enterprise';
        $response = $this->send('push', json_encode($payload), 'd-owner');

        self::assertSame(200, $response->status);
        self::assertSame('invalid_payload', json_decode($response->body, true)['reason']);
        self::assertSame(1, $this->rows('webhook_deliveries'));
        self::assertSame(0, $this->rows('installations'), 'installation upsert must have been rolled back');
        self::assertSame(0, $this->rows('repository_events'));
    }

    public function testDatabaseOutageAnswers503SoGithubCanRedeliver(): void
    {
        $config = $this->webhookConfig();
        $handler = new WebhookHandler($config, static fn (): PDO => throw new PDOException('SQLSTATE[HY000] [2002] Connection refused'));
        $app = $this->app($config, $handler);

        $response = $app->handle($this->request('push', $this->fixture('push'), 'd-outage'));

        self::assertSame(503, $response->status);
        self::assertSame('storage_unavailable', json_decode($response->body, true)['error']['code']);
        self::assertStringNotContainsString('SQLSTATE', $response->body);
    }

    public function testEndpointRefusesToRunWithoutASecret(): void
    {
        $config = $this->webhookConfig(['webhookSecrets' => []]);
        $app = $this->app($config, new WebhookHandler($config, fn (): PDO => $this->pdo));

        $response = $app->handle($this->request('push', $this->fixture('push'), 'd-nosecret'));

        self::assertSame(503, $response->status);
        self::assertSame(0, $this->rows('webhook_deliveries'));
    }

    // ----- helpers -------------------------------------------------------

    /**
     * @param array<string, string|null> $headers overrides; null removes a header
     * @param array<string, mixed> $configOverrides
     */
    public function testProductionDoesNotExposeStepTimings(): void
    {
        $accepted = $this->send('push', $this->fixture('push'), 'd-prod-timing', [], ['appEnv' => 'production']);
        self::assertSame(202, $accepted->status, $accepted->body);
        self::assertArrayNotHasKey('Server-Timing', $accepted->headers);

        $rejected = $this->send('push', $this->fixture('push'), 'd-prod-timing-bad', ['x-hub-signature-256' => 'sha256=' . str_repeat('0', 64)], ['appEnv' => 'production']);
        self::assertSame(401, $rejected->status);
        self::assertArrayNotHasKey('Server-Timing', $rejected->headers);
    }

    private function send(string $event, string $body, string $deliveryId, array $headers = [], array $configOverrides = []): Response
    {
        $config = $this->webhookConfig($configOverrides);
        $app = $this->app($config, new WebhookHandler($config, fn (): PDO => $this->pdo));
        return $app->handle($this->request($event, $body, $deliveryId, $headers));
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function request(string $event, string $body, string $deliveryId, array $overrides = []): Request
    {
        $headers = array_filter(array_merge([
            'content-type' => 'application/json',
            'x-github-event' => $event,
            'x-github-delivery' => $deliveryId,
            'x-hub-signature-256' => Signature::sign($body, $this->secret),
            'user-agent' => 'GitHub-Hookshot/test',
        ], $overrides), static fn (?string $v): bool => $v !== null);

        return new Request('POST', '/webhooks/github', $headers, [], $body, '203.0.113.7');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function webhookConfig(array $overrides = []): Config
    {
        return $this->config->with(array_merge([
            'webhookSecrets' => [$this->secret],
            'webhookFailedAuthLimit' => 3,
        ], $overrides));
    }

    private function app(Config $config, WebhookHandler $handler): App
    {
        $logger = new Logger('api', $this->logStream);
        $health = new HealthService($config, fn (): PDO => $this->pdo, Bootstrap::MIGRATIONS, $logger);
        return new App($config, $health, $logger, $handler);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/../fixtures/github/{$name}.json");
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $sql): array
    {
        $row = $this->pdo->query($sql)->fetch();
        self::assertIsArray($row, "no row for: {$sql}");
        return $row;
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private function log(): string
    {
        rewind($this->logStream);
        return (string) stream_get_contents($this->logStream);
    }
}
