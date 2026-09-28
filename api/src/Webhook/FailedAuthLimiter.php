<?php

declare(strict_types=1);

namespace Keelwatch\Webhook;

use PDO;

/**
 * Fixed-window counter of failed signature checks per client. Only called
 * after a signature has already failed, so legitimate GitHub deliveries
 * never pay for it.
 */
final class FailedAuthLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $keySecret,
        private readonly int $limit,
        private readonly int $windowSeconds,
    ) {
    }

    /**
     * Records one failure and reports whether the client is now over the limit.
     *
     * @return array{blocked: bool, retry_after_s: int}
     */
    public function recordFailure(string $clientIp, ?int $now = null): array
    {
        $now ??= time();
        $windowStart = intdiv($now, $this->windowSeconds) * $this->windowSeconds;
        $key = hash_hmac('sha256', $clientIp, $this->keySecret);

        $this->pdo->prepare(
            'INSERT INTO webhook_auth_failures (client_key, window_start, failures)
             VALUES (?, FROM_UNIXTIME(?), 1) AS new
             ON DUPLICATE KEY UPDATE failures = webhook_auth_failures.failures + 1'
        )->execute([$key, $windowStart]);

        $select = $this->pdo->prepare(
            'SELECT failures FROM webhook_auth_failures WHERE client_key = ? AND window_start = FROM_UNIXTIME(?)'
        );
        $select->execute([$key, $windowStart]);
        $failures = (int) $select->fetchColumn();

        // Opportunistic cleanup of old windows; cheap and bounded.
        if (random_int(1, 50) === 1) {
            $this->pdo->exec('DELETE FROM webhook_auth_failures WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 1000');
        }

        return [
            'blocked' => $failures > $this->limit,
            'retry_after_s' => max(1, $windowStart + $this->windowSeconds - $now),
        ];
    }
}
