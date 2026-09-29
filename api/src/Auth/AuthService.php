<?php

declare(strict_types=1);

namespace Keelwatch\Auth;

use Closure;
use Keelwatch\Config;
use PDO;

/**
 * Local accounts and database-backed sessions.
 *
 * - Passwords: Argon2id via password_hash; rehashed on login if the
 *   parameters change.
 * - Unknown usernames still run a full password_verify against a dummy hash,
 *   so response time doesn't reveal which usernames exist.
 * - Sessions: a 256-bit random token lives only in the cookie; the database
 *   stores its SHA-256. Idle and absolute expiry are both enforced.
 * - Throttling: failures are counted per client IP and per username in
 *   fixed windows, keyed with APP_SECRET so neither is stored in clear.
 */
final class AuthService
{
    public const MIN_PASSWORD = 12;
    public const MAX_PASSWORD = 128;
    private const IP_LIMIT = 20;
    private const USER_LIMIT = 5;
    private const WINDOW_S = 900;

    /**
     * Verified against for unknown usernames. Precomputed: generating it per
     * request (PHP starts fresh every request) added a whole password_hash to
     * the unknown-user path, which measured 1.9x slower than a wrong password
     * for a real user (bench/login_timing.php) and so revealed which usernames
     * exist. Its password was random and discarded; it matches no one.
     * AuthTimingTest fails if PHP's Argon2id defaults move away from it.
     */
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$WklnWFp3MElMOEpoZmpBMg$EKtYBNOfPQXS+4rf0453ZggMXL+GiZ/3ne+tehKfURA';

    /**
     * @param Closure(): PDO $connect
     * @param Closure(): int $clock
     */
    public function __construct(
        private readonly Config $config,
        private readonly Closure $connect,
        private readonly ?Closure $clock = null,
    ) {
    }

    // ----- login / logout -------------------------------------------------------

    public function login(string $username, string $password, string $clientIp): LoginResult
    {
        $pdo = ($this->connect)();
        $username = strtolower(trim($username));
        $buckets = [$this->bucket('ip', $clientIp), $this->bucket('user', $username)];

        $retryAfter = $this->throttled($pdo, $buckets);
        if ($retryAfter !== null) {
            return LoginResult::throttled($retryAfter);
        }

        $stmt = $pdo->prepare('SELECT id, username, role, password_hash, disabled_at FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch() ?: null;

        // Always verify something, so timing doesn't reveal unknown usernames.
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $valid = strlen($password) <= self::MAX_PASSWORD && password_verify($password, $hash);

        if ($user === null || !$valid || $user['disabled_at'] !== null) {
            $this->recordFailure($pdo, $buckets);
            return LoginResult::invalid();
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_ARGON2ID), $user['id']]);
        }
        $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$user['id']]);
        $this->purgeExpired($pdo);

        [$token, $csrf] = $this->createSession($pdo, (int) $user['id']);
        return LoginResult::success(
            new AuthContext((int) $user['id'], $user['username'], $user['role'], $csrf, hash('sha256', $token)),
            $token,
        );
    }

    public function logout(AuthContext $auth): void
    {
        ($this->connect)()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$auth->sessionHash]);
    }

    // ----- session validation ---------------------------------------------------------

    public function authenticate(?string $token): ?AuthContext
    {
        if ($token === null || !preg_match('/^[0-9a-f]{64}$/D', $token)) {
            return null;
        }
        $pdo = ($this->connect)();
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            'SELECT s.user_id, s.csrf_token, u.username, u.role, u.disabled_at,
                    s.expires_at > UTC_TIMESTAMP(3) AS within_absolute,
                    s.last_seen_at > UTC_TIMESTAMP(3) - INTERVAL ? MINUTE AS within_idle
             FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ?'
        );
        $stmt->execute([$this->config->sessionIdleMinutes, $hash]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        if (!$row['within_absolute'] || !$row['within_idle'] || $row['disabled_at'] !== null) {
            $pdo->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$hash]);
            return null;
        }
        $pdo->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP(3) WHERE token_hash = ?')->execute([$hash]);
        return new AuthContext((int) $row['user_id'], $row['username'], $row['role'], $row['csrf_token'], $hash);
    }

    public function validCsrf(AuthContext $auth, ?string $header): bool
    {
        return $header !== null && hash_equals($auth->csrfToken, $header);
    }

    // ----- account management -------------------------------------------------------

    /**
     * @throws PasswordPolicyError
     */
    public function changePassword(AuthContext $auth, string $current, string $new): ?string
    {
        $pdo = ($this->connect)();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$auth->userId]);
        if (!password_verify($current, (string) $stmt->fetchColumn())) {
            return null;
        }
        self::checkPolicy($auth->username, $new);
        $pdo->prepare(
            'UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(3),
                              updated_at = UTC_TIMESTAMP(3) WHERE id = ?'
        )->execute([password_hash($new, PASSWORD_ARGON2ID), $auth->userId]);
        // Every existing session ends, including any an attacker might hold;
        // the caller gets a fresh one.
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$auth->userId]);
        [$token] = $this->createSession($pdo, $auth->userId);
        return $token;
    }

    /**
     * @throws PasswordPolicyError
     */
    public static function createUser(PDO $pdo, string $username, string $password, string $role): int
    {
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/D', $username)) {
            throw new PasswordPolicyError('Usernames are 3-64 characters: lowercase letters, digits, dot, dash, underscore.');
        }
        if (!in_array($role, ['admin', 'viewer'], true)) {
            throw new PasswordPolicyError('Role must be admin or viewer.');
        }
        self::checkPolicy($username, $password);
        $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_ARGON2ID), $role]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @throws PasswordPolicyError
     */
    public static function checkPolicy(string $username, string $password): void
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_PASSWORD || $length > self::MAX_PASSWORD) {
            throw new PasswordPolicyError(sprintf('Passwords must be %d to %d characters.', self::MIN_PASSWORD, self::MAX_PASSWORD));
        }
        if (str_contains(strtolower($password), strtolower($username))) {
            throw new PasswordPolicyError('Passwords must not contain the username.');
        }
    }

    public function sessionMaxAgeSeconds(): int
    {
        return $this->config->sessionAbsoluteHours * 3600;
    }

    // ----- internals ---------------------------------------------------------------

    /**
     * @return array{0: string, 1: string} [token for the cookie, csrf token]
     */
    private function createSession(PDO $pdo, int $userId): array
    {
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $pdo->prepare(
            'INSERT INTO sessions (token_hash, user_id, csrf_token, expires_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP(3) + INTERVAL ? HOUR)'
        )->execute([hash('sha256', $token), $userId, $csrf, $this->config->sessionAbsoluteHours]);
        return [$token, $csrf];
    }

    private function bucket(string $kind, string $value): string
    {
        return hash_hmac('sha256', "{$kind}:{$value}", $this->config->appSecret);
    }

    private function windowStart(): int
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        return intdiv($now, self::WINDOW_S) * self::WINDOW_S;
    }

    /**
     * @param array{0: string, 1: string} $buckets [ip bucket, user bucket]
     */
    private function throttled(PDO $pdo, array $buckets): ?int
    {
        $window = $this->windowStart();
        $stmt = $pdo->prepare('SELECT bucket, failures FROM login_failures WHERE bucket IN (?, ?) AND window_start = FROM_UNIXTIME(?)');
        $stmt->execute([$buckets[0], $buckets[1], $window]);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $over = ((int) ($counts[$buckets[0]] ?? 0)) >= self::IP_LIMIT
            || ((int) ($counts[$buckets[1]] ?? 0)) >= self::USER_LIMIT;
        if (!$over) {
            return null;
        }
        $now = $this->clock !== null ? ($this->clock)() : time();
        return max(1, $window + self::WINDOW_S - $now);
    }

    /**
     * @param array{0: string, 1: string} $buckets
     */
    private function recordFailure(PDO $pdo, array $buckets): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO login_failures (bucket, window_start, failures) VALUES (?, FROM_UNIXTIME(?), 1) AS new
             ON DUPLICATE KEY UPDATE failures = login_failures.failures + 1'
        );
        $window = $this->windowStart();
        foreach ($buckets as $bucket) {
            $stmt->execute([$bucket, $window]);
        }
    }

    private function purgeExpired(PDO $pdo): void
    {
        if (random_int(1, 20) === 1) {
            $pdo->exec('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP(3) LIMIT 1000');
            $pdo->exec('DELETE FROM login_failures WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 1000');
        }
    }
}
