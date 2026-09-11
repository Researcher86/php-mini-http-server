<?php

declare(strict_types=1);

namespace App\Tests\EventLoop;

use App\Connection\Connection;
use App\EventLoop\SelectLoop;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\ParsedRequest;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 story in one test: an HTTP request split across several TCP
 * reads must accumulate in the connection's read buffer, and the parser
 * must refuse to see a request there until the last byte of it arrives.
 *
 * The parser is the one that decides — the buffer only holds bytes — so
 * these tests ask it, exactly as ConnectionHandler does.
 */
final class PartialReadIntegrationTest extends TestCase
{
    /** @var resource */
    private $serverSide;

    /** @var resource */
    private $clientSide;

    private Connection $connection;

    private SelectLoop $loop;

    private HttpParser $parser;

    protected function setUp(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);

        [$this->serverSide, $this->clientSide] = $pair;
        $this->connection = Connection::accepted(1, $this->serverSide, 'unix://peer');
        $this->connection->connect();
        $this->connection->startReading();

        $this->loop = new SelectLoop();
        $this->parser = new HttpParser();
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

        $this->assertNull($this->parse());
        $this->assertSame("GET /hel", (string) $this->connection->readBuffer());

        fwrite($this->clientSide, "lo HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $this->runLoopFor(0.03);

        $parsed = $this->parse();

        $this->assertNotNull($parsed);
        $this->assertSame('/hello', $parsed->request->target);
        $this->assertSame($this->connection->readBuffer()->length(), $parsed->consumedBytes);
    }

    public function testTwoRequestsInOneReadAreKeptInTheBuffer(): void
    {
        $this->registerReader();

        fwrite($this->clientSide, "GET /a HTTP/1.1\r\nHost: t\r\n\r\nGET /b HTTP/1.1\r\nHost: t\r\n\r\n");

        $this->runLoopFor(0.05);

        $first = $this->parse();

        $this->assertNotNull($first);
        $this->assertSame('/a', $first->request->target);

        // Only the first request's bytes are dropped; the second one waits
        // its turn in the buffer, which is what Phase 15 pipelining builds on.
        $this->connection->readBuffer()->consume($first->consumedBytes);

        $this->assertSame("GET /b HTTP/1.1\r\nHost: t\r\n\r\n", (string) $this->connection->readBuffer());
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

    private function parse(): ?ParsedRequest
    {
        return $this->parser->parse((string) $this->connection->readBuffer());
    }

    private function runLoopFor(float $seconds): void
    {
        $loop = $this->loop;
        $this->loop->addTimer($seconds, static fn () => $loop->stop());
        $this->loop->run();
    }
}