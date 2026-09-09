<?php

declare(strict_types=1);

namespace App\Tests\Http\Middleware;

use App\Http\Handler\RequestHandler;
use App\Http\Headers\Headers;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Protocol\HttpMethod;
use App\Http\Protocol\HttpVersion;
use App\Http\Protocol\MalformedRequestException;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;
use App\Http\Response\ResponseFactory;
use App\Router\MethodNotAllowedException;
use App\Router\RouteNotFoundException;
use PHPUnit\Framework\TestCase;

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

    public function testMapsUnknownExceptionTo500(): void
    {
        $response = $this->handle(static function (HttpRequest $r): HttpResponse {
            throw new \RuntimeException('database exploded');
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
                throw new \Error('fatal');
            }
        };

        $response = $middleware->process($request, $failingNext);

        $this->assertSame(500, $response->statusCode());
    }

    private function handle(\Closure $handler): HttpResponse
    {
        $middleware = new ErrorHandlerMiddleware();
        $next = new class($handler) implements RequestHandler {
            public function __construct(private \Closure $handler)
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