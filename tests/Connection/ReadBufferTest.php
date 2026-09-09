<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\ReadBuffer;
use PHPUnit\Framework\TestCase;

final class ReadBufferTest extends TestCase
{
    private ReadBuffer $buffer;

    protected function setUp(): void
    {
        $this->buffer = new ReadBuffer();
    }

    public function testStartsEmpty(): void
    {
        $this->assertTrue($this->buffer->isEmpty());
        $this->assertSame(0, $this->buffer->length());
    }

    public function testIgnoresEmptyChunks(): void
    {
        $this->buffer->append('');
        $this->assertTrue($this->buffer->isEmpty());
    }

    public function testAppendAccumulatesChunks(): void
    {
        $this->buffer->append('GET /hel');
        $this->buffer->append('lo HTTP/1.1');

        $this->assertFalse($this->buffer->isEmpty());
        $this->assertSame('GET /hello HTTP/1.1', (string) $this->buffer);
        $this->assertSame(19, $this->buffer->length());
    }

    public function testContainsFindsSubstrings(): void
    {
        $this->buffer->append("POST /users HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $this->assertTrue($this->buffer->contains("POST"));
        $this->assertTrue($this->buffer->contains("\r\n\r\n"));
        $this->assertTrue($this->buffer->contains("Host: localhost"));
        $this->assertFalse($this->buffer->contains("GET"));
    }

    public function testExtractThroughReturnsNullWhileIncomplete(): void
    {
        $this->buffer->append("GET / HTTP/1.1\r\n");

        $this->assertNull($this->buffer->extractThrough("\r\n\r\n"));
        $this->assertSame("GET / HTTP/1.1\r\n", (string) $this->buffer);
    }

    public function testExtractThroughReturnsMessageAndKeepsTheRest(): void
    {
        $this->buffer->append("GET / HTTP/1.1\r\n\r\nGET /n HTTP/1.1\r\n\r\n");

        $first = $this->buffer->extractThrough("\r\n\r\n");

        $this->assertSame("GET / HTTP/1.1\r\n\r\n", $first);
        $this->assertSame("GET /n HTTP/1.1\r\n\r\n", (string) $this->buffer);
    }

    public function testConsumeDropsBytesFromTheHead(): void
    {
        $this->buffer->append('abcdef');

        $this->buffer->consume(3);

        $this->assertSame('def', (string) $this->buffer);
    }

    public function testConsumeIgnoresNonPositiveLengths(): void
    {
        $this->buffer->append('abc');

        $this->buffer->consume(0);
        $this->buffer->consume(-1);

        $this->assertSame('abc', (string) $this->buffer);
    }

    public function testResetClearsEverything(): void
    {
        $this->buffer->append('leftover');

        $this->buffer->reset();

        $this->assertTrue($this->buffer->isEmpty());
        $this->assertSame(0, $this->buffer->length());
    }
}