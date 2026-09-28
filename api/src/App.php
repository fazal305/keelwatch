<?php

declare(strict_types=1);

namespace Keelwatch;

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
 * HTTP kernel: correlation IDs, CORS, routing, error containment and
 * access logging. Kept free of globals so tests can drive it directly.
 */
final class App
{
    private readonly Router $router;

    /** Set per request so route handlers can log with the request's correlation ID. */
    private string $correlationId = '';
    private Logger $requestLogger;

    public function __construct(
        private readonly Config $config,
        private readonly HealthService $health,
        private readonly Logger $logger,
        private readonly ?WebhookHandler $webhooks = null,
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

        try {
            $response = $cors->isPreflight($request)
                ? $cors->preflight($request)
                : $cors->apply($request, $this->router->dispatch($request));
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
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 2),
        ]);

        return $response;
    }

    private function registerRoutes(): void
    {
        $this->router->get('/healthz', static fn (): Response => Response::json(['status' => 'ok']));

        $this->router->get('/readyz', function (): Response {
            $readiness = $this->health->readiness();
            return Response::json(
                ['status' => $readiness['ready'] ? 'ready' : 'not_ready', 'checks' => $readiness['checks']],
                $readiness['ready'] ? 200 : 503,
            );
        });

        $this->router->get('/api/system/health', fn (): Response => Response::json($this->health->report()));

        if ($this->webhooks !== null) {
            $this->router->post(
                '/webhooks/github',
                fn (Request $request): Response => $this->webhooks->handle($request, $this->correlationId, $this->requestLogger),
            );
        }
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
