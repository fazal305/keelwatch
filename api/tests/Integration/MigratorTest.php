<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Integration;

use Keelwatch\Bootstrap;
use Keelwatch\Database\Migrator;
use RuntimeException;

final class MigratorTest extends DatabaseTestCase
{
    public function testAppliesPendingMigrationsOnceAndInOrder(): void
    {
        $migrator = new Migrator($this->pdo, Bootstrap::MIGRATIONS);

        self::assertSame(['0001_worker_heartbeats'], $migrator->pending());
        self::assertSame(['0001_worker_heartbeats'], $migrator->migrate());
        self::assertSame([], $migrator->pending());
        self::assertSame([], $migrator->migrate(), 'second run must be a no-op');

        $columns = $this->pdo->query('SHOW COLUMNS FROM worker_heartbeats')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('last_seen_at', $columns);
    }

    public function testPendingIsReadOnlyOnAFreshDatabase(): void
    {
        $migrator = new Migrator($this->pdo, Bootstrap::MIGRATIONS);
        $migrator->pending();

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables, 'pending() must not create schema_migrations');
    }

    public function testRefusesAMigrationEditedAfterItWasApplied(): void
    {
        $dir = sys_get_temp_dir() . '/keelwatch-migrations-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/0001_probe.sql';

        try {
            file_put_contents($file, "CREATE TABLE it_probe (id INT PRIMARY KEY);\n");
            (new Migrator($this->pdo, $dir))->migrate();

            file_put_contents($file, "CREATE TABLE it_probe (id BIGINT PRIMARY KEY);\n");
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('0001_probe was modified after being applied');
            (new Migrator($this->pdo, $dir))->migrate();
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
}
