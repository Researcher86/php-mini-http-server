<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\Connection;
use App\Connection\ConnectionException;
use App\Connection\ConnectionState;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    private Connection $connection;

    /** @var resource */
    private $socket;

    protected function setUp(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        $this->socket = $pair[0];
        $peer = $pair[1];

        $this->connection = Connection::accepted(1, $this->socket, 'unix://peer');
        $this->assertIsResource($peer);
        fclose($peer);
    }

    public function testStartsInNewStateThenConnects(): void
    {
        $connection = Connection::accepted(7, $this->socket, 'peer', now: 100.0);

        $this->assertSame(ConnectionState::NEW, $connection->state());
        $this->assertFalse($connection->isClosed());

        $connection->connect();

        $this->assertSame(ConnectionState::CONNECTED, $connection->state());
        $this->assertSame(7, $connection->id);
        $this->assertSame('peer', $connection->remoteAddress());
        $this->assertSame(100.0, $connection->connectedAt());
    }

    public function testLifecycleTransitionsInOrder(): void
    {
        $this->connection->connect();
        $this->connection->startReading();
        $this->assertSame(ConnectionState::READING, $this->connection->state());

        $this->connection->startProcessing();
        $this->assertSame(ConnectionState::PROCESSING, $this->connection->state());

        $this->connection->startWriting();
        $this->assertSame(ConnectionState::WRITING, $this->connection->state());

        $this->connection->backToReading();
        $this->assertSame(ConnectionState::READING, $this->connection->state());

        $this->connection->close();
        $this->assertSame(ConnectionState::CLOSED, $this->connection->state());
        $this->assertTrue($this->connection->isClosed());
    }

    public function testIllegalTransitionThrows(): void
    {
        $this->connection->startReading();

        $this->expectException(ConnectionException::class);
        $this->connection->connect();
    }

    public function testReadBufferAccumulatesAcrossPartialReads(): void
    {
        $this->connection->connect();
        $this->connection->appendRead('GET /hel');
        $this->connection->appendRead('lo HTTP/1.1');

        $this->assertSame('GET /hello HTTP/1.1', (string) $this->connection->readBuffer());
        $this->assertSame(19, $this->connection->bytesRead());

        $this->connection->readBuffer()->consume(4);
        $this->assertSame('/hello HTTP/1.1', (string) $this->connection->readBuffer());
    }

    public function testCompleteRequestIsDetectedOnlyAfterHeaderTerminator(): void
    {
        $this->connection->appendRead("GET / HTTP/1.1\r\nHost: localhost");
        $this->assertFalse($this->connection->hasCompleteRequest());

        $this->connection->appendRead("\r\n\r\n");
        $this->assertTrue($this->connection->hasCompleteRequest());
    }

    public function testWriteBufferAccumulates(): void
    {
        $this->connection->queueWrite('HTTP/1.1 200 OK');
        $this->connection->queueWrite("\r\n\r\n");

        $this->assertSame("HTTP/1.1 200 OK\r\n\r\n", (string) $this->connection->writeBuffer());
        $this->assertSame(19, $this->connection->writeBufferLength());
    }

    public function testQueueWriteOnClosedConnectionThrows(): void
    {
        $this->connection->close();

        $this->expectException(ConnectionException::class);
        $this->connection->queueWrite('data');
    }

    public function testWriteBufferReportsBackpressureSignal(): void
    {
        $this->connection->queueWrite(str_repeat('a', 10));

        $this->assertFalse($this->connection->hasBufferedMoreThan(10));
        $this->assertTrue($this->connection->hasBufferedMoreThan(9));
        $this->assertTrue($this->connection->hasBufferedMoreThan(0));
    }

    public function testHeaderWaitClockStartsOnceAndResets(): void
    {
        $this->assertSame(0.0, $this->connection->waitingForHeadersSince());

        $this->connection->noteWaitingForHeaders();
        $since = $this->connection->waitingForHeadersSince();
        $this->assertGreaterThan(0.0, $since);

        // A second note does not restart the clock — dribbling bytes must
        // not reset the Slowloris deadline.
        usleep(1000);
        $this->connection->noteWaitingForHeaders();
        $this->assertSame($since, $this->connection->waitingForHeadersSince());

        $this->connection->doneWaitingForHeaders();
        $this->assertSame(0.0, $this->connection->waitingForHeadersSince());
    }

    public function testCloseFreesTheSocket(): void
    {
        $this->connection->close();

        $this->assertSame(ConnectionState::CLOSED, $this->connection->state());
    }
}