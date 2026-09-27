<?php

declare(strict_types=1);

namespace Keelwatch\Database;

use PDO;
use RuntimeException;

/**
 * Applies numbered .sql files from db/migrations in order.
 *
 * The API owns the schema; the Python worker only reads and writes rows.
 * MySQL commits DDL implicitly, so each file is recorded only after all of
 * its statements succeed. A checksum guards against editing an applied file.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<string> versions applied by this call
     */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = $this->appliedChecksums();
        $ran = [];

        foreach ($this->files() as $version => $path) {
            $sql = (string) file_get_contents($path);
            $checksum = hash('sha256', $sql);

            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $checksum)) {
                    throw new RuntimeException("Migration {$version} was modified after being applied. Add a new migration instead.");
                }
                continue;
            }

            foreach (self::splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (?, ?, UTC_TIMESTAMP(3))'
            );
            $insert->execute([$version, $checksum]);
            $ran[] = $version;
        }

        return $ran;
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        // Read-only: health checks call this, so it must never create tables.
        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
        )->fetchColumn();
        $applied = (int) $exists > 0 ? $this->appliedChecksums() : [];

        return array_values(array_diff(array_keys($this->files()), array_keys($applied)));
    }

    /**
     * Splits on semicolons at line ends. Migrations must not contain
     * semicolons inside string literals at the end of a line.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $withoutComments) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @return array<string, string> version => absolute path, sorted
     */
    private function files(): array
    {
        $files = [];
        foreach (glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [] as $path) {
            $name = basename($path, '.sql');
            if (!preg_match('/^\d{4}_[a-z0-9_]+$/', $name)) {
                throw new RuntimeException("Migration file name must look like 0001_description.sql: {$name}");
            }
            $files[$name] = $path;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME(3) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
        );
    }

    /**
     * @return array<string, string>
     */
    private function appliedChecksums(): array
    {
        $rows = $this->pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('strval', $rows);
    }
}
