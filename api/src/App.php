<?php

declare(strict_types=1);

namespace Keelwatch;

use Keelwatch\Auth\AuthContext;
use Keelwatch\Auth\AuthService;
use Keelwatch\Auth\PasswordPolicyError;
use Keelwatch\Dashboard\DashboardRoutes;
use Keelwatch\Dashboard\IntegrationRoutes;
use Keelwatch\Health\HealthService;
use Keelwatch\Http\Cors;
use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Http\Router;
use Keelwatch\Support\CorrelationId;
use Keelwatch\Support\Logger;
use Keelwatch\Webhook\WebhookHandler;
use Throwable;

/**
 * HTTP kernel: correlation IDs, CORS, routing, access control, error
 * containment and access logging. Kept free of globals so tests can drive it.
 *
 * Access rules are declared per route (Router::PUBLIC/VIEWER/ADMIN) and
 * enforced here, in one place:
 *   - viewer/admin routes need a valid session cookie (401), and admin routes
 *     an admin (403);
 *   - unsafe methods on session routes need the session's CSRF token in
 *     X-CSRF-Token (403);
 *   - unsafe methods from a browser must come from an allowed Origin (403).
 */
final class App
{
    public const CSRF_HEADER = 'x-csrf-token';

    private readonly Router $router;

    /** Set per request so route handlers can log with the request's correlation ID. */
    private string $correlationId = '';
    private Logger $requestLogger;

    public function __construct(
        private readonly Config $config,
        private readonly HealthService $health,
        private readonly Logger $logger,
        private readonly ?WebhookHandler $webhooks = null,
        private readonly ?AuthService $auth = null,
        private readonly ?DashboardRoutes $dashboard = null,
        private readonly ?IntegrationRoutes $integrations = null,
    ) {
        $this->requestLogger = $logger;
        $this->router = new Router();
        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        $correlationId = CorrelationId::resolve($request->header(CorrelationId::HEADER));
        $logger = $this->logger->withCorrelationId($correlationId);
        $this->correlationId = $correlationId;
        $this->requestLogger = $logger;
        $cors = new Cors($this->config->corsOrigins);
        $userId = null;

        try {
            if ($cors->isPreflight($request)) {
                $response = $cors->preflight($request);
            } else {
                [$response, $userId] = $this->route($request);
                $response = $cors->apply($request, $response);
            }
        } catch (Throwable $e) {
            $logger->error('unhandled exception', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            $response = $cors->apply(
                $request,
                Response::error(500, 'internal_error', 'Something went wrong on our side.', $correlationId),
            );
        }

        $response = self::withSecurityHeaders($response)->withHeader('X-Request-Id', $correlationId);

        $logger->info('request', [
            'method' => $request->method,
            'path' => $request->path,
            'status' => $response->status,
            'user_id' => $userId,
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 2),
        ]);

        return $response;
    }

    /**
     * @return array{0: Response, 1: ?int}
     */
    private function route(Request $request): array
    {
        $match = $this->router->match($request);
        if ($match instanceof Response) {
            return [$match, null];
        }

        if ($request->isUnsafeMethod() && $request->path !== '/webhooks/github' && !$this->originAllowed($request)) {
            return [Response::error(403, 'bad_origin', 'Request origin is not allowed.'), null];
        }

        $auth = null;
        if ($match['access'] !== Router::PUBLIC) {
            if ($this->auth === null) {
                return [Response::error(503, 'auth_unavailable', 'Sign-in is not configured.'), null];
            }
            $auth = $this->auth->authenticate($request->cookie(Response::sessionCookieName($this->config->sessionCookieSecure)));
            if ($auth === null) {
                return [Response::error(401, 'unauthenticated', 'Please sign in.'), null];
            }
            if ($match['access'] === Router::ADMIN && !$auth->isAdmin()) {
                return [Response::error(403, 'forbidden', 'Only administrators can do this.'), $auth->userId];
            }
            if ($request->isUnsafeMethod() && !$this->auth->validCsrf($auth, $request->header(self::CSRF_HEADER))) {
                return [Response::error(403, 'csrf_failed', 'Your session token is missing or stale. Reload and try again.'), $auth->userId];
            }
        }

        return [($match['handler'])($request, $match['params'], $auth), $auth?->userId];
    }

    /**
     * Browsers send Origin on every cross-site and same-origin POST. It must be
     * the API's own origin or a configured dashboard origin. Requests without
     * Origin (curl, server-to-server) are not browser CSRF and pass.
     */
    private function originAllowed(Request $request): bool
    {
        $origin = $request->header('origin');
        if ($origin === null) {
            return true;
        }
        if (in_array(rtrim($origin, '/'), $this->config->corsOrigins, true)) {
            return true;
        }
        $host = $request->header('host');
        return $host !== null && in_array($origin, ["http://{$host}", "https://{$host}"], true);
    }

    private function registerRoutes(): void
    {
        // Liveness/readiness probes for load balancers: status words only.
        $this->router->get('/healthz', static fn (): Response => Response::json(['status' => 'ok']), Router::PUBLIC);

        $this->router->get('/readyz', function (): Response {
            $readiness = $this->health->readiness();
            return Response::json(
                ['status' => $readiness['ready'] ? 'ready' : 'not_ready', 'checks' => $readiness['checks']],
                $readiness['ready'] ? 200 : 503,
            );
        }, Router::PUBLIC);

        // Worker IDs and versions are operational detail: signed-in users only.
        $this->router->get('/api/system/health', fn (): Response => Response::json($this->health->report()), Router::VIEWER);

        if ($this->webhooks !== null) {
            // Authenticated by its HMAC signature, not a session.
            $this->router->post(
                '/webhooks/github',
                fn (Request $request): Response => $this->webhooks->handle($request, $this->correlationId, $this->requestLogger),
                Router::PUBLIC,
            );
        }

        if ($this->auth !== null) {
            $this->registerAuthRoutes($this->auth);
        }
        $this->dashboard?->register($this->router);
        $this->integrations?->register($this->router);
    }

    private function registerAuthRoutes(AuthService $auth): void
    {
        $secure = $this->config->sessionCookieSecure;

        $this->router->post('/api/auth/login', function (Request $request) use ($auth, $secure): Response {
            $body = $request->json(4096) ?? [];
            $username = $body['username'] ?? null;
            $password = $body['password'] ?? null;
            if (!is_string($username) || !is_string($password) || $username === '' || $password === '') {
                return Response::json(['error' => [
                    'code' => 'validation_failed',
                    'message' => 'Enter your username and password.',
                    'fields' => array_filter([
                        'username' => !is_string($username) || $username === '' ? 'Enter your username.' : null,
                        'password' => !is_string($password) || $password === '' ? 'Enter your password.' : null,
                    ]),
                ]], 422);
            }
            $result = $auth->login($username, $password, $request->clientIp);
            if ($result->outcome === 'throttled') {
                $this->requestLogger->warning('login throttled', ['client_ip' => $request->clientIp]);
                return Response::error(429, 'too_many_attempts', 'Too many sign-in attempts. Try again later.')
                    ->withHeader('Retry-After', (string) $result->retryAfterSeconds);
            }
            if ($result->outcome !== 'success') {
                $this->requestLogger->warning('login failed', ['client_ip' => $request->clientIp]);
                // One message for unknown user and wrong password: no account enumeration.
                return Response::error(401, 'invalid_credentials', 'Incorrect username or password.');
            }
            $this->requestLogger->info('login succeeded', ['user_id' => $result->auth->userId]);
            return Response::json(['user' => $result->auth->publicUser(), 'csrf_token' => $result->auth->csrfToken])
                ->withSessionCookie($result->token, $auth->sessionMaxAgeSeconds(), $secure);
        }, Router::PUBLIC);

        $this->router->get('/api/auth/session', static fn (Request $r, array $p, AuthContext $a): Response => Response::json(
            ['user' => $a->publicUser(), 'csrf_token' => $a->csrfToken],
        ), Router::VIEWER);

        $this->router->post('/api/auth/logout', function (Request $r, array $p, AuthContext $a) use ($auth, $secure): Response {
            $auth->logout($a);
            return Response::noContent()->withSessionCookie('', 0, $secure);
        }, Router::VIEWER);

        $this->router->post('/api/auth/password', function (Request $request, array $p, AuthContext $a) use ($auth, $secure): Response {
            $body = $request->json(4096) ?? [];
            $current = $body['current_password'] ?? null;
            $new = $body['new_password'] ?? null;
            if (!is_string($current) || !is_string($new)) {
                return Response::json(['error' => ['code' => 'validation_failed', 'message' => 'Both passwords are required.']], 422);
            }
            try {
                $token = $auth->changePassword($a, $current, $new);
            } catch (PasswordPolicyError $e) {
                return Response::json(['error' => [
                    'code' => 'validation_failed',
                    'message' => $e->getMessage(),
                    'fields' => ['new_password' => $e->getMessage()],
                ]], 422);
            }
            if ($token === null) {
                return Response::json(['error' => [
                    'code' => 'validation_failed',
                    'message' => 'Your current password is incorrect.',
                    'fields' => ['current_password' => 'Your current password is incorrect.'],
                ]], 422);
            }
            $fresh = $auth->authenticate($token);
            return Response::json(['user' => $fresh?->publicUser(), 'csrf_token' => $fresh?->csrfToken])
                ->withSessionCookie($token, $auth->sessionMaxAgeSeconds(), $secure);
        }, Router::VIEWER);
    }

    private static function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }
}
