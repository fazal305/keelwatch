<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Integration;

use Keelwatch\Bootstrap;
use Keelwatch\Config;
use Keelwatch\Database\Connection;
use Keelwatch\Support\Env;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real MySQL database named by DB_TEST_NAME. The tests
 * drop and recreate tables there, so it must never be the main database.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Config $config;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $env = Env::load(Bootstrap::ROOT . '/.env');
        $testDb = trim($env['DB_TEST_NAME'] ?? '');
        self::assertNotSame('', $testDb, 'DB_TEST_NAME must be set for integration tests');

        $base = Config::fromEnv($env);
        self::assertNotSame($base->dbName, $testDb, 'DB_TEST_NAME must differ from DB_NAME');

        $this->config = $base->withDatabase($testDb);
        $this->pdo = Connection::open($this->config);
        $this->resetSchema();
    }

    protected function resetSchema(): void
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
