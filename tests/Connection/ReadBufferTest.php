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

}