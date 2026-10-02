<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Integration;

use Keelwatch\App;
use Keelwatch\Auth\AuthService;
use Keelwatch\Bootstrap;
use Keelwatch\Dashboard\IntegrationRoutes;
use Keelwatch\Database\Migrator;
use Keelwatch\Health\HealthService;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Notify\UrlCipher;
use Keelwatch\Support\Logger;
use PDO;

/**
 * Notification destinations and repository settings through the real kernel
 * and MySQL. No request here ever reaches Slack or Discord: the API only
 * stores destinations; the worker is the only thing that sends.
 */
final class IntegrationsTest extends DatabaseTestCase
{
    private const HOST = 'keelwatch.test';

    private App $app;
    /** @var resource */
    private $logStream;
    private string $key;
    /** @var array{cookie: string, csrf: string} */
    private array $admin;
    /** @var array{cookie: string, csrf: string} */
    private array $viewer;
    private int $installation;
    private int $suspended;
    private int $repo;
    private string $token;
    private string $slackUrl;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->pdo, Bootstrap::MIGRATIONS))->migrate();
        $this->key = random_bytes(32);
        $this->boot($this->key);

        $adminPw = bin2hex(random_bytes(12));
        $viewerPw = bin2hex(random_bytes(12));
        // Usernames contain non-hex letters, so random hex passwords and hashes
        // can never contain them (the password policy rejects that; see checkPolicy).
        AuthService::createUser($this->pdo, 'alan', $adminPw, 'admin');
        AuthService::createUser($this->pdo, 'vic', $viewerPw, 'viewer');
        $this->admin = $this->signIn('alan', $adminPw);
        $this->viewer = $this->signIn('vic', $viewerPw);

        $this->pdo->exec("INSERT INTO installations (github_installation_id, account_login, account_type) VALUES (7001, 'example-org', 'Organization')");
        $this->installation = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO installations (github_installation_id, account_login, account_type, status) VALUES (7002, 'old-org', 'Organization', 'suspended')");
        $this->suspended = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private) VALUES ({$this->installation}, 11, 'example-org/internal', 1)");
        $this->repo = (int) $this->pdo->lastInsertId();

        // Assembled per run, so no webhook-shaped literal lives in the repo.
        $this->token = 'tok' . bin2hex(random_bytes(8));
        $this->slackUrl = 'https://hooks.slack.com/services/T0TEST/B0TEST/' . $this->token;
    }

    private function boot(string $key): void
    {
        $this->config = $this->config->with(['sessionCookieSecure' => false, 'notificationKey' => $key]);
        $connect = fn (): PDO => $this->pdo;
        $this->logStream = fopen('php://memory', 'w+b');
        $logger = new Logger('api', $this->logStream);
        $this->app = new App(
            $this->config,
            new HealthService($this->config, $connect, Bootstrap::MIGRATIONS, $logger),
            $logger,
            null,
            new AuthService($this->config, $connect),
            null,
            new IntegrationRoutes($this->config, $connect, $logger),
        );
    }

    // ----- helpers -----------------------------------------------------------------

    /**
     * @param array{cookie: string, csrf: string}|null $as
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $as, ?array $body = null, bool $csrf = true): Response
    {
        $headers = ['host' => self::HOST, 'origin' => 'http://' . self::HOST, 'content-type' => 'application/json'];
        if ($as !== null) {
            $headers['cookie'] = $as['cookie'];
            if ($csrf) {
                $headers['x-csrf-token'] = $as['csrf'];
            }
        }
        return $this->app->handle(new Request($method, $path, $headers, [], $body === null ? '' : json_encode($body), '198.51.100.30'));
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
        $r = $this->app->handle(new Request('POST', '/api/auth/login', ['host' => self::HOST, 'origin' => 'http://' . self::HOST], [], json_encode(['username' => $user, 'password' => $password]), '198.51.100.30'));
        preg_match('/^kw_session=([0-9a-f]{64})/', $r->headers['Set-Cookie'], $m);
        return ['cookie' => 'kw_session=' . $m[1], 'csrf' => json_decode($r->body, true)['csrf_token']];
    }

    private function logs(): string
    {
        rewind($this->logStream);
        return (string) stream_get_contents($this->logStream);
    }

    /**
     * @return array<string, mixed>
     */
    private function create(array $overrides = []): array
    {
        return $this->json($this->call('POST', '/api/destinations', $this->admin, $overrides + [
            'installation_id' => $this->installation,
            'kind' => 'slack',
            'label' => '#eng-alerts',
            'url' => $this->slackUrl,
            'min_severity' => 'medium',
        ]), 201);
    }

    private function storedCiphertext(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT url_ciphertext FROM notification_destinations WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    // ----- destinations ------------------------------------------------------------

    public function testAdminCreatesADestinationThatIsEncryptedAndNeverEchoed(): void
    {
        $created = $this->create();

        self::assertSame('slack', $created['kind']);
        self::assertSame('hooks.slack.com', $created['url_host']);
        self::assertSame('medium', $created['min_severity']);
        self::assertTrue($created['enabled']);
        self::assertNull($created['last_delivery']);
        self::assertArrayNotHasKey('url', $created);

        $blob = $this->storedCiphertext($created['id']);
        self::assertStringNotContainsString($this->token, $blob);
        self::assertSame($this->slackUrl, (new UrlCipher($this->key))->open($blob));

        $list = $this->call('GET', '/api/destinations', $this->viewer);
        self::assertStringNotContainsString($this->token, $list->body);
        self::assertStringNotContainsString($this->token, $this->logs());
        self::assertStringContainsString('destination created', $this->logs());
    }

    public function testViewersCanReadButNotChange(): void
    {
        $created = $this->create();
        $list = $this->json($this->call('GET', '/api/destinations', $this->viewer));
        self::assertCount(1, $list['items']);
        self::assertTrue($list['encryption_configured']);
        self::assertSame(['example-org', 'old-org'], array_column($list['installations'], 'account_login'));

        self::assertSame(403, $this->call('POST', '/api/destinations', $this->viewer, ['kind' => 'slack'])->status);
        self::assertSame(403, $this->call('PATCH', "/api/destinations/{$created['id']}", $this->viewer, ['enabled' => false])->status);
        self::assertSame(403, $this->call('DELETE', "/api/destinations/{$created['id']}", $this->viewer)->status);
        self::assertSame(401, $this->call('GET', '/api/destinations', null)->status);
    }

    public function testChangesNeedTheCsrfToken(): void
    {
        $created = $this->create();
        $r = $this->call('PATCH', "/api/destinations/{$created['id']}", $this->admin, ['enabled' => false], csrf: false);
        self::assertSame(403, $r->status);
        self::assertTrue($this->json($this->call('GET', '/api/destinations', $this->admin))['items'][0]['enabled']);
    }

    public function testUnsafeUrlsAndBadFieldsAreRefusedWithReasons(): void
    {
        $cases = [
            [['url' => 'https://169.254.169.254/latest/meta-data'], 'url'],
            [['url' => 'https://hooks.slack.com@evil.example/services/T0/B0/x'], 'url'],
            [['url' => 'http://hooks.slack.com/services/T0/B0/x'], 'url'],
            [['kind' => 'discord'], 'url'], // a Slack URL under the Discord kind
            [['kind' => 'teams'], 'kind'],
            [['label' => "two\nlines"], 'label'],
            [['label' => str_repeat('x', 101)], 'label'],
            [['min_severity' => 'urgent'], 'min_severity'],
            [['installation_id' => $this->suspended], 'installation_id'],
            [['installation_id' => 999999], 'installation_id'],
            [['enabled' => 'yes'], 'enabled'],
            [['url_host' => 'hooks.slack.com'], 'url_host'], // unknown field
        ];
        foreach ($cases as [$override, $field]) {
            $body = $this->json($this->call('POST', '/api/destinations', $this->admin, $override + [
                'installation_id' => $this->installation,
                'kind' => 'slack',
                'label' => 'ok',
                'url' => $this->slackUrl,
            ]), 422);
            self::assertArrayHasKey($field, $body['error']['fields'], json_encode($override));
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notification_destinations')->fetchColumn());
        self::assertSame(400, $this->call('POST', '/api/destinations', $this->admin, null)->status);
    }

    public function testWithoutAKeyNothingCanBeStored(): void
    {
        $this->boot('');
        $admin = $this->admin;
        $r = $this->call('POST', '/api/destinations', $admin, ['installation_id' => $this->installation, 'kind' => 'slack', 'label' => 'x', 'url' => $this->slackUrl]);
        self::assertSame(503, $r->status);
        self::assertSame('notifications_not_configured', json_decode($r->body, true)['error']['code']);
        self::assertFalse($this->json($this->call('GET', '/api/destinations', $admin))['encryption_configured']);
    }

    public function testUpdatingSettingsAndReplacingTheUrl(): void
    {
        $created = $this->create();
        $id = $created['id'];
        $before = $this->storedCiphertext($id);

        $updated = $this->json($this->call('PATCH', "/api/destinations/{$id}", $this->admin, ['enabled' => false, 'min_severity' => 'critical', 'label' => 'renamed']));
        self::assertFalse($updated['enabled']);
        self::assertSame('critical', $updated['min_severity']);
        self::assertSame('renamed', $updated['label']);
        self::assertSame($before, $this->storedCiphertext($id), 'settings changes must not touch the URL');

        $newUrl = 'https://hooks.slack.com/services/T0TEST/B0TEST/' . 'new' . $this->token;
        $this->json($this->call('PATCH', "/api/destinations/{$id}", $this->admin, ['url' => $newUrl]));
        self::assertSame($newUrl, (new UrlCipher($this->key))->open($this->storedCiphertext($id)));
        self::assertStringContainsString('"fields":["url"]', $this->logs());
        self::assertStringNotContainsString($this->token, $this->logs());

        // The kind is fixed at creation.
        $discord = $this->json($this->call('PATCH', "/api/destinations/{$id}", $this->admin, ['url' => 'https://discord.com/api/webhooks/1/x']), 422);
        self::assertArrayHasKey('url', $discord['error']['fields']);
        self::assertSame(422, $this->call('PATCH', "/api/destinations/{$id}", $this->admin, ['kind' => 'discord'])->status);
        self::assertSame(404, $this->call('PATCH', '/api/destinations/999999', $this->admin, ['enabled' => true])->status);
    }

    public function testDeletingRemovesTheDestination(): void
    {
        $created = $this->create();
        self::assertSame(204, $this->call('DELETE', "/api/destinations/{$created['id']}", $this->admin)->status);
        self::assertSame(404, $this->call('DELETE', "/api/destinations/{$created['id']}", $this->admin)->status);
        self::assertSame([], $this->json($this->call('GET', '/api/destinations', $this->admin))['items']);
    }

    public function testLastDeliveryIsReported(): void
    {
        $created = $this->create();
        $this->pdo->exec("INSERT INTO digests (installation_id, kind, digest_key, period_start, period_end, content) VALUES ({$this->installation}, 'daily', 'k1', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), '{}')");
        $digest = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO notification_deliveries (destination_id, digest_id, attempt, status, http_status, attempted_at) VALUES ({$created['id']}, {$digest}, 1, 'sent', 200, UTC_TIMESTAMP(3) - INTERVAL 1 HOUR)");
        $this->pdo->exec("INSERT INTO notification_deliveries (destination_id, digest_id, attempt, status, http_status, error) VALUES ({$created['id']}, {$digest}, 2, 'failed', 404, 'HTTP 404')");

        $item = $this->json($this->call('GET', '/api/destinations', $this->viewer))['items'][0];
        self::assertSame(1, $item['sent_count']);
        self::assertSame(['status' => 'failed', 'http_status' => 404, 'error' => 'HTTP 404'], array_diff_key($item['last_delivery'], ['attempted_at' => 1]));
    }

    // ----- repository settings -----------------------------------------------------

    public function testAdminChangesRepositorySettings(): void
    {
        $repo = $this->json($this->call('PATCH', "/api/repositories/{$this->repo}/settings", $this->admin, ['llm_policy' => 'allowed', 'analysis_enabled' => false]));
        self::assertSame('allowed', $repo['llm_policy']);
        self::assertFalse($repo['analysis_enabled']);
        $row = $this->pdo->query("SELECT llm_policy, analysis_enabled FROM repositories WHERE id = {$this->repo}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['llm_policy' => 'allowed', 'analysis_enabled' => 0], ['llm_policy' => $row['llm_policy'], 'analysis_enabled' => (int) $row['analysis_enabled']]);
        self::assertStringContainsString('repository settings updated', $this->logs());
    }

    public function testRepositorySettingsAreValidatedAndAdminOnly(): void
    {
        self::assertSame(403, $this->call('PATCH', "/api/repositories/{$this->repo}/settings", $this->viewer, ['analysis_enabled' => false])->status);
        $bad = $this->json($this->call('PATCH', "/api/repositories/{$this->repo}/settings", $this->admin, ['llm_policy' => 'everything', 'analysis_enabled' => 1]), 422);
        self::assertSame(['llm_policy', 'analysis_enabled'], array_keys($bad['error']['fields']));
        self::assertSame(422, $this->call('PATCH', "/api/repositories/{$this->repo}/settings", $this->admin, ['is_private' => false])->status);
        self::assertSame(404, $this->call('PATCH', '/api/repositories/999999/settings', $this->admin, ['analysis_enabled' => true])->status);
        self::assertSame('none', $this->pdo->query("SELECT llm_policy FROM repositories WHERE id = {$this->repo}")->fetchColumn());
    }
}
