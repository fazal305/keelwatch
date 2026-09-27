<?php

declare(strict_types=1);

namespace Keelwatch\Database;

use Keelwatch\Config;
use PDO;

final class Connection
{
    public static function open(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->dbHost,
            $config->dbPort,
            $config->dbName,
        );

        $pdo = new PDO($dsn, $config->dbUser, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => $config->dbConnectTimeoutS,
        ]);

        // All timestamps are stored and compared in UTC, by every service.
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}
