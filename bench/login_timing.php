<?php

declare(strict_types=1);

/**
 * Does login time reveal whether a username exists? Times failed sign-ins
 * for an existing user (wrong password) and for an unknown username, each
 * through a fresh kernel, as in production where every request is a new
 * PHP process. TEST database only.
 *
 *   php bench/login_timing.php
 */

require __DIR__ . '/../api/vendor/autoload.php';

use Keelwatch\Auth\AuthService;
use Keelwatch\Bootstrap;
use Keelwatch\Config;
use Keelwatch\Database\Connection;
use Keelwatch\Database\Migrator;
use Keelwatch\Http\Request;
use Keelwatch\Support\Env;
use Keelwatch\Support\Logger;

$env = Env::load(Bootstrap::ROOT . '/.env');
$testDb = trim($env['DB_TEST_NAME'] ?? '');
$base = Config::fromEnv($env);
if ($testDb === '' || $testDb === $base->dbName) {
    fwrite(STDERR, "DB_TEST_NAME must be set and differ from DB_NAME.\n");
    exit(2);
}
$config = $base->with(['dbName' => $testDb, 'sessionCookieSecure' => false]);
$pdo = Connection::open($config);
(new Migrator($pdo, Bootstrap::MIGRATIONS))->migrate();
$pdo->exec("DELETE FROM users WHERE username = 'timing-probe'");
AuthService::createUser($pdo, 'timing-probe', bin2hex(random_bytes(12)), 'viewer');

$attempt = static function (string $username) use ($config): float {
    $pdo = Connection::open($config);
    $pdo->exec('DELETE FROM login_failures'); // keep the throttle out of the measurement
    // A fresh kernel per attempt, like a new PHP process per request.
    $app = Bootstrap::app($config, new Logger('api', fopen('php://memory', 'wb')));
    $t = hrtime(true);
    $r = $app->handle(new Request('POST', '/api/auth/login', ['host' => 'k.test', 'origin' => 'http://k.test'], [], json_encode(['username' => $username, 'password' => 'definitely-wrong-password']), '198.51.100.77'));
    $ms = (hrtime(true) - $t) / 1e6;
    if ($r->status !== 401) {
        exit("unexpected status {$r->status}\n");
    }
    return $ms;
};

$known = $unknown = [];
for ($i = 0; $i < 30; $i++) {
    $known[] = $attempt('timing-probe');
    $unknown[] = $attempt('no-such-user-' . $i);
}
sort($known);
sort($unknown);
printf("wrong password, existing user   p50 %.1f ms\n", $known[15]);
printf("unknown username                p50 %.1f ms\n", $unknown[15]);
printf("ratio unknown/existing          %.2f\n", $unknown[15] / $known[15]);
$pdo->exec("DELETE FROM users WHERE username = 'timing-probe'");
