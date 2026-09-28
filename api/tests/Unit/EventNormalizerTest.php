<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Keelwatch\Tests\Support\ContractValidator;
use Keelwatch\Webhook\EventNormalizer;
use Keelwatch\Webhook\NormalizationException;
use Keelwatch\Webhook\Normalized;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventNormalizerTest extends TestCase
{
    private const CORRELATION = 'corr-unit-00000001';

    private static function payload(string $name): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . "/../fixtures/github/{$name}.json"),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }

    private static function normalize(string $event, array $payload): Normalized
    {
        return (new EventNormalizer())->normalize(
            $event,
            'delivery-0001',
            $payload,
            self::CORRELATION,
            new DateTimeImmutable('2026-09-27T12:30:00', new DateTimeZone('UTC')),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function analysableEvents(): iterable
    {
        yield 'push' => ['push', 'push'];
        yield 'pull_request.opened' => ['pull_request', 'pull_request.opened'];
        yield 'pull_request.closed' => ['pull_request', 'pull_request.closed'];
    }

    #[DataProvider('analysableEvents')]
    public function testOutputSatisfiesTheContract(string $event, string $fixture): void
    {
        $result = self::normalize($event, self::payload($fixture));

        self::assertFalse($result->isIgnored());
        self::assertNull(ContractValidator::errors('github-event', $result->envelope), 'envelope violates contracts/github-event.v1.json');
    }

    #[DataProvider('analysableEvents')]
    public function testNoEmailOrFreeTextSurvives(string $event, string $fixture): void
    {
        $json = json_encode(self::normalize($event, self::payload($fixture))->envelope);

        self::assertStringNotContainsString('@', $json, 'no email address may reach the envelope');
        self::assertStringNotContainsString('Add login form', $json, 'commit messages and PR titles are not kept');
        self::assertStringNotContainsString('Contact me', $json, 'PR bodies are not kept');
    }

    public function testPushFieldsAndUtcConversion(): void
    {
        $envelope = self::normalize('push', self::payload('push'))->envelope;

        self::assertSame('push', $envelope['type']);
        self::assertNull($envelope['action']);
        // 17:05 at +05:00 is 12:05 UTC.
        self::assertSame('2026-09-27T12:05:00Z', $envelope['occurred_at']);
        self::assertSame(2, $envelope['push']['commit_count']);
        self::assertSame(['login' => 'octo-dev'], $envelope['actor']);
        self::assertSame(12345678, $envelope['installation']['github_id']);
    }

    public function testMergedPullRequest(): void
    {
        $pr = self::normalize('pull_request', self::payload('pull_request.closed'))->envelope['pull_request'];

        self::assertSame('closed', $pr['state']);
        self::assertTrue($pr['merged']);
        self::assertSame(42, $pr['number']);
    }

    public function testIgnoredCases(): void
    {
        self::assertSame('unsupported_action', self::normalize('pull_request', self::payload('pull_request.labeled'))->ignoreReason);
        self::assertSame('branch_deleted', self::normalize('push', self::payload('push.deleted'))->ignoreReason);
        self::assertSame('unsupported_event', self::normalize('issues', self::payload('issues.opened'))->ignoreReason);

        $noInstallation = self::payload('push');
        unset($noInstallation['installation']);
        self::assertSame('no_installation', self::normalize('push', $noInstallation)->ignoreReason);
    }

    /**
     * @return iterable<string, array{string, callable(array): array}>
     */
    public static function malformedPayloads(): iterable
    {
        yield 'uppercase sha' => ['push', static fn (array $p): array => ['after' => strtoupper($p['after'])] + $p];
        yield 'sha with newline' => ['push', static fn (array $p): array => ['after' => $p['after'] . "\n"] + $p];
        yield 'traversal in repo name' => ['push', static function (array $p): array {
            $p['repository']['full_name'] = '../../etc';
            return $p;
        }];
        yield 'string repository id' => ['push', static function (array $p): array {
            $p['repository']['id'] = '87654321';
            return $p;
        }];
        yield 'bad timestamp' => ['push', static function (array $p): array {
            $p['head_commit']['timestamp'] = 'yesterday-ish';
            return $p;
        }];
        yield 'unknown pr state' => ['pull_request', static function (array $p): array {
            $p['pull_request']['state'] = 'merged';
            return $p;
        }];
        yield 'email as sender login' => ['pull_request', static function (array $p): array {
            $p['sender']['login'] = 'octo@example.com';
            return $p;
        }];
    }

    #[DataProvider('malformedPayloads')]
    public function testMalformedSignedPayloadsAreRefused(string $event, callable $mutate): void
    {
        $fixture = $event === 'push' ? 'push' : 'pull_request.opened';

        $this->expectException(NormalizationException::class);
        self::normalize($event, $mutate(self::payload($fixture)));
    }
}
