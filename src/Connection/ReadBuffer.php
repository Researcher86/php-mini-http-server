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
 *     Socket reads → append() → parser: a full request in there? ─ No → wait
 *                          ▲                                         │
 *                          └──────── consume(what it used) ◄─── Yes ─┘
 *
 * The buffer is deliberately HTTP-agnostic: it accumulates bytes and drops
 * the ones somebody else has consumed. Deciding where a request ends is the
 * parser's job (Phase 5), which is why there is no delimiter search here.
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

    /**
     * Drop the first $length bytes: the caller has turned them into a
     * request and whatever follows is the next one's.
     */
    public function consume(int $length): void
    {
        if ($length <= 0) {
            return;
        }

        $this->data = substr($this->data, $length);
    }

    public function __toString(): string
    {
        return $this->data;
    }
}
