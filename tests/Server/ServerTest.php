<?php

declare(strict_types=1);

namespace App\Tests\Server;

use App\Server\Server;
use App\Server\ServerConfig;
use App\Server\ServerStartException;
use App\Server\ServerState;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    private Server $server;

    private ServerConfig $config;

    protected function setUp(): void
    {
        $this->config = new ServerConfig(host: '127.0.0.1', port: 0);
        $this->server = new Server($this->config);
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
        $clientB = stream_socket_client("tcp://127.0.0.1:$port");

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
}