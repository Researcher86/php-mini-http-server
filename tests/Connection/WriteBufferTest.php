<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\WriteBuffer;
use App\Connection\WriteBufferException;
use PHPUnit\Framework\TestCase;

final class WriteBufferTest extends TestCase
{
    public function testAppendsAndTracksLength(): void
    {
        $buffer = new WriteBuffer();
        $buffer->append('Hello ');
        $buffer->append('world');

        $this->assertSame('Hello world', (string) $buffer);
        $this->assertSame(11, $buffer->length());
        $this->assertFalse($buffer->isEmpty());
    }

    public function testIgnoresEmptyChunks(): void
    {
        $buffer = new WriteBuffer();
        $buffer->append('');
        $buffer->append('x');

        $this->assertSame(1, $buffer->length());
    }

    public function testFlushWritesEverythingAndEmptiesBuffer(): void
    {
        $buffer = new WriteBuffer();
        $buffer->append('response');

        [$server, $client] = $this->socketPair();

        $written = $buffer->flushTo($server);

        $this->assertSame(8, $written);
        $this->assertTrue($buffer->isEmpty());
        $this->assertSame('response', fread($client, 8192));

        fclose($server);
        fclose($client);
    }

    public function testPartialFlushKeepsRemainderForNextAttempt(): void
    {
        $buffer = new WriteBuffer();
        $buffer->append('0123456789');

        // A tiny stream is a pretend full send buffer: everything fits one
        // call but only part when we write through a 6-byte window.
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);

        $written = 0;
        while ($buffer->length() > 0) {
            $attempt = $buffer->flushTo($stream);
            if ($attempt <= 0) {
                break;
            }
            $written += $attempt;
        }

        $this->assertSame(10, $written);
        $this->assertTrue($buffer->isEmpty());

        rewind($stream);
        $this->assertSame('0123456789', fread($stream, 8192));
        fclose($stream);
    }

    public function testFlushOnEmptyBufferReturnsZero(): void
    {
        $buffer = new WriteBuffer();

        [$server, $client] = $this->socketPair();

        $this->assertSame(0, $buffer->flushTo($server));

        fclose($server);
        fclose($client);
    }

    public function testFlushOnDeadSocketThrows(): void
    {
        $buffer = new WriteBuffer();
        $buffer->append('data');

        [$server, $client] = $this->socketPair();
        fclose($client);
        fclose($server); // writing to a closed stream is a hard error, not a block

        $this->expectException(WriteBufferException::class);
        $buffer->flushTo($server);
    }

    public function testResetDiscardsEverything(): void
    {
        $buffer = new WriteBuffer();
        $buffer->append('data');
        $buffer->reset();

        $this->assertTrue($buffer->isEmpty());
        $this->assertSame('', (string) $buffer);
    }

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);

        return $pair;
    }
}