<?php

declare(strict_types=1);

namespace Keelwatch\Dashboard;

use Closure;
use Keelwatch\Auth\AuthContext;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Http\Router;
use Keelwatch\Support\Logger;
use PDO;

/**
 * The dashboard's JSON API. Query parameters are validated against fixed
 * allowlists; anything malformed is a 422 naming the parameter, never a
 * silently ignored or guessed value.
 */
final class DashboardRoutes
{
    private const MAX_LIMIT = 100;
    private const DEFAULT_LIMIT = 50;

    /**
     * @param Closure(): PDO $connect
     */
    public function __construct(
        private readonly Closure $connect,
        private readonly Logger $logger,
    ) {
    }

    public function register(Router $router): void
    {
        $repo = fn (): DashboardRepository => new DashboardRepository(($this->connect)());

        $router->get('/api/overview', fn (): Response => Response::json($repo()->overview()), Router::VIEWER);

        $router->get('/api/repositories', function (Request $r) use ($repo): Response {
            $q = $this->params($r, ['q' => 'text', 'state' => ['active', 'removed', 'all']]);
            return $q instanceof Response ? $q : Response::json(['items' => $repo()->repositories($q)]);
        }, Router::VIEWER);

        $router->get('/api/repositories/{id}', fn (Request $r, array $p): Response => self::found($repo()->repository($p['id']), 'Repository'), Router::VIEWER);

        $router->get('/api/events', function (Request $r) use ($repo): Response {
            $q = $this->params($r, [
                'status' => ['accepted', 'ignored'],
                'repository_id' => 'id',
                'event' => ['push', 'pull_request', 'installation', 'installation_repositories', 'ping'],
                'before' => 'id',
                'limit' => 'limit',
            ]);
            return $q instanceof Response ? $q : Response::json($repo()->events($q, $q['before'] ?? null, $q['limit'] ?? self::DEFAULT_LIMIT));
        }, Router::VIEWER);

        $router->get('/api/runs', function (Request $r) use ($repo): Response {
            $q = $this->params($r, [
                'status' => DashboardRepository::RUN_STATUSES,
                'repository_id' => 'id',
                'before' => 'id',
                'limit' => 'limit',
            ]);
            return $q instanceof Response ? $q : Response::json($repo()->runs($q, $q['before'] ?? null, $q['limit'] ?? self::DEFAULT_LIMIT));
        }, Router::VIEWER);

        $router->get('/api/runs/{id}', fn (Request $r, array $p): Response => self::found($repo()->run($p['id']), 'Run'), Router::VIEWER);

        $router->post('/api/runs/{id}/resume', function (Request $r, array $p, AuthContext $a) use ($repo): Response {
            try {
                $result = $repo()->resumeRun($p['id']);
            } catch (RunActionRefused $e) {
                return Response::error($e->reason === 'not_found' ? 404 : 409, $e->reason, $e->getMessage());
            }
            $this->logger->info('run resume requested', ['run_id' => $p['id'], 'user_id' => $a->userId]);
            return Response::json(['status' => 'queued'] + $result, 202);
        }, Router::ADMIN);

        $router->post('/api/runs/{id}/cancel', function (Request $r, array $p, AuthContext $a) use ($repo): Response {
            try {
                $status = $repo()->cancelRun($p['id']);
            } catch (RunActionRefused $e) {
                return Response::error($e->reason === 'not_found' ? 404 : 409, $e->reason, $e->getMessage());
            }
            $this->logger->info('run cancelled', ['run_id' => $p['id'], 'user_id' => $a->userId]);
            return Response::json(['status' => $status, 'run_id' => $p['id']]);
        }, Router::ADMIN);

        $router->get('/api/findings', function (Request $r) use ($repo): Response {
            $q = $this->params($r, [
                'severity' => DashboardRepository::SEVERITIES,
                'category' => ['security', 'logic', 'dependency', 'architecture', 'quality'],
                'source' => ['rule', 'llm', 'osv'],
                'repository_id' => 'id',
                'run_id' => 'id',
                'q' => 'text',
                'before' => 'id',
                'limit' => 'limit',
            ]);
            return $q instanceof Response ? $q : Response::json($repo()->findings($q, $q['before'] ?? null, $q['limit'] ?? self::DEFAULT_LIMIT));
        }, Router::VIEWER);

        $router->get('/api/findings/{id}', fn (Request $r, array $p): Response => self::found($repo()->finding($p['id']), 'Finding'), Router::VIEWER);

        $router->get('/api/digests', function (Request $r) use ($repo): Response {
            $q = $this->params($r, ['kind' => ['run', 'daily'], 'repository_id' => 'id', 'before' => 'id', 'limit' => 'limit']);
            return $q instanceof Response ? $q : Response::json($repo()->digests($q, $q['before'] ?? null, $q['limit'] ?? self::DEFAULT_LIMIT));
        }, Router::VIEWER);

        $router->get('/api/analytics', function (Request $r): Response {
            $q = $this->params($r, ['days' => array_map('strval', AnalyticsRepository::RANGES), 'repository_id' => 'id']);
            if ($q instanceof Response) {
                return $q;
            }
            $analytics = new AnalyticsRepository(($this->connect)());
            return self::found($analytics->summary((int) ($q['days'] ?? 30), $q['repository_id'] ?? null), 'Repository');
        }, Router::VIEWER);

        $router->get('/api/digests/{id}', fn (Request $r, array $p): Response => self::found($repo()->digest($p['id']), 'Digest'), Router::VIEWER);
    }

    /**
     * Validates query parameters against a spec: a list of allowed strings,
     * or 'id' (positive integer), 'limit' (1..100), 'text' (<=100 chars).
     * Unknown parameters are rejected so typos don't silently return everything.
     *
     * @param array<string, list<string>|string> $spec
     * @return array<string, int|string>|Response
     */
    private function params(Request $request, array $spec): array|Response
    {
        $out = [];
        $errors = [];
        foreach ($request->query as $name => $value) {
            if (!array_key_exists($name, $spec)) {
                $errors[$name] = 'Unknown parameter.';
                continue;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            $rule = $spec[$name];
            $error = null;
            if (is_array($rule)) {
                if (in_array($value, $rule, true)) {
                    $out[$name] = $value;
                } else {
                    $error = 'Must be one of: ' . implode(', ', $rule) . '.';
                }
            } elseif ($rule === 'id') {
                if (preg_match('/^[1-9][0-9]{0,18}$/D', $value)) {
                    $out[$name] = (int) $value;
                } else {
                    $error = 'Must be a positive integer.';
                }
            } elseif ($rule === 'limit') {
                if (ctype_digit($value) && (int) $value >= 1 && (int) $value <= self::MAX_LIMIT) {
                    $out[$name] = (int) $value;
                } else {
                    $error = 'Must be between 1 and ' . self::MAX_LIMIT . '.';
                }
            } elseif (mb_strlen($value) <= 100 && !preg_match('/[\x00-\x1F]/', $value)) {
                $out[$name] = trim($value);
            } else {
                $error = 'Must be at most 100 printable characters.';
            }
            if ($error !== null) {
                $errors[$name] = $error;
            }
        }
        if ($errors !== []) {
            return Response::json(['error' => ['code' => 'invalid_query', 'message' => 'Some filters are invalid.', 'fields' => $errors]], 422);
        }
        return $out;
    }

    /**
     * @param array<string, mixed>|null $item
     */
    private static function found(?array $item, string $what): Response
    {
        return $item === null ? Response::error(404, 'not_found', "{$what} not found.") : Response::json($item);
    }
}
