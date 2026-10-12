<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\Server;

use PhpMiniHttpServer\EventLoop\SelectLoop;
use PhpMiniHttpServer\Http\Middleware\MiddlewarePipeline;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PhpMiniHttpServer\Router\Router;
use PhpMiniHttpServer\Server\ConnectionHandler;
use PhpMiniHttpServer\Server\GracefulShutdown;
use PhpMiniHttpServer\Server\Server;
use PhpMiniHttpServer\Server\ServerConfig;
use PhpMiniHttpServer\Server\ServerState;
use PHPUnit\Framework\TestCase;

/**
 * Phase 19's RUNNING → DRAINING → FINISHING → STOPPED, driven the way
 * bin/server.php drives it: a real SelectLoop, real sockets, and shutdown
 * requested from inside the loop — or by a real signal, which must do
 * nothing more than wake the loop.
 *
 * Every run carries a safety timer, so a shutdown that never finishes
 * fails the assertions instead of hanging the suite.
 */
final class GracefulShutdownTest extends TestCase
{
    private const float SAFETY_STOP_SECONDS = 3.0;

    private Server $server;

    private SelectLoop $loop;

    /** @var list<resource> */
    private array $clients = [];

    protected function tearDown(): void
    {
        pcntl_signal(SIGUSR1, SIG_DFL);

        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                fclose($client);
            }
        }

        $this->server->stop();
    }

    public function testRestingConnectionsAreReapedAndTheLoopStopsOnItsOwn(): void
    {
        $shutdown = $this->startServer();
        $client = $this->connectAndRequest("GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");

        $this->loop->addTimer(0.1, $shutdown->requestShutdown(...));
        $elapsed = $this->runLoop();

        // A keep-alive connection between requests has nothing to finish,
        // so shutdown does not wait out its idle timeout.
        $this->assertSame(ServerState::STOPPED, $this->server->state());
        $this->assertLessThan(1.0, $elapsed);
        $this->assertSame('', $this->readUntilEof($client, afterResponse: true));
    }

    public function testAnInFlightRequestFinishesAndANewOneIsRefusedBeforeTheServerStops(): void
    {
        $shutdown = $this->startServer();
        $client = $this->connect();

        // Half a request is on the wire when the shutdown begins.
        fwrite($client, "GET /hello HTTP/1.1\r\n");

        $this->loop->addTimer(0.05, $shutdown->requestShutdown(...));
        $this->loop->addTimer(0.15, function () use ($client): void {
            $this->assertSame(ServerState::DRAINING, $this->server->state());
            $this->assertSame(1, $this->server->connectionCount());

            fwrite($client, "Host: t\r\n\r\n");
        });

        $this->runLoop();

        $this->assertSame(ServerState::STOPPED, $this->server->state());
        $this->assertStringContainsString('503 Service Unavailable', $this->readUntilEof($client));
    }

    public function testASecondRequestForcesTheEnd(): void
    {
        $shutdown = $this->startServer();
        $client = $this->connect();
        fwrite($client, "GET /hello HTTP/1.1\r\n");

        $this->loop->addTimer(0.05, $shutdown->requestShutdown(...));
        $this->loop->addTimer(0.1, $shutdown->requestShutdown(...));
        $elapsed = $this->runLoop();

        $this->assertSame(ServerState::STOPPED, $this->server->state());
        $this->assertLessThan(1.0, $elapsed);
    }

    public function testTheDrainDeadlineClosesConnectionsThatNeverFinish(): void
    {
        // headerTimeout is far away and nothing sweeps it here: only the
        // drain deadline can end this shutdown.
        $shutdown = $this->startServer(drainTimeout: 0.3);
        $client = $this->connect();
        fwrite($client, "GET /hello HTTP/1.1\r\n");

        $this->loop->addTimer(0.05, $shutdown->requestShutdown(...));
        $elapsed = $this->runLoop();

        $this->assertSame(ServerState::STOPPED, $this->server->state());
        $this->assertSame(0, $this->server->connectionCount());
        $this->assertGreaterThanOrEqual(0.3, $elapsed);
        $this->assertLessThan(1.0, $elapsed);
        $this->assertSame('', $this->readUntilEof($client));
    }

    /**
     * The hazard this class exists to remove: an async signal handler runs
     * between any two statements of whatever it interrupts, so it must not
     * touch the loop or the server itself. The signal only wakes the loop;
     * the shutdown happens on the loop's own turn.
     */
    public function testASignalChangesNothingUntilTheLoopHandlesIt(): void
    {
        $shutdown = $this->startServer();
        $shutdown->listenForSignals(SIGUSR1);

        posix_kill(posix_getpid(), SIGUSR1);

        $this->assertSame(ServerState::RUNNING, $this->server->state());

        $elapsed = $this->runLoop();

        $this->assertSame(ServerState::STOPPED, $this->server->state());
        $this->assertLessThan(1.0, $elapsed);
    }

    public function testTwoSignalsThatArriveBeforeTheLoopWakesStillForceTheEnd(): void
    {
        $shutdown = $this->startServer();
        $shutdown->listenForSignals(SIGUSR1);

        $client = $this->connect();
        fwrite($client, "GET /hello HTTP/1.1\r\n");
        $this->loop->addTimer(0.05, static function (): void {
            posix_kill(posix_getpid(), SIGUSR1);
            posix_kill(posix_getpid(), SIGUSR1);
        });

        // One wake-up carries both signals; each still counts. Without the
        // second the in-flight request would hold the drain open for the
        // full 30-second deadline.
        $elapsed = $this->runLoop();

        $this->assertSame(ServerState::STOPPED, $this->server->state());
        $this->assertLessThan(1.0, $elapsed);
    }

    private function startServer(float $drainTimeout = 30.0): GracefulShutdown
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: 0, drainTimeout: $drainTimeout));
        $this->server->start();
        $this->loop = new SelectLoop();

        $router = new Router();
        $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello'));

        ConnectionHandler::acceptOn($this->loop, $this->server, new MiddlewarePipeline($router));

        return new GracefulShutdown($this->loop, $this->server);
    }

    /**
     * @return resource
     */
    private function connect(): mixed
    {
        $client = stream_socket_client("tcp://127.0.0.1:{$this->server->getPort()}");
        $this->assertIsResource($client);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * Connect, send $request and leave its response unread on the socket.
     *
     * @return resource
     */
    private function connectAndRequest(string $request): mixed
    {
        $client = $this->connect();
        fwrite($client, $request);

        return $client;
    }

    /**
     * Run the loop until something stops it.
     *
     * @return float how long it ran, in seconds
     */
    private function runLoop(): float
    {
        $safety = $this->loop->addTimer(self::SAFETY_STOP_SECONDS, $this->loop->stop(...));
        $started = hrtime(true);

        $this->loop->run();

        $this->loop->cancelTimer($safety);

        return (hrtime(true) - $started) / 1e9;
    }

    /**
     * Everything the server sent until it closed the connection. With
     * $afterResponse, the one response already sent is skipped, so only
     * what came after it is returned.
     *
     * @param resource $client
     */
    private function readUntilEof(mixed $client, bool $afterResponse = false): string
    {
        stream_set_timeout($client, 1);
        $received = '';

        while (!feof($client)) {
            $chunk = fread($client, 8192);

            if ($chunk === false || ($chunk === '' && stream_get_meta_data($client)['timed_out'])) {
                $this->fail('The server never closed the connection.');
            }

            $received .= $chunk;
        }

        if ($afterResponse) {
            $this->assertStringStartsWith('HTTP/1.1 200 OK', $received);

            return substr($received, (int) strpos($received, "\r\n\r\n") + 4 + strlen('Hello'));
        }

        return $received;
    }
}
