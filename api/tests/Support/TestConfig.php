<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Support;

use Keelwatch\Config;

final class TestConfig
{
    /**
     * A syntactically valid config. The password is a throwaway literal used
     * only to prove it never appears in error output.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    public static function env(array $overrides = []): array
    {
        return array_merge([
            'APP_ENV' => 'test',
            'APP_VERSION' => '9.9.9',
            'API_CORS_ORIGINS' => '',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_NAME' => 'keelwatch_unit',
            'DB_USER' => 'unit_user',
            'DB_PASSWORD' => 'not-a-real-password-7f3a',
            'WORKER_STALE_AFTER_S' => '35',
        ], $overrides);
    }

    /**
     * @param array<string, string> $overrides
     */
    public static function make(array $overrides = []): Config
    {
        return Config::fromEnv(self::env($overrides));
    }
}
