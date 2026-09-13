<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\EventLoop;

use PhpMiniHttpServer\EventLoop\SelectLoop;
use PhpMiniHttpServer\Http\Middleware\ErrorHandlerMiddleware;
use PhpMiniHttpServer\Http\Middleware\MiddlewarePipeline;
use PhpMiniHttpServer\Http\Protocol\HttpParser;
use PhpMiniHttpServer\Http\Protocol\ResponseEncoder;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PhpMiniHttpServer\Metrics\ServerMetrics;
use PhpMiniHttpServer\Router\Router;
use PhpMiniHttpServer\Server\ConnectionHandler;
use PhpMiniHttpServer\Server\Server;
use PhpMiniHttpServer\Server\ServerConfig;
use PhpMiniHttpServer\Support\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * ConnectionHandler needs real sockets to move bytes through, so these
 * tests drive the SelectLoop like ServerRoundTripTest does — but the point
 * of each test is a ConnectionHandler behaviour (backpressure, error
 * teardown), not the full request/response round-trip.
 */
final class ConnectionHandlerTest extends TestCase
{
    private Server $server;

    protected function setUp(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: 0));
        $this->server->start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testBackpressurePausesReadsThenResumesKeepingTheBatchIntact(): void
    {
        $router = new Router();
        $router->get('/big', static fn (): HttpResponse => ResponseFactory::text(str_repeat('y', 4096)));
        $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello'));

        $application = new MiddlewarePipeline($router);
        $application->add(new ErrorHandlerMiddleware());

        $loop = new SelectLoop();
        $parser = new HttpParser();
        $encoder = new ResponseEncoder();
        $metrics = $this->wireServer($loop, $parser, $application, $encoder, maxBufferedResponseBytes: 128);

        $client = stream_socket_client("tcp://127.0.0.1:{$this->server->getPort()}");
        $this->assertIsResource($client);
        stream_set_blocking($client, false);

        $received = '';
        $loop->onReadable($client, static function ($stream) use (&$received): void {
            $chunk = fread($stream, 8192);

            if ($chunk !== false && $chunk !== '') {
                $received .= $chunk;
            }
        });

        // Three pipelined requests in one write: the first response already
        // crosses the tiny 128-byte ceiling, so mid-batch the handler must
        // pause reads, drain, and resume — without losing requests 2 and 3
        // that were already buffered alongside request 1.
        $this->assertGreaterThan(0, fwrite(
            $client,
            "GET /big HTTP/1.1\r\nHost: t\r\n\r\n"
            . "GET /big HTTP/1.1\r\nHost: t\r\n\r\n"
            . "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n",
        ));

        $loop->addTimer(0.2, static fn () => $loop->stop());
        $loop->run();

        // All three responses arrive, the connection survives the pause in
        // keep-alive state, and the handler's reader is re-armed — the
        // backpressure pause cost the client nothing but wait time.
        $this->assertCount(4, explode('HTTP/1.1 200 OK', $received));
        $this->assertStringContainsString('Hello', $received);
        $this->assertStringContainsString(str_repeat('y', 4096), $received);
        $this->assertSame(3, $loop->watchedReadableCount());
        $this->assertSame(1, $this->server->connectionCount());
        $this->assertGreaterThan(0, $metrics->bytesWritten());

        fclose($client);
    }

    public function testMalformedRequestAnswers400ThenClosesTheConnection(): void
    {
        $router = new Router();
        $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello'));

        $application = new MiddlewarePipeline($router);
        $application->add(new ErrorHandlerMiddleware());

        $loop = new SelectLoop();
        $parser = new HttpParser();
        $encoder = new ResponseEncoder();
        $this->wireServer($loop, $parser, $application, $encoder);

        $client = stream_socket_client("tcp://127.0.0.1:{$this->server->getPort()}");
        $this->assertIsResource($client);
        stream_set_blocking($client, false);

        $received = '';
        $loop->onReadable($client, static function ($stream) use (&$received): void {
            $chunk = fread($stream, 8192);

            if ($chunk !== false && $chunk !== '') {
                $received .= $chunk;
            }
        });

        $this->assertGreaterThan(0, fwrite($client, "GARBAGE GARBAGE\r\n\r\n"));
        $loop->addTimer(0.2, static fn () => $loop->stop());
        $loop->run();

        // A 400 is answered, and unlike keep-alive responses the connection
        // is torn down once the error flushes.
        $this->assertStringContainsString('400 Bad Request', $received);

        fclose($client);

        for ($i = 0; $i < 100 && $this->server->connectionCount() > 0; $i++) {
            usleep(1000);
        }

        $this->assertSame(0, $this->server->connectionCount());
    }

    public function testClientThatVanishesMidWriteClosesOnlyItsOwnConnection(): void
    {
        $router = new Router();
        // Far past any socket send buffer, so the response cannot be handed
        // to the kernel in one go and the write really is still in flight
        // when the client disappears.
        $router->get('/big', static fn (): HttpResponse => ResponseFactory::text(str_repeat('z', 4_000_000)));
        $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello'));

        $application = new MiddlewarePipeline($router);
        $application->add(new ErrorHandlerMiddleware());

        $loop = new SelectLoop();
        $this->wireServer($loop, new HttpParser(), $application, new ResponseEncoder());

        $doomed = stream_socket_client("tcp://127.0.0.1:{$this->server->getPort()}");
        $this->assertIsResource($doomed);
        fwrite($doomed, "GET /big HTTP/1.1\r\nHost: t\r\n\r\n");

        // Close without reading a byte: the kernel answers the response
        // bytes already in flight with RST, so the server's next write fails
        // outright rather than merely blocking. A killed browser tab, a
        // client on a dropped mobile connection — the ordinary case.
        $loop->addTimer(0.05, static function () use ($doomed): void {
            fclose($doomed);
        });

        // A second, healthy client proves the loop kept running.
        $survivorSaw = '';
        $loop->addTimer(0.15, function () use ($loop, &$survivorSaw): void {
            // The failed write took its own connection with it, and only it.
            $this->assertSame(0, $this->server->connectionCount());

            $client = stream_socket_client("tcp://127.0.0.1:{$this->server->getPort()}");
            $this->assertIsResource($client);
            fwrite($client, "GET /hello HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
            stream_set_blocking($client, false);

            $loop->onReadable($client, static function ($stream) use (&$survivorSaw): void {
                $chunk = fread($stream, 8192);

                if ($chunk !== false) {
                    $survivorSaw .= $chunk;
                }
            });
        });

        $loop->addTimer(0.4, static fn () => $loop->stop());
        $loop->run();

        $this->assertStringContainsString('200 OK', $survivorSaw);
        $this->assertStringContainsString('Hello', $survivorSaw);
    }

    public function testHandlerThatSmugglesCrLfIntoAHeaderGets500NotADeadServer(): void
    {
        $router = new Router();
        $router->get('/evil', static function (): HttpResponse {
            $response = ResponseFactory::text('body');
            // Response splitting: a header value carrying CR/LF would end the
            // header block early and let the rest be read as a second,
            // attacker-chosen response. The encoder refuses to write it.
            $response->headers->set('X-Evil', "a\r\nInjected: yes");

            return $response;
        });
        $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello'));

        $application = new MiddlewarePipeline($router);
        $application->add(new ErrorHandlerMiddleware());

        $loop = new SelectLoop();
        $this->wireServer($loop, new HttpParser(), $application, new ResponseEncoder());

        $client = stream_socket_client("tcp://127.0.0.1:{$this->server->getPort()}");
        $this->assertIsResource($client);
        stream_set_blocking($client, false);

        $received = '';
        $loop->onReadable($client, static function ($stream) use (&$received): void {
            $chunk = fread($stream, 8192);

            if ($chunk !== false) {
                $received .= $chunk;
            }
        });

        // Keep-alive, and a healthy request behind the poisoned one: the
        // encoder failure must cost this request a 500 and nothing else.
        fwrite($client, "GET /evil HTTP/1.1\r\nHost: t\r\n\r\n");
        $loop->addTimer(0.15, static function () use ($client): void {
            fwrite($client, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        });

        $loop->addTimer(0.35, static fn () => $loop->stop());
        $loop->run();

        $this->assertStringContainsString('500 Internal Server Error', $received);
        $this->assertStringNotContainsString('Injected: yes', $received);
        $this->assertStringContainsString('Hello', $received);

        fclose($client);
    }

    public function testMetricsCountRequestsAndBytesOfARealExchange(): void
    {
        $router = new Router();
        $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello'));

        $application = new MiddlewarePipeline($router);
        $application->add(new ErrorHandlerMiddleware());

        $loop = new SelectLoop();
        $metrics = $this->wireServer($loop, new HttpParser(), $application, new ResponseEncoder());

        $client = stream_socket_client("tcp://127.0.0.1:{$this->server->getPort()}");
        $this->assertIsResource($client);
        stream_set_blocking($client, false);

        $loop->onReadable($client, static function ($stream): void {
            fread($stream, 8192);
        });

        $request = "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n";
        fwrite($client, $request . $request);

        $loop->addTimer(0.2, static fn () => $loop->stop());
        $loop->run();

        // Phase 20: the counters behind GET /metrics are fed by the handler
        // at the edges — one tick per request served, and the raw byte counts
        // of what actually crossed the socket in each direction.
        $this->assertSame(2, $metrics->totalRequests());
        $this->assertSame(2 * strlen($request), $metrics->bytesRead());
        $this->assertGreaterThan(2 * strlen('Hello'), $metrics->bytesWritten());
        $this->assertGreaterThan(0.0, $metrics->requestsPerSecond());

        fclose($client);
    }

    /**
     * Register the accept path: each accepted connection gets a
     * ConnectionHandler wired to the shared parser/pipeline/encoder.
     *
     * @return ServerMetrics the metrics collector fed by the handler
     */
    private function wireServer(
        SelectLoop $loop,
        HttpParser $parser,
        MiddlewarePipeline $application,
        ResponseEncoder $encoder,
        int $maxBufferedResponseBytes = ConnectionHandler::MAX_BUFFERED_RESPONSE_BYTES,
    ): ServerMetrics {
        $server = $this->server;
        $metrics = new ServerMetrics();

        $loop->onReadable($server->socket(), static function ($stream) use ($loop, $server, $parser, $application, $encoder, $metrics, $maxBufferedResponseBytes): void {
            $connection = $server->accept();

            if ($connection === null) {
                return;
            }

            (new ConnectionHandler(
                loop: $loop,
                server: $server,
                connection: $connection,
                parser: $parser,
                application: $application,
                encoder: $encoder,
                metrics: $metrics,
                logger: new NullLogger(),
                maxBufferedResponseBytes: $maxBufferedResponseBytes,
            ))->start();
        });

        return $metrics;
    }
}
