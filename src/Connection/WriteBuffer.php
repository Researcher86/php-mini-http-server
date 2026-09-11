<?php

declare(strict_types=1);

namespace App\Connection;

use TypeError;

/**
 * The write side of a TCP connection.
 *
 * A single fwrite() may send only part of a large response before the
 * socket's send buffer fills up. The buffer keeps what was not accepted
 * and hands it back out on the next writable event:
 *
 *     queue → flushTo() → everything accepted? ─ Yes → done
 *                                      │
 *                                      └─ No → keep remainder, wait for writable
 *
 * flushTo() talks to a (non-blocking) socket: it returns 0 when the socket
 * would block, throws when the socket itself errors, and drops whatever
 * was actually written so the caller never double-sends.
 */
final class WriteBuffer
{
    private string $data = '';

    public function append(string $chunk): void
    {
        if ($chunk !== '') {
            $this->data .= $chunk;
        }
    }

    public function length(): int
    {
        return strlen($this->data);
    }

    public function isEmpty(): bool
    {
        return $this->data === '';
    }

    /**
     * Try to write all buffered bytes to $stream and drop exactly what the
     * socket accepted.
     *
     * @param resource $stream
     *
     * @throws WriteBufferException when the socket errors out
     *
     * Returns 0 when there is nothing buffered or the socket would block —
     * non-blocking sockets report "send more later" as a silent 0, not as an
     * error, so the loop simply waits for the next writable event.
     */
    public function flushTo(mixed $stream): int
    {
        if ($this->data === '') {
            return 0;
        }

        try {
            $written = @fwrite($stream, $this->data);
        } catch (TypeError) {
            throw new WriteBufferException('Failed to write to socket: stream is not usable.');
        }

        if ($written === false) {
            throw new WriteBufferException('Failed to write to socket.');
        }

        if ($written > 0) {
            $this->data = substr($this->data, $written);
        }

        return $written;
    }

    public function __toString(): string
    {
        return $this->data;
    }
}
