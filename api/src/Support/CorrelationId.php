<?php

declare(strict_types=1);

namespace Keelwatch\Support;

final class CorrelationId
{
    public const HEADER = 'x-request-id';

    /**
     * Reuse a caller-supplied ID only when it is a safe, bounded token;
     * anything else is replaced so it can't inject content into logs.
     */
    public static function resolve(?string $incoming): string
    {
        if ($incoming !== null && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $incoming)) {
            return $incoming;
        }
        return bin2hex(random_bytes(16));
    }
}
