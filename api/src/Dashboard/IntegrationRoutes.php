<?php

declare(strict_types=1);

namespace Keelwatch\Dashboard;

use Closure;
use Keelwatch\Auth\AuthContext;
use Keelwatch\Config;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Http\Router;
use Keelwatch\Notify\DestinationUrl;
use Keelwatch\Notify\InvalidDestination;
use Keelwatch\Notify\UrlCipher;
use Keelwatch\Support\Logger;
use PDO;

/**
 * Settings: notification destinations and per-repository analysis settings.
 *
 * Reads are open to every signed-in user (they reveal only hosts and labels);
 * every change is admin-only and logged with the acting user, never with a URL.
 * Nothing here sends a notification: delivery is the worker's job.
 */
final class IntegrationRoutes
{
    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
    private const LLM_POLICIES = ['none', 'public_only', 'allowed'];
    private const MAX_BODY = 8192;

    /**
     * @param Closure(): PDO $connect
     */
    public function __construct(
        private readonly Config $config,
        private readonly Closure $connect,
        private readonly Logger $logger,
    ) {
    }

    public function register(Router $router): void
    {
        $repo = fn (): IntegrationsRepository => new IntegrationsRepository(($this->connect)());

        $router->get('/api/destinations', fn (): Response => Response::json([
            'items' => $repo()->destinations(),
            'installations' => $repo()->installations(),
            'encryption_configured' => $this->config->notificationKey !== '',
        ]), Router::VIEWER);

        $router->post('/api/destinations', function (Request $r, array $p, AuthContext $a) use ($repo): Response {
            if ($this->config->notificationKey === '') {
                return self::notConfigured();
            }
            $body = $this->body($r, ['installation_id', 'kind', 'label', 'url', 'min_severity', 'enabled']);
            if ($body instanceof Response) {
                return $body;
            }
            $errors = [];
            $installationId = $body['installation_id'] ?? null;
            if (!is_int($installationId) || $installationId < 1) {
                $errors['installation_id'] = 'Choose a GitHub account.';
            } elseif (!$repo()->installationIsActive($installationId)) {
                $errors['installation_id'] = 'That GitHub account is not active.';
            }
            $kind = $body['kind'] ?? null;
            if (!in_array($kind, DestinationUrl::KINDS, true)) {
                $errors['kind'] = 'Choose Slack or Discord.';
            }
            $label = self::label($body['label'] ?? null, $errors);
            $minSeverity = $body['min_severity'] ?? 'high';
            if (!in_array($minSeverity, self::SEVERITIES, true)) {
                $errors['min_severity'] = 'Choose a severity.';
            }
            $enabled = $body['enabled'] ?? true;
            if (!is_bool($enabled)) {
                $errors['enabled'] = 'Must be true or false.';
            }
            $host = null;
            $url = $body['url'] ?? null;
            if (!is_string($url) || trim($url) === '') {
                $errors['url'] = 'Paste the webhook URL.';
            } elseif (!isset($errors['kind'])) {
                try {
                    $host = DestinationUrl::validate($kind, $url);
                } catch (InvalidDestination $e) {
                    $errors['url'] = $e->getMessage();
                }
            }
            if ($errors !== []) {
                return self::invalid($errors);
            }

            $id = $repo()->createDestination(
                $installationId,
                $kind,
                $label,
                (new UrlCipher($this->config->notificationKey))->seal(trim($url)),
                $host,
                $minSeverity,
                $enabled,
            );
            $this->logger->info('destination created', ['destination_id' => $id, 'kind' => $kind, 'host' => $host, 'user_id' => $a->userId]);
            return Response::json($repo()->destination($id), 201);
        }, Router::ADMIN);

        $router->patch('/api/destinations/{id}', function (Request $r, array $p, AuthContext $a) use ($repo): Response {
            $body = $this->body($r, ['label', 'url', 'min_severity', 'enabled']);
            if ($body instanceof Response) {
                return $body;
            }
            $current = $repo()->destination($p['id']);
            if ($current === null) {
                return Response::error(404, 'not_found', 'Destination not found.');
            }
            $errors = [];
            $changes = [];
            if (array_key_exists('label', $body)) {
                $changes['label'] = self::label($body['label'], $errors);
            }
            if (array_key_exists('min_severity', $body)) {
                in_array($body['min_severity'], self::SEVERITIES, true)
                    ? $changes['min_severity'] = $body['min_severity']
                    : $errors['min_severity'] = 'Choose a severity.';
            }
            if (array_key_exists('enabled', $body)) {
                is_bool($body['enabled'])
                    ? $changes['enabled'] = $body['enabled']
                    : $errors['enabled'] = 'Must be true or false.';
            }
            if (array_key_exists('url', $body)) {
                if ($this->config->notificationKey === '') {
                    return self::notConfigured();
                }
                if (!is_string($body['url']) || trim($body['url']) === '') {
                    $errors['url'] = 'Paste the webhook URL.';
                } else {
                    try {
                        // The kind is fixed at creation; a new URL must match it.
                        $changes['url_host'] = DestinationUrl::validate($current['kind'], $body['url']);
                        $changes['url_ciphertext'] = (new UrlCipher($this->config->notificationKey))->seal(trim($body['url']));
                    } catch (InvalidDestination $e) {
                        $errors['url'] = $e->getMessage();
                    }
                }
            }
            if ($errors !== []) {
                return self::invalid($errors);
            }
            $repo()->updateDestination($p['id'], $changes);
            // Field names only: the new URL itself is a credential.
            $fields = array_values(array_diff(array_keys($changes), ['url_ciphertext', 'url_host']));
            if (isset($changes['url_host'])) {
                $fields[] = 'url';
            }
            $this->logger->info('destination updated', ['destination_id' => $p['id'], 'fields' => $fields, 'user_id' => $a->userId]);
            return Response::json($repo()->destination($p['id']));
        }, Router::ADMIN);

        $router->delete('/api/destinations/{id}', function (Request $r, array $p, AuthContext $a) use ($repo): Response {
            if (!$repo()->deleteDestination($p['id'])) {
                return Response::error(404, 'not_found', 'Destination not found.');
            }
            $this->logger->info('destination deleted', ['destination_id' => $p['id'], 'user_id' => $a->userId]);
            return Response::noContent();
        }, Router::ADMIN);

        $router->patch('/api/repositories/{id}/settings', function (Request $r, array $p, AuthContext $a) use ($repo): Response {
            $body = $this->body($r, ['llm_policy', 'analysis_enabled']);
            if ($body instanceof Response) {
                return $body;
            }
            $errors = [];
            $changes = [];
            if (array_key_exists('llm_policy', $body)) {
                in_array($body['llm_policy'], self::LLM_POLICIES, true)
                    ? $changes['llm_policy'] = $body['llm_policy']
                    : $errors['llm_policy'] = 'Must be one of: ' . implode(', ', self::LLM_POLICIES) . '.';
            }
            if (array_key_exists('analysis_enabled', $body)) {
                is_bool($body['analysis_enabled'])
                    ? $changes['analysis_enabled'] = $body['analysis_enabled']
                    : $errors['analysis_enabled'] = 'Must be true or false.';
            }
            if ($errors !== []) {
                return self::invalid($errors);
            }
            if (!$repo()->updateRepositorySettings($p['id'], $changes)) {
                return Response::error(404, 'not_found', 'Repository not found.');
            }
            $this->logger->info('repository settings updated', ['repository_id' => $p['id'], 'changes' => $changes, 'user_id' => $a->userId]);
            return Response::json((new DashboardRepository(($this->connect)()))->repository($p['id']));
        }, Router::ADMIN);
    }

    /**
     * A JSON object with only the allowed keys; anything else is a 422, so a
     * misspelt field is reported instead of silently ignored.
     *
     * @param list<string> $allowed
     * @return array<string, mixed>|Response
     */
    private function body(Request $request, array $allowed): array|Response
    {
        $body = $request->json(self::MAX_BODY);
        if ($body === null) {
            return Response::error(400, 'invalid_json', 'Send a non-empty JSON object.');
        }
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown !== []) {
            return self::invalid(array_fill_keys(array_map('strval', $unknown), 'Unknown field.'));
        }
        return $body;
    }

    /**
     * @param array<string, string> $errors
     */
    private static function label(mixed $value, array &$errors): string
    {
        $label = is_string($value) ? trim($value) : '';
        if ($label === '' || mb_strlen($label) > 100 || preg_match('/[\x00-\x1F\x7F]/', $label)) {
            $errors['label'] = 'Use 1–100 characters, no line breaks.';
        }
        return $label;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function invalid(array $fields): Response
    {
        return Response::json(['error' => ['code' => 'validation_failed', 'message' => 'Some fields need attention.', 'fields' => $fields]], 422);
    }

    private static function notConfigured(): Response
    {
        return Response::error(503, 'notifications_not_configured', 'Set NOTIFICATION_KEY on the server before adding destinations.');
    }
}
