<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Unit;

use Keelwatch\Http\Request;
use Keelwatch\Http\Response;
use Keelwatch\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testNumericPathParametersAreExtracted(): void
    {
        $router = new Router();
        $router->get('/api/runs/{id}/checkpoints/{phase_no}', static fn () => Response::noContent(), Router::VIEWER);

        $match = $router->match(new Request('GET', '/api/runs/42/checkpoints/7'));

        self::assertIsArray($match);
        self::assertSame(['id' => 42, 'phase_no' => 7], $match['params']);
        self::assertSame(Router::VIEWER, $match['access']);
    }

    public function testParametersOnlyMatchPositiveIntegers(): void
    {
        $router = new Router();
        $router->get('/api/runs/{id}', static fn () => Response::noContent());

        foreach (['/api/runs/abc', '/api/runs/0', '/api/runs/-1', '/api/runs/1.5', '/api/runs/1%20OR%201', '/api/runs/12345678901234567890'] as $path) {
            $result = $router->match(new Request('GET', $path));
            self::assertInstanceOf(Response::class, $result, $path);
            self::assertSame(404, $result->status, $path);
        }
    }

    public function testMethodMismatchIs405WithAllow(): void
    {
        $router = new Router();
        $router->get('/api/runs/{id}', static fn () => Response::noContent());
        $router->post('/api/runs/{id}', static fn () => Response::noContent());

        $result = $router->match(new Request('DELETE', '/api/runs/3'));

        self::assertSame(405, $result->status);
        self::assertSame('GET, POST', $result->headers['Allow']);
    }

    public function testCookieParsing(): void
    {
        $request = new Request('GET', '/', ['cookie' => 'a=1; kw_session=abc%3D; theme=dark']);

        self::assertSame('abc=', $request->cookie('kw_session'));
        self::assertSame('dark', $request->cookie('theme'));
        self::assertNull($request->cookie('missing'));
    }

    public function testSessionCookieAttributes(): void
    {
        $secure = Response::noContent()->withSessionCookie('tok', 3600, true)->headers['Set-Cookie'];
        $local = Response::noContent()->withSessionCookie('tok', 3600, false)->headers['Set-Cookie'];

        self::assertSame('__Host-kw_session=tok; Path=/; Max-Age=3600; HttpOnly; SameSite=Strict; Secure', $secure);
        self::assertSame('kw_session=tok; Path=/; Max-Age=3600; HttpOnly; SameSite=Strict', $local);
    }
}
