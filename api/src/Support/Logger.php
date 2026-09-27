<?php

declare(strict_types=1);

namespace Keelwatch\Support;

/**
 * Structured JSON-lines logger.
 *
 * Context keys that look like credentials are redacted recursively before
 * anything is written, so a careless call site can't leak a secret.
 */
final class Logger
{
    private const REDACTED = '[REDACTED]';
    private const SENSITIVE_KEY = '/pass|secret|token|authorization|signature|cookie|api[_-]?key|private[_-]?key|credential/i';

    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream Defaults to STDERR so logs never mix with HTTP output.
     */
    public function __construct(
        private readonly string $service,
        $stream = null,
        private ?string $correlationId = null,
    ) {
        $this->stream = $stream ?? fopen('php://stderr', 'wb');
    }

    public function withCorrelationId(string $correlationId): self
    {
        $clone = clone $this;
        $clone->correlationId = $correlationId;
        return $clone;
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $context[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }
        return $context;
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $record = [
            'ts' => gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) (fmod(microtime(true), 1) * 1000)),
            'level' => $level,
            'service' => $this->service,
            'msg' => $message,
        ];
        if ($this->correlationId !== null) {
            $record['correlation_id'] = $this->correlationId;
        }
        if ($context !== []) {
            $record['ctx'] = self::redact($context);
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        fwrite($this->stream, $line . "\n");
    }
}
