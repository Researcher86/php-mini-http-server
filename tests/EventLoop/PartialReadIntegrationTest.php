<?php

declare(strict_types=1);

namespace App\Tests\EventLoop;

use App\Connection\Connection;
use App\EventLoop\SelectLoop;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 story in one test: an HTTP request split across several TCP
 * reads must accumulate in the connection's read buffer and only be seen
 * as "complete" once the header terminator has fully arrived.
 */
final class PartialReadIntegrationTest extends TestCase
{
    /** @var resource */
    private $serverSide;

    /** @var resource */
    private $clientSide;

    private Connection $connection;

    private SelectLoop $loop;

    protected function setUp(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);

        [$this->serverSide, $this->clientSide] = $pair;
        $this->connection = Connection::accepted(1, $this->serverSide, 'unix://peer');
        $this->connection->connect();
        $this->connection->startReading();

        $this->loop = new SelectLoop();
    }

    protected function tearDown(): void
    {
        fclose($this->clientSide);
        fclose($this->serverSide);
    }

    public function testSplitRequestIsBufferedUntilComplete(): void
    {
        $this->registerReader();

        fwrite($this->clientSide, "GET /hel");

        $this->runLoopFor(0.03);

        $this->assertFalse($this->connection->hasCompleteRequest());
        $this->assertSame("GET /hel", (string) $this->connection->readBuffer());

        fwrite($this->clientSide, "lo HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $this->runLoopFor(0.03);

        $this->assertTrue($this->connection->hasCompleteRequest());
        $this->assertSame(
            "GET /hello HTTP/1.1\r\nHost: localhost\r\n\r\n",
            (string) $this->connection->readBuffer(),
        );
    }

    public function testTwoRequestsInOneReadAreKeptInTheBuffer(): void
    {
        $this->registerReader();

        fwrite($this->clientSide, "GET /a HTTP/1.1\r\n\r\nGET /b HTTP/1.1\r\n\r\n");

        $this->runLoopFor(0.05);

        $first = $this->connection->readBuffer()->extractThrough("\r\n\r\n");

        $this->assertSame("GET /a HTTP/1.1\r\n\r\n", $first);
        $this->assertSame("GET /b HTTP/1.1\r\n\r\n", (string) $this->connection->readBuffer());
    }

    /**
     * The connection's buffer only fills when some code pulls bytes off the
     * socket — the event-loop handler that later feeds the HTTP parser.
     */
    private function registerReader(): void
    {
        $this->loop->onReadable($this->serverSide, function ($stream): void {
            $data = fread($stream, 8192);

            if ($data !== false && $data !== '') {
                $this->connection->appendRead($data);
            }
        });
    }

    private function runLoopFor(float $seconds): void
    {
        $loop = $this->loop;
        $this->loop->addTimer($seconds, static fn () => $loop->stop());
        $this->loop->run();
    }
}