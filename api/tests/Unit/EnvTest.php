<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use Keelwatch\Support\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testParsesPlainQuotedAndExportedValues(): void
    {
        $env = Env::parse(<<<'ENV'
            # comment line
            PLAIN=value
            export EXPORTED=yes
            DOUBLE="has spaces\tand tab"
            SINGLE='raw $value\n'
            INLINE=abc # trailing comment
            EMPTY=
            ENV);

        self::assertSame('value', $env['PLAIN']);
        self::assertSame('yes', $env['EXPORTED']);
        self::assertSame("has spaces\tand tab", $env['DOUBLE']);
        self::assertSame('raw $value\n', $env['SINGLE']);
        self::assertSame('abc', $env['INLINE']);
        self::assertSame('', $env['EMPTY']);
    }

    public function testIgnoresMalformedLinesAndInvalidKeys(): void
    {
        $env = Env::parse("NOEQUALS\n1BAD=x\nBAD-KEY=y\nGOOD=z\r\n");

        self::assertSame(['GOOD' => 'z'], $env);
    }

    public function testMissingFileYieldsOnlyProcessEnvironment(): void
    {
        $env = Env::load(__DIR__ . '/does-not-exist.env');

        self::assertArrayNotHasKey('PLAIN', $env);
    }
}
