<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Integration;

use Keelwatch\App;
use Keelwatch\Auth\AuthService;
use Keelwatch\Bootstrap;
use Keelwatch\Database\Migrator;
use Keelwatch\Health\HealthService;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Support\Logger;
use PDO;

/**
 * Sign-in, sessions, CSRF, roles and throttling through the real HTTP
 * kernel and MySQL. Passwords are generated per run.
 */
final class AuthTest extends DatabaseTestCase
{
    private const HOST = 'keelwatch.test';
    private App $app;
    private string $adminPassword;
    private string $viewerPassword;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->pdo, Bootstrap::MIGRATIONS))->migrate();
        $this->config = $this->config->with(['sessionCookieSecure' => false]);
        $this->adminPassword = bin2hex(random_bytes(12));
        $this->viewerPassword = bin2hex(random_bytes(12));
        // Usernames contain non-hex letters, so random hex passwords and hashes
        // can never contain them (the password policy rejects that; see checkPolicy).
        AuthService::createUser($this->pdo, 'alan', $this->adminPassword, 'admin');
        AuthService::createUser($this->pdo, 'vic', $this->viewerPassword, 'viewer');
        $this->now = time();
        $this->app = $this->makeApp();
    }

    private function makeApp(): App
    {
        $connect = fn (): PDO => $this->pdo;
        $logger = new Logger('api', fopen('php://memory', 'wb'));
        return new App(
            $this->config,
            new HealthService($this->config, $connect, Bootstrap::MIGRATIONS, $logger),
            $logger,
            null,
            new AuthService($this->config, $connect, fn (): int => $this->now),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, ?array $json = null, array $headers = [], string $ip = '198.51.100.9'): Response
    {
        $headers = array_merge(['host' => self::HOST, 'origin' => 'http://' . self::HOST], $headers);
        return $this->app->handle(new Request($method, $path, $headers, [], $json === null ? '' : json_encode($json), $ip));
    }

    /**
     * @return array{cookie: string, csrf: string}
     */
    private function signIn(string $username, string $password): array
    {
        $response = $this->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $response->status, $response->body);
        preg_match('/^kw_session=([0-9a-f]{64});/', $response->headers['Set-Cookie'], $m);
        return ['cookie' => 'kw_session=' . $m[1], 'csrf' => json_decode($response->body, true)['csrf_token']];
    }

    public function testSignInSetsAHardenedCookieAndOpensTheDashboard(): void
    {
        $response = $this->request('POST', '/api/auth/login', ['username' => 'ALAN ', 'password' => $this->adminPassword]);

        self::assertSame(200, $response->status);
        self::assertMatchesRegularExpression('/^kw_session=[0-9a-f]{64}; Path=\/; Max-Age=43200; HttpOnly; SameSite=Strict$/', $response->headers['Set-Cookie']);
        $body = json_decode($response->body, true);
        self::assertSame(['id' => $body['user']['id'], 'username' => 'alan', 'role' => 'admin'], $body['user']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['csrf_token']);

        preg_match('/^kw_session=([0-9a-f]{64})/', $response->headers['Set-Cookie'], $m);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE token_hash = '" . hash('sha256', $m[1]) . "'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE token_hash = '{$m[1]}'")->fetchColumn(), 'the raw token is never stored');

        $session = $this->signIn('vic', $this->viewerPassword);
        self::assertSame(200, $this->request('GET', '/api/system/health', null, ['cookie' => $session['cookie']])->status);
    }

    public function testWrongPasswordAndUnknownUserGetTheSameAnswer(): void
    {
        $wrong = $this->request('POST', '/api/auth/login', ['username' => 'alan', 'password' => 'not-the-password-123']);
        $unknown = $this->request('POST', '/api/auth/login', ['username' => 'nobody', 'password' => 'not-the-password-123']);

        self::assertSame(401, $wrong->status);
        self::assertSame($wrong->status, $unknown->status);
        self::assertSame(json_decode($wrong->body, true)['error']['message'], json_decode($unknown->body, true)['error']['message']);
        self::assertArrayNotHasKey('Set-Cookie', $wrong->headers);
    }

    public function testMissingFieldsAreReportedPerField(): void
    {
        $response = $this->request('POST', '/api/auth/login', ['username' => 'alan']);

        self::assertSame(422, $response->status);
        self::assertSame(['password' => 'Enter your password.'], json_decode($response->body, true)['error']['fields']);
    }

    public function testProtectedRoutesNeedAValidSession(): void
    {
        self::assertSame(401, $this->request('GET', '/api/system/health')->status);
        self::assertSame(401, $this->request('GET', '/api/system/health', null, ['cookie' => 'kw_session=' . str_repeat('a', 64)])->status);
        self::assertSame(401, $this->request('GET', '/api/system/health', null, ['cookie' => 'kw_session=../../etc'])->status);
    }

    public function testStateChangesNeedTheCsrfTokenAndAnAllowedOrigin(): void
    {
        $s = $this->signIn('alan', $this->adminPassword);

        $noToken = $this->request('POST', '/api/auth/logout', null, ['cookie' => $s['cookie']]);
        $wrongToken = $this->request('POST', '/api/auth/logout', null, ['cookie' => $s['cookie'], 'x-csrf-token' => str_repeat('0', 64)]);
        $foreign = $this->request('POST', '/api/auth/logout', null, [
            'cookie' => $s['cookie'], 'x-csrf-token' => $s['csrf'], 'origin' => 'https://evil.example.com',
        ]);

        self::assertSame([403, 403, 403], [$noToken->status, $wrongToken->status, $foreign->status]);
        self::assertSame('bad_origin', json_decode($foreign->body, true)['error']['code']);
        self::assertSame(200, $this->request('GET', '/api/auth/session', null, ['cookie' => $s['cookie']])->status, 'still signed in');

        $ok = $this->request('POST', '/api/auth/logout', null, ['cookie' => $s['cookie'], 'x-csrf-token' => $s['csrf']]);
        self::assertSame(204, $ok->status);
        self::assertStringContainsString('Max-Age=0', $ok->headers['Set-Cookie']);
        self::assertSame(401, $this->request('GET', '/api/auth/session', null, ['cookie' => $s['cookie']])->status);
    }

    public function testIdleSessionsExpire(): void
    {
        $s = $this->signIn('vic', $this->viewerPassword);
        $this->pdo->exec('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP(3) - INTERVAL 61 MINUTE');

        self::assertSame(401, $this->request('GET', '/api/auth/session', null, ['cookie' => $s['cookie']])->status);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 'expired session removed');
    }

    public function testAbsoluteLifetimeIsEnforcedEvenForActiveSessions(): void
    {
        $s = $this->signIn('vic', $this->viewerPassword);
        $this->pdo->exec('UPDATE sessions SET expires_at = UTC_TIMESTAMP(3) - INTERVAL 1 SECOND');

        self::assertSame(401, $this->request('GET', '/api/auth/session', null, ['cookie' => $s['cookie']])->status);
    }

    public function testDisabledUsersAreSignedOutImmediately(): void
    {
        $s = $this->signIn('vic', $this->viewerPassword);
        $this->pdo->exec("UPDATE users SET disabled_at = UTC_TIMESTAMP(3) WHERE username = 'vic'");

        self::assertSame(401, $this->request('GET', '/api/auth/session', null, ['cookie' => $s['cookie']])->status);
        self::assertSame(401, $this->request('POST', '/api/auth/login', ['username' => 'vic', 'password' => $this->viewerPassword])->status);
    }

    public function testRepeatedFailuresForOneUserAreThrottled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->request('POST', '/api/auth/login', ['username' => 'alan', 'password' => "wrong-password-{$i}xx"], [], "203.0.113.{$i}")->status);
        }

        $limited = $this->request('POST', '/api/auth/login', ['username' => 'alan', 'password' => $this->adminPassword], [], '203.0.113.99');
        self::assertSame(429, $limited->status, 'even the right password is refused while throttled');
        self::assertGreaterThan(0, (int) $limited->headers['Retry-After']);

        $this->now += 901; // next window
        self::assertSame(200, $this->request('POST', '/api/auth/login', ['username' => 'alan', 'password' => $this->adminPassword])->status);
    }

    public function testThrottleBucketsNeverStoreUsernamesOrIps(): void
    {
        $this->request('POST', '/api/auth/login', ['username' => 'alan', 'password' => 'wrong-password-xx']);
        $dump = json_encode($this->pdo->query('SELECT * FROM login_failures')->fetchAll());

        self::assertStringNotContainsString('alan', $dump);
        self::assertStringNotContainsString('198.51.100.9', $dump);
    }

    public function testPasswordChangeSignsOutOtherSessionsAndEnforcesPolicy(): void
    {
        $laptop = $this->signIn('vic', $this->viewerPassword);
        $phone = $this->signIn('vic', $this->viewerPassword);
        $headers = ['cookie' => $laptop['cookie'], 'x-csrf-token' => $laptop['csrf']];

        $weak = $this->request('POST', '/api/auth/password', ['current_password' => $this->viewerPassword, 'new_password' => 'short'], $headers);
        self::assertSame(422, $weak->status);
        self::assertArrayHasKey('new_password', json_decode($weak->body, true)['error']['fields']);

        $wrongCurrent = $this->request('POST', '/api/auth/password', ['current_password' => 'nope-nope-nope', 'new_password' => 'a brand new passphrase'], $headers);
        self::assertSame(['current_password'], array_keys(json_decode($wrongCurrent->body, true)['error']['fields']));

        $ok = $this->request('POST', '/api/auth/password', ['current_password' => $this->viewerPassword, 'new_password' => 'a brand new passphrase'], $headers);
        self::assertSame(200, $ok->status, $ok->body);
        preg_match('/^kw_session=([0-9a-f]{64})/', $ok->headers['Set-Cookie'], $m);

        self::assertSame(401, $this->request('GET', '/api/auth/session', null, ['cookie' => $phone['cookie']])->status);
        self::assertSame(401, $this->request('GET', '/api/auth/session', null, ['cookie' => $laptop['cookie']])->status);
        self::assertSame(200, $this->request('GET', '/api/auth/session', null, ['cookie' => 'kw_session=' . $m[1]])->status);
    }

    public function testPasswordsAreStoredAsArgon2id(): void
    {
        $hash = (string) $this->pdo->query("SELECT password_hash FROM users WHERE username = 'alan'")->fetchColumn();

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertStringNotContainsString($this->adminPassword, $hash);
    }
}
