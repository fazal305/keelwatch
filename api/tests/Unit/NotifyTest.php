<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use Keelwatch\Bootstrap;
use Keelwatch\Notify\DestinationUrl;
use Keelwatch\Notify\InvalidDestination;
use Keelwatch\Notify\UrlCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Parity with the worker: both implementations run against the same vectors
 * in contracts/test-vectors (see worker/tests/test_notify.py).
 */
final class NotifyTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function vector(string $name): array
    {
        return json_decode((string) file_get_contents(Bootstrap::ROOT . "/contracts/test-vectors/{$name}"), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testEncryptionMatchesThePublishedVector(): void
    {
        $v = self::vector('notification-url-encryption.v1.json');
        $cipher = new UrlCipher(base64_decode($v['key_base64'], true));
        $blob = (string) hex2bin($v['blob_hex']);

        self::assertSame($blob, $cipher->seal($v['plaintext'], (string) hex2bin($v['nonce_hex'])));
        self::assertSame($v['plaintext'], $cipher->open($blob));
    }

    public function testEveryEncryptionUsesAFreshNonce(): void
    {
        $cipher = new UrlCipher(random_bytes(32));
        $a = $cipher->seal('same text');
        $b = $cipher->seal('same text');
        self::assertNotSame($a, $b);
        self::assertSame("\x01", $a[0]);
        self::assertSame('same text', $cipher->open($b));
    }

    public function testTamperingAndWrongKeysAreDetected(): void
    {
        $cipher = new UrlCipher(random_bytes(32));
        $blob = $cipher->seal('secret url');
        $tampered = $blob;
        $tampered[20] = $tampered[20] === 'a' ? 'b' : 'a';

        foreach ([[$cipher, $tampered], [new UrlCipher(random_bytes(32)), $blob], [$cipher, "\x02" . substr($blob, 1)], [$cipher, 'short']] as [$c, $b]) {
            try {
                $c->open($b);
                self::fail('expected decryption to be refused');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testKeyMustBe32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UrlCipher(random_bytes(16));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function validUrls(): iterable
    {
        foreach (self::vector('notification-destination-urls.v1.json')['valid'] as $case) {
            yield trim($case['url']) => [$case['kind'], $case['url'], $case['host']];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidUrls(): iterable
    {
        $v = self::vector('notification-destination-urls.v1.json');
        foreach ($v['invalid'] as $case) {
            yield $case['why'] => [$case['kind'], $case['url']];
        }
        foreach ($v['invalid_generated'] as $case) {
            yield $case['why'] => [$case['kind'], $case['prefix'] . str_repeat($case['repeat'], $case['count'])];
        }
    }

    #[DataProvider('validUrls')]
    public function testSharedVectorValidUrls(string $kind, string $url, string $host): void
    {
        self::assertSame($host, DestinationUrl::validate($kind, $url));
    }

    #[DataProvider('invalidUrls')]
    public function testSharedVectorInvalidUrls(string $kind, string $url): void
    {
        $this->expectException(InvalidDestination::class);
        DestinationUrl::validate($kind, $url);
    }

    public function testErrorsNeverEchoTheUrl(): void
    {
        $url = 'https://hooks.slack.com/services/T0/B0/should-not-appear?x=1';
        try {
            DestinationUrl::validate('slack', $url);
            self::fail('expected rejection');
        } catch (InvalidDestination $e) {
            self::assertStringNotContainsString('should-not-appear', $e->getMessage());
        }
    }
}
