<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\Server;

use PhpMiniHttpServer\Server\Server;
use PhpMiniHttpServer\Server\ServerConfig;
use PhpMiniHttpServer\Server\ServerStartException;
use PhpMiniHttpServer\Server\ServerState;
use PhpMiniHttpServer\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    private Server $server;

    private ServerConfig $config;

    /** Time moves only where a test says so, so the sweeps need no waiting. */
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->config = new ServerConfig(host: '127.0.0.1', port: 0);
        $this->clock = new FakeClock();
        $this->server = new Server($this->config, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testStartsRunningAndBindsToArbitraryPort(): void
    {
        $this->server->start();

        $this->assertTrue($this->server->isRunning());
        $this->assertSame(ServerState::RUNNING, $this->server->state());
        $this->assertSame('127.0.0.1', $this->server->getHost());
        $this->assertGreaterThan(0, $this->server->getPort());
    }

    public function testAcceptsPendingClientConnection(): void
    {
        $this->server->start();

        $port = $this->server->getPort();

        $client = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr);
        $this->assertIsResource($client);

        $connection = $this->server->accept();
        $this->assertNotNull($connection);

        $socket = $connection->socket();
        $this->assertIsResource($socket);

        fwrite($client, "hello\n");
        $this->assertSame("hello\n", fread($socket, 6));

        $this->server->close($connection);
        fclose($client);
    }

    public function testTracksConnectionsUntilClosed(): void
    {
        $this->server->start();

        $port = $this->server->getPort();

        $clientA = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($clientA);
        $clientB = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($clientB);

        $a = $this->server->accept();
        $b = $this->server->accept();

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, $this->server->connectionCount());

        $this->server->close($a);
        $this->assertSame(1, $this->server->connectionCount());

        fclose($clientA);
        fclose($clientB);
    }

    public function testAcceptReturnsNullWhenNothingIsPending(): void
    {
        $this->server->start();

        $this->assertNull($this->server->accept());
    }

    public function testConnectionsPastTheCeilingAreRefusedAtOnce(): void
    {
        $server = new Server(new ServerConfig(host: '127.0.0.1', port: 0, maxConnections: 2), $this->clock);
        $server->start();

        $port = $server->getPort();
        $clients = [];

        for ($i = 0; $i < 3; $i++) {
            $client = stream_socket_client("tcp://127.0.0.1:$port");
            $this->assertIsResource($client);
            $clients[] = $client;
        }

        $this->assertNotNull($server->accept());
        $this->assertNotNull($server->accept());

        // The third is accepted by the kernel and closed by us: the client
        // is told immediately rather than left queued behind a server that
        // will never get to it.
        $this->assertNull($server->accept());

        $this->assertSame(2, $server->connectionCount());
        $this->assertSame(1, $server->refusedConnections());

        $server->stop();

        foreach ($clients as $client) {
            fclose($client);
        }
    }

    public function testStopClosesSocketAndFlipsState(): void
    {
        $this->server->start();
        $this->server->stop();

        $this->assertFalse($this->server->isRunning());
        $this->assertSame(ServerState::STOPPED, $this->server->state());
    }

    public function testStartThrowsWhenPortIsAlreadyTaken(): void
    {
        $first = new Server(new ServerConfig(host: '127.0.0.1', port: 0));
        $first->start();

        $second = new Server(new ServerConfig(host: '127.0.0.1', port: $first->getPort()));

        $this->expectException(ServerStartException::class);
        $second->start();

        $first->stop();
    }

    public function testAcceptThrowsWhenServerIsNotStarted(): void
    {
        $this->expectException(ServerStartException::class);
        $this->server->accept();
    }

    public function testCloseIdleConnectionsReapsQuietClients(): void
    {
        $this->server->start();

        $port = $this->server->getPort();

        $client = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($client);
        $connection = $this->server->accept();
        $this->assertNotNull($connection);

        $this->clock->advance(10.0);

        $closed = $this->server->closeIdleConnections(5.0);

        $this->assertSame([$connection], $closed);
        $this->assertSame(0, $this->server->connectionCount());

        fclose($client);
    }

    public function testActiveConnectionsSurviveTheIdleSweep(): void
    {
        $this->server->start();

        $port = $this->server->getPort();

        $client = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($client);
        $connection = $this->server->accept();
        $this->assertNotNull($connection);

        $this->clock->advance(2.0);
        $connection->appendRead('data'); // activity resets the idle clock

        $closed = $this->server->closeIdleConnections(5.0);

        $this->assertSame([], $closed);
        $this->assertSame(1, $this->server->connectionCount());

        $this->server->close($connection);
        fclose($client);
    }

    public function testCloseIdleConnectionsReportsMultipleReclaims(): void
    {
        $this->server->start();

        $port = $this->server->getPort();

        $clientA = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($clientA);
        $clientB = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($clientB);

        $a = $this->server->accept();
        $b = $this->server->accept();
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        $this->clock->advance(5.0);

        $closed = $this->server->closeIdleConnections(1.0);

        $this->assertCount(2, $closed);
        $this->assertSame(0, $this->server->connectionCount());

        fclose($clientA);
        fclose($clientB);
    }

    public function testDrainStopsAcceptingAndFlipsToDraining(): void
    {
        $this->server->start();

        $port = $this->server->getPort();
        $client = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($client);
        $connection = $this->server->accept();
        $this->assertNotNull($connection);

        $this->server->drain();

        $this->assertSame(ServerState::DRAINING, $this->server->state());
        $this->assertSame(1, $this->server->connectionCount(), 'existing connections survive draining');

        // Listening socket is gone: nothing new can connect.
        $late = @stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertFalse($late);

        $this->server->finish();
        fclose($client);
    }

    public function testIsDrainingReflectsGracefulShutdown(): void
    {
        $this->server->start();
        $this->assertFalse($this->server->isDraining());

        $this->server->drain();
        $this->assertTrue($this->server->isDraining());
    }

    public function testCloseRestingConnectionsReapsIdleKeepAliveConnections(): void
    {
        $this->server->start();

        $port = $this->server->getPort();

        $idleClient = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($idleClient);
        $idle = $this->server->accept();
        $this->assertNotNull($idle);

        $busyClient = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($busyClient);
        $busy = $this->server->accept();
        $this->assertNotNull($busy);
        $busy->appendRead('partial'); // mid-exchange: must survive the reap

        $this->server->drain();

        $closed = $this->server->closeRestingConnections();

        $this->assertSame([$idle], $closed);
        $this->assertSame(1, $this->server->connectionCount());
        $this->assertContains($busy, $this->server->connections());

        $this->server->close($busy);
        fclose($idleClient);
        fclose($busyClient);
    }

    public function testFinishClosesRemainingConnectionsAndStops(): void
    {
        $this->server->start();

        $port = $this->server->getPort();
        $client = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($client);
        $connection = $this->server->accept();
        $this->assertNotNull($connection);

        $this->server->drain();
        $this->server->finish();

        $this->assertSame(ServerState::STOPPED, $this->server->state());
        $this->assertSame(0, $this->server->connectionCount());

        fclose($client);
    }

    public function testCloseSlowHeaderReadsReapsMidHeaderConnections(): void
    {
        $this->server->start();

        $port = $this->server->getPort();
        $client = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($client);
        $connection = $this->server->accept();
        $this->assertNotNull($connection);

        $connection->noteWaitingForHeaders();
        $this->clock->advance(10.0);

        $closed = $this->server->closeSlowHeaderReads(5.0);

        $this->assertSame([$connection], $closed);
        $this->assertSame(0, $this->server->connectionCount());

        fclose($client);
    }

    public function testCloseSlowHeaderReadsLeavesHealthyConnectionsAlone(): void
    {
        $this->server->start();

        $port = $this->server->getPort();
        $client = stream_socket_client("tcp://127.0.0.1:$port");
        $this->assertIsResource($client);
        $connection = $this->server->accept();
        $this->assertNotNull($connection);
        $connection->noteWaitingForHeaders();
        $connection->doneWaitingForHeaders();
        $this->clock->advance(100.0);

        $closed = $this->server->closeSlowHeaderReads(1.0);

        $this->assertSame([], $closed);
        $this->assertSame(1, $this->server->connectionCount());

        $this->server->close($connection);
        fclose($client);
    }
}
