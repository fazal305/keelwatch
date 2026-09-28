<?php

declare(strict_types=1);

namespace Keelwatch\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Typed, allow-list reads from a decoded GitHub payload. Every accessor
 * validates shape and format and throws NormalizationException otherwise,
 * so nothing unvalidated reaches the database.
 */
final class Payload
{
    private const SHA = '/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/D';
    private const FULL_NAME = '/^[A-Za-z0-9-]+\/[A-Za-z0-9._-]+$/D';
    private const LOGIN = '/^[A-Za-z0-9-]{1,39}(?:\[bot\])?$/D';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function has(string $path): bool
    {
        return $this->get($path) !== null;
    }

    public function string(string $path, int $maxLength = 255): string
    {
        $value = $this->get($path);
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength || str_contains($value, "\0")) {
            throw new NormalizationException("{$path} must be a non-empty string up to {$maxLength} bytes");
        }
        return $value;
    }

    public function optionalString(string $path, int $maxLength = 255): ?string
    {
        return $this->get($path) === null ? null : $this->string($path, $maxLength);
    }

    public function positiveInt(string $path): int
    {
        $value = $this->get($path);
        if (!is_int($value) || $value < 1) {
            throw new NormalizationException("{$path} must be a positive integer");
        }
        return $value;
    }

    public function optionalNonNegativeInt(string $path): ?int
    {
        $value = $this->get($path);
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw new NormalizationException("{$path} must be a non-negative integer");
        }
        return $value;
    }

    public function bool(string $path, ?bool $default = null): bool
    {
        $value = $this->get($path);
        if ($value === null && $default !== null) {
            return $default;
        }
        if (!is_bool($value)) {
            throw new NormalizationException("{$path} must be a boolean");
        }
        return $value;
    }

    public function sha(string $path): string
    {
        $value = $this->string($path, 64);
        if (!preg_match(self::SHA, $value)) {
            throw new NormalizationException("{$path} is not a commit SHA");
        }
        return $value;
    }

    public function fullName(string $path): string
    {
        $value = $this->string($path, 200);
        if (!preg_match(self::FULL_NAME, $value)) {
            throw new NormalizationException("{$path} is not an owner/name repository name");
        }
        return $value;
    }

    public function login(string $path): ?string
    {
        $value = $this->get($path);
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !preg_match(self::LOGIN, $value)) {
            throw new NormalizationException("{$path} is not a GitHub login");
        }
        return $value;
    }

    /**
     * Accepts ISO-8601 strings (any offset) and Unix timestamps; returns UTC
     * in the contract's "…Z" form.
     */
    public function utcTimestamp(string $path): ?string
    {
        $value = $this->get($path);
        if ($value === null) {
            return null;
        }
        try {
            $date = is_int($value)
                ? (new DateTimeImmutable('@' . $value))
                : (is_string($value) ? new DateTimeImmutable($value) : null);
        } catch (Throwable) {
            $date = null;
        }
        if ($date === null) {
            throw new NormalizationException("{$path} is not a timestamp");
        }
        return self::formatUtc($date);
    }

    /**
     * @return list<mixed>
     */
    public function list(string $path): array
    {
        $value = $this->get($path);
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new NormalizationException("{$path} must be a list");
        }
        return $value;
    }

    public static function formatUtc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function get(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
