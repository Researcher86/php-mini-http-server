<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\Http\Middleware;

use Closure;
use Error;
use PhpMiniHttpServer\Http\Handler\RequestHandler;
use PhpMiniHttpServer\Http\Headers\Headers;
use PhpMiniHttpServer\Http\Middleware\ErrorHandlerMiddleware;
use PhpMiniHttpServer\Http\Protocol\BodyTooLargeException;
use PhpMiniHttpServer\Http\Protocol\HeaderTooLargeException;
use PhpMiniHttpServer\Http\Protocol\HttpMethod;
use PhpMiniHttpServer\Http\Protocol\HttpVersion;
use PhpMiniHttpServer\Http\Protocol\MalformedRequestException;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PhpMiniHttpServer\Router\MethodNotAllowedException;
use PhpMiniHttpServer\Router\RouteNotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorHandlerMiddlewareTest extends TestCase
{
    public function testPassesSuccessfulResponsesThrough(): void
    {
        $response = $this->handle(fn (HttpRequest $r): HttpResponse => ResponseFactory::text('ok'));

        $this->assertSame(200, $response->statusCode());
        $this->assertSame('ok', $response->body);
    }

    public function testMapsRouteNotFoundTo404(): void
    {
        $response = $this->handle(static function (HttpRequest $r): HttpResponse {
            throw new RouteNotFoundException('nope');
        });

        $this->assertSame(404, $response->statusCode());
        $this->assertSame("Not Found\n", $response->body);
    }

    public function testMapsMethodNotAllowedTo405WithAllowHeader(): void
    {
        $response = $this->handle(static function (HttpRequest $r): HttpResponse {
            throw new MethodNotAllowedException(['GET', 'HEAD']);
        });

        $this->assertSame(405, $response->statusCode());
        $this->assertSame('GET, HEAD', $response->header('Allow'));
    }

    public function testMapsMalformedRequestTo400(): void
    {
        $response = $this->handle(static function (HttpRequest $r): HttpResponse {
            throw new MalformedRequestException('bad bytes');
        });

        $this->assertSame(400, $response->statusCode());
    }

    public function testMapsHeaderTooLargeTo431(): void
    {
        $response = $this->handle(static function (HttpRequest $r): HttpResponse {
            throw new HeaderTooLargeException('too many headers');
        });

        $this->assertSame(431, $response->statusCode());
        $this->assertSame("Request Header Fields Too Large\n", $response->body);
    }

    public function testMapsBodyTooLargeTo413(): void
    {
        $response = $this->handle(static function (HttpRequest $r): HttpResponse {
            throw new BodyTooLargeException('too big');
        });

        $this->assertSame(413, $response->statusCode());
        $this->assertSame("Payload Too Large\n", $response->body);
    }

    public function testMapsUnknownExceptionTo500(): void
    {
        $response = $this->handle(static function (HttpRequest $r): HttpResponse {
            throw new RuntimeException('database exploded');
        });

        $this->assertSame(500, $response->statusCode());
        $this->assertSame("Internal Server Error\n", $response->body);
    }

    public function testDoesNotSwallowErrorsOutsideThePipelineCall(): void
    {
        $middleware = new ErrorHandlerMiddleware();
        $request = $this->request();
        $failingNext = new class implements RequestHandler {
            public function handle(HttpRequest $request): HttpResponse
            {
                throw new Error('fatal');
            }
        };

        $response = $middleware->process($request, $failingNext);

        $this->assertSame(500, $response->statusCode());
    }

    private function handle(Closure $handler): HttpResponse
    {
        $middleware = new ErrorHandlerMiddleware();
        $next = new class ($handler) implements RequestHandler {
            public function __construct(private Closure $handler)
            {
            }

            public function handle(HttpRequest $request): HttpResponse
            {
                return ($this->handler)($request);
            }
        };

        return $middleware->process($this->request(), $next);
    }

    private function request(): HttpRequest
    {
        return new HttpRequest(
            method: HttpMethod::GET,
            target: '/',
            version: HttpVersion::HTTP_1_1,
            headers: new Headers(),
            body: '',
        );
    }
}
