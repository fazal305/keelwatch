<?php

declare(strict_types=1);

/**
 * What a per-request database connection costs, and the MySQL settings that
 * bound write latency. PHP (built-in server or FPM) opens one connection per
 * request, lazily, and shares it within the request; there is no pool.
 *
 *   php bench/connect.php
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
$config = $base->withDatabase($testDb);

$times = [];
for ($i = 0; $i < 50; $i++) {
    $t = hrtime(true);
    $pdo = Connection::open($config);
    $pdo->query('SELECT 1')->fetchColumn();
    $times[] = (hrtime(true) - $t) / 1e6;
    $pdo = null;
}
sort($times);
printf("Connect + first query (50x): p50 %.2f ms  p95 %.2f ms  max %.2f ms\n", $times[24], $times[47], $times[49]);

$pdo = Connection::open($config);
$vars = $pdo->query("SHOW VARIABLES WHERE Variable_name IN
    ('version', 'innodb_flush_log_at_trx_commit', 'sync_binlog', 'log_bin', 'max_connections', 'innodb_buffer_pool_size')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($vars as $name => $value) {
    printf("%-32s %s\n", $name, $value);
}
