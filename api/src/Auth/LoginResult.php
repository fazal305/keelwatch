<?php

declare(strict_types=1);

namespace Keelwatch\Auth;

final class LoginResult
{
    private function __construct(
        public readonly string $outcome, // success | invalid | throttled
        public readonly ?AuthContext $auth = null,
        public readonly ?string $token = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
    }

    public static function success(AuthContext $auth, string $token): self
    {
        return new self('success', $auth, $token);
    }

    public static function invalid(): self
    {
        return new self('invalid');
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self('throttled', null, null, $retryAfterSeconds);
    }
}
