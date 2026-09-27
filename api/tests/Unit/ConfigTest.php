<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use Keelwatch\Config;
use Keelwatch\ConfigException;
use Keelwatch\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testBuildsTypedConfigWithDefaults(): void
    {
        $config = TestConfig::make(['DB_PORT' => '', 'DB_CONNECT_TIMEOUT_S' => '']);

        self::assertSame('test', $config->appEnv);
        self::assertSame(3306, $config->dbPort);
        self::assertSame(2, $config->dbConnectTimeoutS);
        self::assertSame(35, $config->workerStaleAfterS);
        self::assertSame([], $config->corsOrigins);
    }

    public function testReportsEveryProblemAtOnce(): void
    {
        try {
            Config::fromEnv(TestConfig::env([
                'APP_ENV' => 'staging',
                'DB_HOST' => '',
                'DB_PASSWORD' => '',
                'DB_PORT' => '99999',
            ]));
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertCount(4, $e->errors);
            self::assertStringContainsString('APP_ENV', $e->getMessage());
            self::assertStringContainsString('DB_HOST is required', $e->getMessage());
            self::assertStringContainsString('DB_PASSWORD is required', $e->getMessage());
            self::assertStringContainsString('DB_PORT', $e->getMessage());
        }
    }

    public function testErrorMessagesNeverContainValues(): void
    {
        try {
            Config::fromEnv(TestConfig::env(['DB_PORT' => 'not-a-real-password-7f3a', 'DB_NAME' => '']));
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString('not-a-real-password-7f3a', $e->getMessage());
        }
    }

    public function testParsesCorsAllowlistAndRejectsNonOrigins(): void
    {
        $config = TestConfig::make(['API_CORS_ORIGINS' => 'https://app.example.com/, http://localhost:5173']);
        self::assertSame(['https://app.example.com', 'http://localhost:5173'], $config->corsOrigins);

        $this->expectException(ConfigException::class);
        TestConfig::make(['API_CORS_ORIGINS' => 'https://app.example.com/path']);
    }

    public function testWithDatabaseOnlyChangesTheName(): void
    {
        $config = TestConfig::make();
        $test = $config->withDatabase('keelwatch_test');

        self::assertSame('keelwatch_test', $test->dbName);
        self::assertSame($config->dbUser, $test->dbUser);
        self::assertSame('keelwatch_unit', $config->dbName);
    }
}
