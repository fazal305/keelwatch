<?php

declare(strict_types=1);

namespace Keelwatch\Webhook;

/**
 * Outcome of normalizing one delivery: either an analysable event
 * (contracts/github-event.v1.json envelope) or a reason it was ignored.
 */
final class Normalized
{
    /**
     * @param array<string, mixed>|null $envelope
     */
    private function __construct(
        public readonly ?array $envelope,
        public readonly ?string $ignoreReason,
    ) {
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public static function event(array $envelope): self
    {
        return new self($envelope, null);
    }

    public static function ignored(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isIgnored(): bool
    {
        return $this->ignoreReason !== null;
    }
}
