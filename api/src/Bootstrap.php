<?php

declare(strict_types=1);

namespace Keelwatch;

use ErrorException;
use Keelwatch\Database\Connection;
use Keelwatch\Health\HealthService;
use Keelwatch\Support\Env;
use Keelwatch\Support\Logger;

final class Bootstrap
{
    public const ROOT = __DIR__ . '/../..';
    public const MIGRATIONS = self::ROOT . '/db/migrations';

    public static function convertErrorsToExceptions(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /**
     * @throws ConfigException
     */
    public static function config(): Config
    {
        return Config::fromEnv(Env::load(self::ROOT . '/.env'));
    }

    public static function app(Config $config, Logger $logger): App
    {
        $health = new HealthService(
            $config,
            static fn () => Connection::open($config),
            self::MIGRATIONS,
            $logger,
        );
        return new App($config, $health, $logger);
    }
}
