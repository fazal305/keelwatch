<?php

declare(strict_types=1);

namespace Keelwatch\Webhook;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Keelwatch\Config;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Support\Logger;
use PDO;
use PDOException;
use Throwable;

/**
 * POST /webhooks/github
 *
 * receive → cheap checks → verify signature → parse → normalize (pure)
 * → one transaction: record delivery (dedupe) → sync installation/repo
 * → event + job → respond. No analysis ever runs in this request.
 */
final class WebhookHandler
{
    private const EVENT_HEADER = 'x-github-event';
    private const DELIVERY_HEADER = 'x-github-delivery';
    private const SIGNATURE_HEADER = 'x-hub-signature-256';

    private const INSTALLATION_EVENTS = ['installation', 'installation_repositories'];

    /** @var array<string, float> milliseconds per step, for Server-Timing and logs */
    private array $timings = [];

    /**
     * @param Closure(): PDO $connect
     */
    public function __construct(
        private readonly Config $config,
        private readonly Closure $connect,
        private readonly EventNormalizer $normalizer = new EventNormalizer(),
    ) {
    }

    public function handle(Request $request, string $correlationId, Logger $logger): Response
    {
        $this->timings = [];
        $started = hrtime(true);

        $response = $this->process($request, $correlationId, $logger);

        $this->timings['total'] = (hrtime(true) - $started) / 1e6;
        $logger->info('webhook handled', [
            'status' => $response->status,
            'event' => $request->header(self::EVENT_HEADER),
            'delivery_id' => $request->header(self::DELIVERY_HEADER),
            'timings_ms' => array_map(static fn (float $ms): float => round($ms, 2), $this->timings),
        ]);

        return $response->withHeader('Server-Timing', $this->serverTiming());
    }

    private function process(Request $request, string $correlationId, Logger $logger): Response
    {
        if ($this->config->webhookSecrets === []) {
            $logger->error('webhook received but GITHUB_WEBHOOK_SECRET is not configured');
            return Response::error(503, 'webhook_not_configured', 'Webhook endpoint is not configured.');
        }

        // ---- Cheap checks: no body read, no crypto, no database. ----------
        $contentType = strtolower((string) $request->header('content-type'));
        if (!str_starts_with($contentType, 'application/json')) {
            return Response::error(415, 'unsupported_media_type', 'Set the webhook content type to application/json.');
        }

        $event = (string) $request->header(self::EVENT_HEADER);
        $deliveryId = (string) $request->header(self::DELIVERY_HEADER);
        if (!preg_match('/^[a-z_]{1,64}$/D', $event) || !preg_match('/^[A-Za-z0-9-]{1,64}$/D', $deliveryId)) {
            return Response::error(400, 'bad_headers', 'Missing or malformed GitHub event headers.');
        }

        $declaredLength = $request->header('content-length');
        if ($declaredLength !== null && ctype_digit($declaredLength) && (int) $declaredLength > $this->config->webhookMaxBodyBytes) {
            return Response::error(413, 'payload_too_large', 'Payload exceeds the configured limit.');
        }
        $body = $request->rawBodyWithin($this->config->webhookMaxBodyBytes);
        if ($body === null) {
            return Response::error(413, 'payload_too_large', 'Payload exceeds the configured limit.');
        }

        // ---- 1. Authenticity, before touching any data. -----------------
        $t = hrtime(true);
        $valid = Signature::verify($body, $request->header(self::SIGNATURE_HEADER), $this->config->webhookSecrets);
        $this->timings['verify'] = (hrtime(true) - $t) / 1e6;

        if (!$valid) {
            return $this->rejectSignature($request, $event, $deliveryId, $logger);
        }

        // ---- 2. Parse and normalize (pure; no I/O). ---------------------
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return Response::error(400, 'invalid_json', 'Payload is not valid JSON.');
        }
        if (!is_array($data)) {
            return Response::error(400, 'invalid_json', 'Payload must be a JSON object.');
        }

        $receivedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $normalized = null;
        $invalidReason = null;
        if (!in_array($event, self::INSTALLATION_EVENTS, true) && $event !== 'ping') {
            try {
                $normalized = $this->normalizer->normalize($event, $deliveryId, $data, $correlationId, $receivedAt);
            } catch (NormalizationException $e) {
                $invalidReason = $e->getMessage();
            }
        }

        // ---- 3. Persist: one transaction, dedupe first. ------------------
        $t = hrtime(true);
        try {
            $pdo = ($this->connect)();
            try {
                $result = $this->persist($pdo, $event, $deliveryId, $data, strlen($body), $correlationId, $normalized, $invalidReason);
            } catch (NormalizationException $e) {
                // A signed payload with an unexpected shape is recorded as
                // ignored, not reported as an outage GitHub would retry forever.
                // The first transaction rolled back, so nothing is half-applied.
                $invalidReason = $e->getMessage();
                $result = $this->persist($pdo, $event, $deliveryId, $data, strlen($body), $correlationId, null, $invalidReason);
            }
        } catch (Throwable $e) {
            $logger->error('webhook persistence failed', [
                'exception' => $e::class,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'delivery_id' => $deliveryId,
            ]);
            // 503 marks the delivery failed on GitHub's side so it can be redelivered.
            return Response::error(503, 'storage_unavailable', 'Could not record the delivery. Please redeliver.');
        } finally {
            $this->timings['db'] = (hrtime(true) - $t) / 1e6;
        }

        if ($invalidReason !== null) {
            $logger->warning('signed webhook payload could not be normalized', [
                'delivery_id' => $deliveryId,
                'event' => $event,
                'reason' => $invalidReason,
            ]);
        }

        return match ($result['status']) {
            'duplicate' => Response::json(['status' => 'duplicate', 'delivery_id' => $deliveryId]),
            'ignored' => Response::json(['status' => 'ignored', 'reason' => $result['reason'], 'delivery_id' => $deliveryId]),
            default => Response::json(['status' => 'accepted', 'delivery_id' => $deliveryId], 202),
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: 'accepted'|'ignored'|'duplicate', reason?: string}
     */
    private function persist(
        PDO $pdo,
        string $event,
        string $deliveryId,
        array $data,
        int $payloadBytes,
        string $correlationId,
        ?Normalized $normalized,
        ?string $invalidReason,
    ): array {
        $ignoreReason = match (true) {
            $event === 'ping' => 'ping',
            $invalidReason !== null => 'invalid_payload',
            $normalized?->isIgnored() === true => $normalized->ignoreReason,
            default => null,
        };

        $pdo->beginTransaction();
        try {
            $deliveryRowId = $this->insertDelivery($pdo, $event, $deliveryId, $data, $payloadBytes, $correlationId, $ignoreReason);
            if ($deliveryRowId === null) {
                $pdo->rollBack();
                return ['status' => 'duplicate'];
            }

            if ($ignoreReason !== null) {
                $pdo->commit();
                return ['status' => 'ignored', 'reason' => $ignoreReason];
            }

            $sync = new InstallationSync($pdo);

            if (in_array($event, self::INSTALLATION_EVENTS, true)) {
                $sync->applyInstallationEvent($event, $data);
                $pdo->commit();
                return ['status' => 'accepted'];
            }

            if ($normalized?->envelope === null) {
                throw new \LogicException('accepted non-installation delivery without an envelope');
            }

            $envelope = $normalized->envelope;
            $payload = new Payload($data);
            $installationId = $sync->upsertInstallation(
                $envelope['installation']['github_id'],
                $payload->string('repository.owner.login', 100),
                $payload->string('repository.owner.type', 16),
            );
            $repositoryId = $sync->upsertRepository(
                $installationId,
                $envelope['repository']['github_id'],
                $envelope['repository']['full_name'],
                $envelope['repository']['private'],
                $envelope['repository']['default_branch'],
            );

            $pdo->prepare('UPDATE webhook_deliveries SET repository_id = ? WHERE id = ?')
                ->execute([$repositoryId, $deliveryRowId]);

            $repo = $pdo->prepare('SELECT analysis_enabled FROM repositories WHERE id = ?');
            $repo->execute([$repositoryId]);
            if ((int) $repo->fetchColumn() !== 1) {
                $pdo->prepare("UPDATE webhook_deliveries SET status = 'ignored', ignore_reason = 'analysis_disabled' WHERE id = ?")
                    ->execute([$deliveryRowId]);
                $pdo->commit();
                return ['status' => 'ignored', 'reason' => 'analysis_disabled'];
            }

            $eventId = $this->insertEvent($pdo, $deliveryRowId, $repositoryId, $envelope);
            $this->enqueue($pdo, $eventId, $repositoryId, $correlationId);

            $pdo->commit();
            return ['status' => 'accepted'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return int|null the new row id, or null if this delivery was already recorded
     */
    private function insertDelivery(
        PDO $pdo,
        string $event,
        string $deliveryId,
        array $data,
        int $payloadBytes,
        string $correlationId,
        ?string $ignoreReason,
    ): ?int {
        $githubInstallationId = is_int($data['installation']['id'] ?? null) ? $data['installation']['id'] : null;
        $githubRepoId = is_int($data['repository']['id'] ?? null) ? $data['repository']['id'] : null;
        $action = is_string($data['action'] ?? null) ? substr($data['action'], 0, 64) : null;

        try {
            $pdo->prepare(
                'INSERT INTO webhook_deliveries
                    (github_delivery_id, event, action, github_installation_id, github_repo_id,
                     status, ignore_reason, payload_bytes, correlation_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $deliveryId,
                $event,
                $action,
                $githubInstallationId,
                $githubRepoId,
                $ignoreReason === null ? 'accepted' : 'ignored',
                $ignoreReason,
                $payloadBytes,
                $correlationId,
            ]);
        } catch (PDOException $e) {
            $driverCode = $e->errorInfo[1] ?? null;
            if ($driverCode === 1062 && str_contains($e->getMessage(), 'uq_deliveries_github_id')) {
                return null;
            }
            throw $e;
        }

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertEvent(PDO $pdo, int $deliveryRowId, int $repositoryId, array $envelope): int
    {
        $pr = $envelope['pull_request'] ?? null;
        $push = $envelope['push'] ?? null;

        $pdo->prepare(
            'INSERT INTO repository_events
                (delivery_id, repository_id, schema_version, type, actor_login, ref,
                 head_sha, base_sha, pr_number, occurred_at, envelope)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $deliveryRowId,
            $repositoryId,
            $envelope['schema_version'],
            $envelope['type'],
            $envelope['actor']['login'] ?? null,
            $push['ref'] ?? $pr['head_ref'] ?? null,
            $push['after'] ?? $pr['head_sha'] ?? null,
            $push['before'] ?? $pr['base_sha'] ?? null,
            $pr['number'] ?? null,
            str_replace(['T', 'Z'], [' ', ''], $envelope['occurred_at']),
            json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function enqueue(PDO $pdo, int $eventId, int $repositoryId, string $correlationId): void
    {
        $payload = [
            'schema_version' => 1,
            'event_id' => $eventId,
            'repository_id' => $repositoryId,
            'correlation_id' => $correlationId,
        ];
        $pdo->prepare(
            "INSERT IGNORE INTO jobs (queue, type, payload, idempotency_key, correlation_id)
             VALUES ('events', 'github_event', ?, ?, ?)"
        )->execute([
            json_encode($payload, JSON_THROW_ON_ERROR),
            "event:{$eventId}",
            $correlationId,
        ]);
    }

    private function rejectSignature(Request $request, string $event, string $deliveryId, Logger $logger): Response
    {
        $context = [
            'reason' => $request->header(self::SIGNATURE_HEADER) === null ? 'missing_signature' : 'bad_signature',
            'event' => $event,
            'delivery_id' => $deliveryId,
            'client_ip' => $request->clientIp,
        ];

        try {
            $t = hrtime(true);
            $limiter = new FailedAuthLimiter(
                ($this->connect)(),
                $this->config->webhookSecrets[0],
                $this->config->webhookFailedAuthLimit,
                $this->config->webhookFailedAuthWindowS,
            );
            $outcome = $limiter->recordFailure($request->clientIp);
            $this->timings['db'] = (hrtime(true) - $t) / 1e6;
        } catch (Throwable $e) {
            // Counting failures is best-effort; the request is rejected regardless.
            $logger->warning('could not record failed webhook signature', ['exception' => $e::class]);
            $outcome = ['blocked' => false, 'retry_after_s' => 0];
        }

        $logger->warning('webhook signature rejected', $context + ['throttled' => $outcome['blocked']]);

        if ($outcome['blocked']) {
            return Response::error(429, 'too_many_failed_attempts', 'Too many requests with invalid signatures.')
                ->withHeader('Retry-After', (string) $outcome['retry_after_s']);
        }
        return Response::error(401, 'invalid_signature', 'Signature verification failed.');
    }

    private function serverTiming(): string
    {
        $parts = [];
        foreach ($this->timings as $name => $ms) {
            $parts[] = sprintf('%s;dur=%.2f', $name, $ms);
        }
        return implode(', ', $parts);
    }
}
