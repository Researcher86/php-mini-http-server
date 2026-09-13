<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\Http\Middleware;

use PhpMiniHttpServer\Http\Handler\RequestHandler;
use PhpMiniHttpServer\Http\Headers\Headers;
use PhpMiniHttpServer\Http\Middleware\MiddlewareInterface;
use PhpMiniHttpServer\Http\Middleware\MiddlewarePipeline;
use PhpMiniHttpServer\Http\Protocol\HttpMethod;
use PhpMiniHttpServer\Http\Protocol\HttpVersion;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\HttpStatusCode;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PHPUnit\Framework\TestCase;

final class MiddlewarePipelineTest extends TestCase
{
    private MiddlewarePipeline $pipeline;

    private OrderLog $log;

    protected function setUp(): void
    {
        $this->log = new OrderLog();
        $this->pipeline = new MiddlewarePipeline($this->terminal());
    }

    public function testRequestFallsThroughMiddlewareToTheFinalHandler(): void
    {
        $this->pipeline->add($this->logger('first'));
        $this->pipeline->add($this->logger('second'));

        $response = $this->pipeline->handle($this->request('/'));

        $this->assertSame('terminal', $response->body);
        $this->assertSame(['first', 'second', 'second:out', 'first:out'], $this->log->entries);
    }

    public function testOnionOrderInAndOut(): void
    {
        $this->pipeline->add($this->logger('outer'));
        $this->pipeline->add($this->logger('inner'));

        $this->pipeline->handle($this->request('/'));

        // outer enters, inner enters, inner exits, outer exits
        $this->assertSame(['outer', 'inner', 'inner:out', 'outer:out'], $this->log->entries);
    }

    public function testMiddlewareCanShortCircuitBeforeTheHandler(): void
    {
        $this->pipeline->add(new class implements MiddlewareInterface {
            public function process(HttpRequest $request, RequestHandler $next): HttpResponse
            {
                return ResponseFactory::text('denied', HttpStatusCode::UNAUTHORIZED);
            }
        });

        $response = $this->pipeline->handle($this->request('/'));

        $this->assertSame(401, $response->statusCode());
        $this->assertSame('denied', $response->body);
    }

    public function testMiddlewareCanTransformTheResponse(): void
    {
        $this->pipeline->add(new class implements MiddlewareInterface {
            public function process(HttpRequest $request, RequestHandler $next): HttpResponse
            {
                $response = $next->handle($request);
                $response->headers->set('X-Powered-By', 'mini-http');

                return $response;
            }
        });

        $response = $this->pipeline->handle($this->request('/'));

        $this->assertSame('mini-http', $response->header('X-Powered-By'));
    }

    public function testMiddlewareSeesTheRequestPath(): void
    {
        $this->pipeline->add($this->pathRecorder());

        $this->pipeline->handle($this->request('/probe'));

        $this->assertSame(['/probe'], $this->log->entries);
    }

    public function testEmptyPipelineDelegatesStraightToTheFinalHandler(): void
    {
        $this->assertSame('terminal', $this->pipeline->handle($this->request('/'))->body);
    }

    public function testCountsRegisteredMiddleware(): void
    {
        $this->assertSame(0, $this->pipeline->count());
        $this->pipeline->add($this->logger('a'));
        $this->pipeline->add($this->logger('b'));
        $this->assertSame(2, $this->pipeline->count());
    }

    private function logger(string $name): MiddlewareInterface
    {
        $log = $this->log;

        return new class ($name, $log) implements MiddlewareInterface {
            public function __construct(private string $name, private OrderLog $log)
            {
            }

            public function process(HttpRequest $request, RequestHandler $next): HttpResponse
            {
                $this->log->entries[] = $this->name;
                $response = $next->handle($request);
                $this->log->entries[] = $this->name . ':out';

                return $response;
            }
        };
    }

    private function pathRecorder(): MiddlewareInterface
    {
        $log = $this->log;

        return new class ($log) implements MiddlewareInterface {
            public function __construct(private OrderLog $log)
            {
            }

            public function process(HttpRequest $request, RequestHandler $next): HttpResponse
            {
                $this->log->entries[] = $request->path();

                return $next->handle($request);
            }
        };
    }

    private function terminal(): RequestHandler
    {
        return new class implements RequestHandler {
            public function handle(HttpRequest $request): HttpResponse
            {
                return ResponseFactory::text('terminal');
            }
        };
    }

    private function request(string $path): HttpRequest
    {
        return new HttpRequest(
            method: HttpMethod::GET,
            target: $path,
            version: HttpVersion::HTTP_1_1,
            headers: new Headers(),
            body: '',
        );
    }
}

/**
 * Shared mutable log for the middleware-order tests.
 */
final class OrderLog
{
    /** @var list<string> */
    public array $entries = [];
}
