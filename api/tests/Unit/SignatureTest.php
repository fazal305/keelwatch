<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use Keelwatch\Webhook\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private string $secret;
    private string $previous;

    protected function setUp(): void
    {
        // Generated per run: no secret literal lives in the repository.
        $this->secret = bin2hex(random_bytes(32));
        $this->previous = bin2hex(random_bytes(32));
    }

    public function testMatchesGithubsDocumentedExample(): void
    {
        // Example from GitHub's "Validating webhook deliveries" documentation.
        self::assertSame(
            'sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17',
            Signature::sign('Hello, World!', "It's a Secret to Everybody"),
        );
    }

    public function testAcceptsTheExactBody(): void
    {
        $body = '{"zen":"Keep it logically awesome."}';
        self::assertTrue(Signature::verify($body, Signature::sign($body, $this->secret), [$this->secret]));
    }

    public function testRejectsAnyChangeToTheBody(): void
    {
        $body = '{"a":1}';
        $header = Signature::sign($body, $this->secret);

        self::assertFalse(Signature::verify('{"a":2}', $header, [$this->secret]));
        self::assertFalse(Signature::verify('{"a": 1}', $header, [$this->secret]), 're-encoded JSON must not verify');
        self::assertFalse(Signature::verify($body . "\n", $header, [$this->secret]));
    }

    public function testRejectsWrongSecretMissingAndMalformedHeaders(): void
    {
        $body = '{}';
        $good = Signature::sign($body, $this->secret);

        self::assertFalse(Signature::verify($body, Signature::sign($body, 'another-secret-value-xx'), [$this->secret]));
        self::assertFalse(Signature::verify($body, null, [$this->secret]));
        self::assertFalse(Signature::verify($body, '', [$this->secret]));
        self::assertFalse(Signature::verify($body, substr($good, 7), [$this->secret]), 'missing sha256= prefix');
        self::assertFalse(Signature::verify($body, 'sha1=' . substr($good, 7), [$this->secret]));
        self::assertFalse(Signature::verify($body, strtoupper($good), [$this->secret]));
        self::assertFalse(Signature::verify($body, substr($good, 0, -1), [$this->secret]), 'truncated');
        self::assertFalse(Signature::verify($body, $good . ' ', [$this->secret]));
        self::assertFalse(Signature::verify($body, $good, []), 'no configured secret');
    }

    public function testAcceptsThePreviousSecretDuringRotation(): void
    {
        $body = '{"rotation":true}';

        self::assertTrue(Signature::verify($body, Signature::sign($body, $this->previous), [$this->secret, $this->previous]));
        self::assertFalse(Signature::verify($body, Signature::sign($body, $this->previous), [$this->secret]));
    }
}
