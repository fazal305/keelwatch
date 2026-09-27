<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use Keelwatch\Support\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    public function testRedactsSensitiveKeysRecursively(): void
    {
        $redacted = Logger::redact([
            'DB_PASSWORD' => 'hunter2',
            'headers' => [
                'Authorization' => 'Bearer abc',
                'X-Hub-Signature-256' => 'sha256=deadbeef',
                'content-type' => 'application/json',
            ],
            'github_token' => 'placeholder-value',
            'api_key' => 'k',
            'path' => '/healthz',
        ]);

        self::assertSame('[REDACTED]', $redacted['DB_PASSWORD']);
        self::assertSame('[REDACTED]', $redacted['headers']['Authorization']);
        self::assertSame('[REDACTED]', $redacted['headers']['X-Hub-Signature-256']);
        self::assertSame('application/json', $redacted['headers']['content-type']);
        self::assertSame('[REDACTED]', $redacted['github_token']);
        self::assertSame('[REDACTED]', $redacted['api_key']);
        self::assertSame('/healthz', $redacted['path']);
    }

    public function testWritesOneJsonLineWithCorrelationId(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $logger = (new Logger('api', $stream))->withCorrelationId('corr-12345678');

        $logger->info('hello', ['secret' => 'x', 'n' => 1]);

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        self::assertStringEndsWith("\n", $output);
        self::assertSame(1, substr_count($output, "\n"));

        $record = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('info', $record['level']);
        self::assertSame('api', $record['service']);
        self::assertSame('hello', $record['msg']);
        self::assertSame('corr-12345678', $record['correlation_id']);
        self::assertSame(['secret' => '[REDACTED]', 'n' => 1], $record['ctx']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $record['ts']);
        self::assertStringNotContainsString('"x"', $output);
    }
}
