<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\EventLoop;

use PhpMiniHttpServer\Connection\Connection;
use PhpMiniHttpServer\EventLoop\SelectLoop;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8 story in one test: a large response must survive partial socket
 * writes. The connection queues bytes, flushWrite() sends what the socket
 * accepts, and the remaining bytes wait for the next writable event while
 * the client reads concurrently — exactly the send/recv dance of a real
 * HTTP exchange.
 */
final class PartialWriteIntegrationTest extends TestCase
{
    /** @var resource */
    private $serverSide;

    /** @var resource */
    private $clientSide;

    private Connection $connection;

    private SelectLoop $loop;

    private string $received = '';

    protected function setUp(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);

        [$this->serverSide, $this->clientSide] = $pair;
        stream_set_blocking($this->serverSide, false); // like Server does for accepted sockets
        $this->connection = Connection::accepted(1, $this->serverSide, 'unix://peer');
        $this->connection->connect();
        $this->connection->startWriting();

        $this->loop = new SelectLoop();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->clientSide)) {
            fclose($this->clientSide);
        }

        if (is_resource($this->serverSide)) {
            fclose($this->serverSide);
        }
    }

    public function testLargeResponseIsFullyDeliveredDespitePartialWrites(): void
    {
        $payload = str_repeat('x', 1_000_000);
        $expected = "HTTP/1.1 200 OK\r\n\r\n" . $payload;
        $this->connection->queueWrite($expected);

        $this->runExchange();

        $this->assertSame($expected, $this->received);
        $this->assertTrue($this->connection->writeBuffer()->isEmpty());
        $this->assertSame(strlen($expected), $this->connection->bytesWritten());
    }

    public function testPartialFlushKeepsRemainderUntilNextWritableEvent(): void
    {
        $payload = str_repeat('y', 500_000);
        $this->connection->queueWrite($payload);

        $this->runExchange();

        $this->assertSame($payload, $this->received);
        $this->assertTrue($this->connection->writeBuffer()->isEmpty());
    }

    /**
     * Run the loop with the client draining into $received while the server
     * flushes its write buffer; stop the moment everything is out.
     */
    private function runExchange(): void
    {
        $received = &$this->received;
        $loop = $this->loop;
        $connection = $this->connection;

        $this->loop->onReadable($this->clientSide, static function ($stream) use (&$received): void {
            $chunk = fread($stream, 8192);
            if ($chunk !== false && $chunk !== '') {
                $received .= $chunk;
            }
        });

        $this->loop->onWritable($this->serverSide, static function ($stream) use ($connection, $loop): void {
            $connection->flushWrite($stream);

            // 0 from flushWrite just means "send buffer full, wait for the
            // next writable event" — only stop once everything is gone.
            if ($connection->writeBuffer()->isEmpty()) {
                $loop->removeWritable($stream);
                $loop->stop();
            }
        });

        $this->loop->run();

        // Everything is flushed to the kernel; closing the server side
        // drains what is still in transit and hands the client an EOF so
        // the final read loop terminates instead of blocking for more.
        fclose($this->serverSide);

        while (($chunk = fread($this->clientSide, 8192)) !== false && $chunk !== '') {
            $this->received .= $chunk;
        }
    }
}
