<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\Http\Middleware;

use ArrayObject;
use PhpMiniHttpServer\Http\Handler\RequestHandler;
use PhpMiniHttpServer\Http\Headers\Headers;
use PhpMiniHttpServer\Http\Middleware\LoggingMiddleware;
use PhpMiniHttpServer\Http\Protocol\HttpMethod;
use PhpMiniHttpServer\Http\Protocol\HttpVersion;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PhpMiniHttpServer\Support\Logger;
use PHPUnit\Framework\TestCase;

final class LoggingMiddlewareTest extends TestCase
{
    public function testLogsRequestStartAndStatusThroughTheLogger(): void
    {
        /** @var ArrayObject<int, string> $captured */
        $captured = new ArrayObject();
        $logger = new class ($captured) implements Logger {
            /** @var ArrayObject<int, string> */
            private ArrayObject $captured;

            /**
             * @param ArrayObject<int, string> $captured
             */
            public function __construct(ArrayObject $captured)
            {
                $this->captured = $captured;
            }

            public function log(string $message): void
            {
                $this->captured->append($message);
            }
        };

        $middleware = new LoggingMiddleware($logger);
        $next = new class implements RequestHandler {
            public function handle(HttpRequest $request): HttpResponse
            {
                return ResponseFactory::text('ok');
            }
        };

        $request = new HttpRequest(
            method: HttpMethod::POST,
            target: '/users',
            version: HttpVersion::HTTP_1_1,
            headers: new Headers(),
            body: '',
        );

        $middleware->process($request, $next);

        $this->assertSame(['POST /users → start', 'POST /users → 200'], $captured->getArrayCopy());
    }
}
