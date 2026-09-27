<?php

declare(strict_types=1);

use Keelwatch\Bootstrap;
use Keelwatch\Config;
use Keelwatch\ConfigException;
use Keelwatch\Database\Connection;
use Keelwatch\Database\Migrator;
use Keelwatch\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

Bootstrap::convertErrorsToExceptions();

$env = Env::load(Bootstrap::ROOT . '/.env');

try {
    $config = Config::fromEnv($env);
} catch (ConfigException $e) {
    fwrite(STDERR, "Configuration invalid:\n  - " . implode("\n  - ", $e->errors) . "\n");
    exit(1);
}

if (in_array('--test', $argv, true)) {
    $testName = trim($env['DB_TEST_NAME'] ?? '');
    if ($testName === '' || $testName === $config->dbName) {
        fwrite(STDERR, "DB_TEST_NAME must be set and differ from DB_NAME for --test\n");
        exit(1);
    }
    $config = $config->withDatabase($testName);
}

try {
    $applied = (new Migrator(Connection::open($config), Bootstrap::MIGRATIONS))->migrate();
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo $applied === []
    ? "Database '{$config->dbName}' is up to date.\n"
    : "Applied to '{$config->dbName}': " . implode(', ', $applied) . "\n";
