<?php

declare(strict_types=1);

/**
 * Sends a signed GitHub-style webhook to a local Keelwatch API.
 *
 *   php scripts/send-webhook.php push
 *   php scripts/send-webhook.php pull_request.opened --url=http://127.0.0.1:8080
 *   php scripts/send-webhook.php push --bad-signature
 *   php scripts/send-webhook.php push --repeat=200     (latency sample)
 *
 * The event name is the fixture name up to the first dot. Fixtures live in
 * api/tests/fixtures/github. The secret is read from the root .env and is
 * never printed. Only loopback URLs are allowed, so this can't be pointed
 * at someone else's server by accident.
 */

require __DIR__ . '/../api/vendor/autoload.php';

use Keelwatch\Support\Env;
use Keelwatch\Webhook\Signature;

$args = array_slice($argv, 1);
$fixture = $args[0] ?? '';
$options = [];
foreach (array_slice($args, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    }
}

$fixturePath = __DIR__ . "/../api/tests/fixtures/github/{$fixture}.json";
if (!preg_match('/^[a-z_.]+$/', $fixture) || !is_file($fixturePath)) {
    $available = array_map(static fn (string $p): string => basename($p, '.json'), glob(__DIR__ . '/../api/tests/fixtures/github/*.json') ?: []);
    fwrite(STDERR, "Usage: php scripts/send-webhook.php <fixture> [--url=...] [--bad-signature] [--repeat=N]\n");
    fwrite(STDERR, 'Fixtures: ' . implode(', ', $available) . "\n");
    exit(2);
}

$url = rtrim((string) ($options['url'] ?? 'http://127.0.0.1:8080'), '/') . '/webhooks/github';
$host = parse_url($url, PHP_URL_HOST);
if (!in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
    fwrite(STDERR, "Refusing to send to non-loopback host '{$host}'.\n");
    exit(2);
}

$secret = Env::load(__DIR__ . '/../.env')['GITHUB_WEBHOOK_SECRET'] ?? '';
if ($secret === '') {
    fwrite(STDERR, "GITHUB_WEBHOOK_SECRET is not set in .env\n");
    exit(2);
}

$event = explode('.', $fixture)[0];
$body = (string) file_get_contents($fixturePath);
$signature = isset($options['bad-signature'])
    ? Signature::sign($body, bin2hex(random_bytes(16)))
    : Signature::sign($body, $secret);
$repeat = max(1, (int) ($options['repeat'] ?? 1));

$durations = [];
$statuses = [];
$serverTotals = [];
for ($i = 0; $i < $repeat; $i++) {
    $deliveryId = sprintf('%s-%s', 'dev', bin2hex(random_bytes(8)));
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "X-GitHub-Event: {$event}",
            "X-GitHub-Delivery: {$deliveryId}",
            "X-Hub-Signature-256: {$signature}",
            'User-Agent: keelwatch-send-webhook',
        ],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    $started = hrtime(true);
    $responseBody = curl_exec($ch);
    $durations[] = (hrtime(true) - $started) / 1e6;
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
    if (preg_match('/total;dur=([\d.]+)/', $responseHeaders['server-timing'] ?? '', $m)) {
        $serverTotals[] = (float) $m[1];
    }

    if ($repeat === 1) {
        if ($responseBody === false) {
            fwrite(STDERR, 'Request failed: ' . curl_error($ch) . "\n");
            exit(1);
        }
        echo "HTTP {$status}  {$responseBody}\n";
        echo 'Server-Timing: ' . ($responseHeaders['server-timing'] ?? '-') . "\n";
        echo sprintf("Round trip: %.1f ms\n", $durations[0]);
    }
}

if ($repeat > 1) {
    $pct = static function (array $values, float $p): float {
        sort($values);
        return $values[(int) max(0, ceil($p / 100 * count($values)) - 1)];
    };
    echo "Requests: {$repeat}  Statuses: " . json_encode($statuses) . "\n";
    echo sprintf("Client round trip  p50 %.1f ms  p95 %.1f ms  max %.1f ms\n", $pct($durations, 50), $pct($durations, 95), max($durations));
    if ($serverTotals !== []) {
        echo sprintf("Server handler     p50 %.1f ms  p95 %.1f ms  max %.1f ms\n", $pct($serverTotals, 50), $pct($serverTotals, 95), max($serverTotals));
    }
}
