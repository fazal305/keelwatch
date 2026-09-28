<?php

declare(strict_types=1);

namespace Keelwatch\Webhook;

use DateTimeImmutable;

/**
 * Builds the contracts/github-event.v1.json envelope from a verified
 * push or pull_request payload. Everything not listed here is dropped,
 * including author/committer/pusher emails.
 */
final class EventNormalizer
{
    public const SCHEMA_VERSION = 1;

    public const PULL_REQUEST_ACTIONS = ['opened', 'synchronize', 'reopened', 'ready_for_review', 'closed'];

    /**
     * @param array<string, mixed> $data
     * @throws NormalizationException when a signed payload has an unexpected shape
     */
    public function normalize(
        string $event,
        string $deliveryId,
        array $data,
        string $correlationId,
        DateTimeImmutable $receivedAt,
    ): Normalized {
        $payload = new Payload($data);

        if ($event !== 'push' && $event !== 'pull_request') {
            return Normalized::ignored('unsupported_event');
        }
        if (!$payload->has('installation.id')) {
            // Repository-level webhooks carry no installation; Keelwatch
            // only processes GitHub App deliveries.
            return Normalized::ignored('no_installation');
        }

        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'delivery_id' => $deliveryId,
            'event' => $event,
        ];
        $common = [
            'correlation_id' => $correlationId,
            'installation' => ['github_id' => $payload->positiveInt('installation.id')],
            'repository' => [
                'github_id' => $payload->positiveInt('repository.id'),
                'full_name' => $payload->fullName('repository.full_name'),
                'private' => $payload->bool('repository.private'),
                'default_branch' => $payload->optionalString('repository.default_branch'),
            ],
            'actor' => ($login = $payload->login('sender.login')) === null ? null : ['login' => $login],
        ];

        return $event === 'push'
            ? $this->push($payload, $base, $common, $receivedAt)
            : $this->pullRequest($payload, $base, $common, $receivedAt);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $common
     */
    private function push(Payload $payload, array $base, array $common, DateTimeImmutable $receivedAt): Normalized
    {
        if ($payload->bool('deleted', false)) {
            // Nothing to analyse on a deleted branch.
            return Normalized::ignored('branch_deleted');
        }

        $occurredAt = $payload->utcTimestamp('head_commit.timestamp')
            ?? Payload::formatUtc($receivedAt);

        return Normalized::event($base + [
            'action' => null,
            'type' => 'push',
            'occurred_at' => $occurredAt,
        ] + $common + [
            'push' => [
                'ref' => $payload->string('ref'),
                'before' => $payload->sha('before'),
                'after' => $payload->sha('after'),
                'commit_count' => count($payload->list('commits')),
                'created' => $payload->bool('created', false),
                'deleted' => false,
                'forced' => $payload->bool('forced', false),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $common
     */
    private function pullRequest(Payload $payload, array $base, array $common, DateTimeImmutable $receivedAt): Normalized
    {
        $action = $payload->string('action', 64);
        if (!in_array($action, self::PULL_REQUEST_ACTIONS, true)) {
            return Normalized::ignored('unsupported_action');
        }

        $state = $payload->string('pull_request.state', 16);
        if ($state !== 'open' && $state !== 'closed') {
            throw new NormalizationException('pull_request.state must be open or closed');
        }

        $occurredAt = $payload->utcTimestamp('pull_request.updated_at')
            ?? Payload::formatUtc($receivedAt);

        return Normalized::event($base + [
            'action' => $action,
            'type' => "pull_request.{$action}",
            'occurred_at' => $occurredAt,
        ] + $common + [
            'pull_request' => [
                'number' => $payload->positiveInt('pull_request.number'),
                'state' => $state,
                'draft' => $payload->bool('pull_request.draft', false),
                'merged' => $payload->bool('pull_request.merged', false),
                'head_sha' => $payload->sha('pull_request.head.sha'),
                'base_sha' => $payload->sha('pull_request.base.sha'),
                'head_ref' => $payload->string('pull_request.head.ref'),
                'base_ref' => $payload->string('pull_request.base.ref'),
                'changed_files' => $payload->optionalNonNegativeInt('pull_request.changed_files'),
                'additions' => $payload->optionalNonNegativeInt('pull_request.additions'),
                'deletions' => $payload->optionalNonNegativeInt('pull_request.deletions'),
            ],
        ]);
    }
}
