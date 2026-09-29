<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use Keelwatch\Auth\AuthService;
use PHPUnit\Framework\TestCase;

/**
 * The unknown-username path must cost what a real verify costs: exactly one
 * password_verify with the same Argon2id parameters as stored hashes.
 */
final class AuthTimingTest extends TestCase
{
    public function testTheDummyHashUsesTheCurrentArgon2idParameters(): void
    {
        self::assertStringStartsWith('$argon2id$', AuthService::DUMMY_HASH);
        // If PHP's defaults change, regenerate DUMMY_HASH with
        //   php -r "echo password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID);"
        self::assertFalse(password_needs_rehash(AuthService::DUMMY_HASH, PASSWORD_ARGON2ID));
        self::assertSame(
            password_get_info(password_hash('x', PASSWORD_ARGON2ID))['options'],
            password_get_info(AuthService::DUMMY_HASH)['options'],
        );
    }

    public function testTheDummyHashMatchesNothingObvious(): void
    {
        foreach (['', 'password', 'admin', 'x'] as $guess) {
            self::assertFalse(password_verify($guess, AuthService::DUMMY_HASH));
        }
    }
}
