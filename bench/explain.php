<?php

declare(strict_types=1);

/**
 * EXPLAIN ANALYZE a query against the TEST database (after bench/dashboard.php
 * has seeded it). Reads the SQL from a file so it can be copied verbatim from
 * the repository classes.
 *
 *   php bench/explain.php path/to/query.sql
 */

require __DIR__ . '/../api/vendor/autoload.php';

use Keelwatch\Bootstrap;
use Keelwatch\Config;
use Keelwatch\Database\Connection;
use Keelwatch\Support\Env;

$env = Env::load(Bootstrap::ROOT . '/.env');
$testDb = trim($env['DB_TEST_NAME'] ?? '');
$base = Config::fromEnv($env);
if ($testDb === '' || $testDb === $base->dbName) {
    fwrite(STDERR, "DB_TEST_NAME must be set and differ from DB_NAME.\n");
    exit(2);
}
$pdo = Connection::open($base->withDatabase($testDb));
$sql = (string) file_get_contents($argv[1] ?? 'php://stdin');
foreach ($pdo->query('EXPLAIN ANALYZE ' . $sql)->fetchAll(PDO::FETCH_COLUMN) as $plan) {
    echo $plan, "\n";
}
