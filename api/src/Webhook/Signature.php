<?php

declare(strict_types=1);

namespace Keelwatch\Webhook;

/**
 * GitHub webhook signature check (X-Hub-Signature-256).
 *
 * The HMAC is computed over the exact request bytes. Comparison uses
 * hash_equals, which is constant-time for equal-length strings; the header
 * format is checked first so a malformed header can't reach the compare.
 * The legacy SHA-1 header is deliberately ignored.
 */
final class Signature
{
    private const PREFIX = 'sha256=';

    /**
     * @param list<string> $secrets current secret first, previous one during rotation
     */
    public static function verify(string $rawBody, ?string $header, array $secrets): bool
    {
        if ($secrets === [] || $header === null) {
            return false;
        }
        if (!preg_match('/^sha256=[0-9a-f]{64}$/', $header)) {
            return false;
        }

        $valid = false;
        // Check every secret (no early exit) so timing doesn't reveal which one matched.
        foreach ($secrets as $secret) {
            $expected = self::PREFIX . hash_hmac('sha256', $rawBody, $secret);
            $valid = hash_equals($expected, $header) || $valid;
        }
        return $valid;
    }

    public static function sign(string $rawBody, string $secret): string
    {
        return self::PREFIX . hash_hmac('sha256', $rawBody, $secret);
    }
}
