<?php

declare(strict_types=1);

namespace App\Tests\EventLoop;

use App\EventLoop\SelectLoop;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\ResponseEncoder;
use App\Http\Response\HttpResponse;
use App\Http\Response\ResponseFactory;
use App\Metrics\ServerMetrics;
use App\Router\Router;
use App\Server\ConnectionHandler;
use App\Server\Server;
use App\Server\ServerConfig;
use App\Support\NullLogger;
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