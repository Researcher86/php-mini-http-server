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
 * Phase 13 timeouts over real sockets: a request that stops arriving midway
 * must not park the connection forever. The reaper mirrors bin/server.php —
 * an idle sweep plus a header-clock sweep, run on a timer — so these tests
 * wire the same machinery the demo server runs in production.
 *
 * The header clock covers the whole "waiting for a complete request" state,
 * body included: once the header block is in but the declared body never
 * finishes, the connection is stuck mid-request the same way a slow header
 * is, and the same sweep reaps it.
 */
final class RoundTripTimeoutTest extends TestCase
{
    private Server $server;

    private SelectLoop $loop;

    protected function setUp(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: 0));
        $this->server->start();

        $router = new Router();
        $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello'));
        $router->post('/hello', static fn (): HttpResponse => ResponseFactory::text('Posted'));

        $pipeline = new MiddlewarePipeline($router);
        $pipeline->add(new ErrorHandlerMiddleware());

        $loop = new SelectLoop();
        $this->loop = $loop;

        $metrics = new ServerMetrics();
        $logger = new NullLogger();
        $parser = new HttpParser();
        $encoder = new ResponseEncoder();

        $server = $this->server;
        $loop->onReadable($server->socket(), static function ($stream) use (
            $loop,
            $server,
            $parser,
            $pipeline,
            $encoder,
            $metrics,
            $logger,
        ): void {
            $connection = $server->accept();

            if ($connection !== null) {
                (new ConnectionHandler(
                    loop: $loop,
                    server: $server,
                    connection: $connection,
                    parser: $parser,
                    application: $pipeline,
                    encoder: $encoder,
                    metrics: $metrics,
                    logger: $logger,
                ))->start();
            }
        });
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testSlowHeaderIsReapedByHeaderTimeout(): void
    {
        // Header block without the terminating blank line, then silence.
        $this->assertMidRequestConnectionIsReaped("GET /hello HTTP/1.1\r\nHost: t");
    }

    public function testRequestBodyThatNeverFinishesIsReapedByHeaderTimeout(): void
    {
        // Headers declare 5 body bytes but only 2 ever arrive.
        $this->assertMidRequestConnectionIsReaped("POST /hello HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\n\r\nab");
    }

    private function assertMidRequestConnectionIsReaped(string $partialBytes): void
    {
        $port = $this->server->getPort();

        $client = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($client);

        fwrite($client, $partialBytes);

        // The idle timeout is deliberately long: in the window this test
        // allows, ANY close must come from the header clock, never from the
        // idle sweep.
        $server = $this->server;
        $loop = $this->loop;
        $loop->every(0.02, static function () use ($server): void {
            $server->closeIdleConnections(10.0);
            $server->closeSlowHeaderReads(0.05);
        });

        $done = false;
        $deadline = microtime(true) + 2.0;
        $loop->every(0.02, static function () use ($loop, $server, &$done, $deadline): void {
            if ($server->connectionCount() === 0) {
                $done = true;
                $loop->stop();
            } elseif (microtime(true) > $deadline) {
                $loop->stop();
            }
        });

        $loop->run();

        $this->assertTrue($done, 'a request stuck mid-arrival must be reaped by the header clock');
        $this->assertSame(0, $server->connectionCount());

        fclose($client);
    }
}