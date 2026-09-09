<?php

declare(strict_types=1);

namespace App\Connection;

/**
 * The read side of a TCP connection.
 *
 * TCP does not preserve application message boundaries: one HTTP request
 * may arrive as many read() calls, and many requests as one. The buffer's
 * job is to absorb arbitrary chunks and hand out only complete messages:
 *
 *     Socket reads → append() → is there "\r\n\r\n"? ─ No → wait for more
 *                                        │
 *                                        └─ Yes → extractThrough() → parser
 *
 * The buffer is deliberately HTTP-agnostic; it deals in bytes and delimiters.
 * Deciding that a delimiter means "one complete HTTP request" is the
 * parser's job (Phase 5).
 */
final class ReadBuffer
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

    public function contains(string $needle): bool
    {
        return $needle === '' || str_contains($this->data, $needle);
    }

    /**
     * Pull out everything up to and including $needle, leaving the rest in
     * the buffer. Returns null while $needle has not fully arrived — the
     * wait-for-more-data case the class exists for.
     */
    public function extractThrough(string $needle): ?string
    {
        $pos = strpos($this->data, $needle);

        if ($pos === false) {
            return null;
        }

        $end = $pos + strlen($needle);
        $message = substr($this->data, 0, $end);
        $this->data = substr($this->data, $end);

        return $message;
    }

    /**
     * Discard the first $length bytes — used for e.g. request bodies that
     * are consumed separately from the headers.
     */
    public function consume(int $length): void
    {
        if ($length <= 0) {
            return;
        }

        $this->data = substr($this->data, $length);
    }

    public function reset(): void
    {
        $this->data = '';
    }

    public function __toString(): string
    {
        return $this->data;
    }
}