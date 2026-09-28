<?php

declare(strict_types=1);

namespace Keelwatch\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public array $headers = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($status, $body, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function error(int $status, string $code, string $message, ?string $correlationId = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($correlationId !== null) {
            $error['correlation_id'] = $correlationId;
        }
        return self::json(['error' => $error], $status);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /**
     * Session cookie: HttpOnly, SameSite=Strict, Path=/. In secure mode it
     * uses the __Host- prefix, which browsers only accept over HTTPS with no
     * Domain attribute, so it can't be set or overridden by a subdomain.
     */
    public function withSessionCookie(string $value, int $maxAgeSeconds, bool $secure): self
    {
        $name = $secure ? '__Host-kw_session' : 'kw_session';
        $cookie = sprintf(
            '%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Strict%s',
            $name,
            rawurlencode($value),
            max(0, $maxAgeSeconds),
            $secure ? '; Secure' : '',
        );
        return $this->withHeader('Set-Cookie', $cookie);
    }

    public static function sessionCookieName(bool $secure): string
    {
        return $secure ? '__Host-kw_session' : 'kw_session';
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
