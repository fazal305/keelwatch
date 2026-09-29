<?php

declare(strict_types=1);

namespace Keelwatch;

use ErrorException;
use Keelwatch\Auth\AuthService;
use Keelwatch\Dashboard\DashboardRoutes;
use Keelwatch\Dashboard\IntegrationRoutes;
use Keelwatch\Database\Connection;
use Keelwatch\Health\HealthService;
use Keelwatch\Support\Env;
use Keelwatch\Support\Logger;
use Keelwatch\Webhook\WebhookHandler;

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
        // One connection per request, opened lazily and shared by every service.
        $pdo = null;
        $connect = static function () use (&$pdo, $config) {
            return $pdo ??= Connection::open($config);
        };
        $health = new HealthService($config, $connect, self::MIGRATIONS, $logger);
        return new App(
            $config,
            $health,
            $logger,
            new WebhookHandler($config, $connect),
            new AuthService($config, $connect),
            new DashboardRoutes($connect, $logger),
            new IntegrationRoutes($config, $connect, $logger),
        );
    }
}
