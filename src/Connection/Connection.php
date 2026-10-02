<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Connection;

use PhpMiniHttpServer\Support\Clock;
use PhpMiniHttpServer\Support\SystemClock;

/**
 * An explicit representation of one client TCP connection.
 *
 * Phases 1 already proved the socket works; this class is the object the
 * Event Loop will later react to. It bundles everything a connection owns:
 *
 *     Socket          the actual network stream
 *     Read Buffer     bytes read from the socket but not yet parsed
 *     Write Buffer    bytes parsed but not yet fully written
 *     State           where in the lifecycle the connection is
 *     Metadata        id, remote address, timestamps, byte counters
 *
 * The read side is a ReadBuffer that accumulates bytes until the parser
 * finds a full request in them; the write side is a WriteBuffer that
 * survives partial writes.
 */
final class Connection
{
    private ConnectionState $state = ConnectionState::NEW;

    private readonly ReadBuffer $readBuffer;

    private readonly WriteBuffer $writeBuffer;

    private int $bytesRead = 0;

    private int $bytesWritten = 0;

    private float $lastActivityAt;

    /** 0.0 when not mid-header; otherwise when the current header block started */
    private float $waitingForHeadersSince = 0.0;

    private readonly float $connectedAt;

    /**
     * @param resource $socket a freshly accepted client socket
     */
    public function __construct(
        public readonly int $id,
        private readonly mixed $socket,
        private readonly string $remoteAddress,
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->readBuffer = new ReadBuffer();
        $this->writeBuffer = new WriteBuffer();
        $this->connectedAt = $clock->now();
        $this->lastActivityAt = $this->connectedAt;
    }

    public function connect(): void
    {
        $this->assertState(ConnectionState::NEW, 'connect');
        $this->state = ConnectionState::CONNECTED;
    }

    public function startReading(): void
    {
        $this->moveTo(ConnectionState::READING, 'startReading');
    }

    public function startProcessing(): void
    {
        $this->moveTo(ConnectionState::PROCESSING, 'startProcessing');
    }

    public function startWriting(): void
    {
        $this->moveTo(ConnectionState::WRITING, 'startWriting');
    }

    /**
     * Back to READING without closing — the keep-alive path the lifecycle
     * diagram draws as WRITING → READING.
     */
    public function backToReading(): void
    {
        $this->moveTo(ConnectionState::READING, 'backToReading');
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->state = ConnectionState::CLOSED;
    }

    /**
     * @return resource
     */
    public function socket(): mixed
    {
        return $this->socket;
    }

    public function state(): ConnectionState
    {
        return $this->state;
    }

    public function remoteAddress(): string
    {
        return $this->remoteAddress;
    }

    public function connectedAt(): float
    {
        return $this->connectedAt;
    }

    public function lastActivityAt(): float
    {
        return $this->lastActivityAt;
    }

    /**
     * The connection is now waiting for the rest of a request header block.
     *
     * The Slowloris guard keys off this instant: a client may keep the idle
     * sweep happy by dribbling bytes, but it cannot keep the header-read
     * deadline at bay forever.
     */
    public function noteWaitingForHeaders(): void
    {
        if ($this->waitingForHeadersSince === 0.0) {
            $this->waitingForHeadersSince = $this->clock->now();
        }
    }

    /**
     * A complete request was parsed — the header wait is over.
     */
    public function doneWaitingForHeaders(): void
    {
        $this->waitingForHeadersSince = 0.0;
    }

    /**
     * When the current header block started arriving, or 0.0 when the
     * connection is not mid-header.
     */
    public function waitingForHeadersSince(): float
    {
        return $this->waitingForHeadersSince;
    }

    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function appendRead(string $data): void
    {
        $this->readBuffer->append($data);
        $this->bytesRead += strlen($data);
        $this->lastActivityAt = $this->clock->now();
    }

    public function readBuffer(): ReadBuffer
    {
        return $this->readBuffer;
    }

    public function queueWrite(string $data): void
    {
        $this->assertNotClosed('queueWrite');
        $this->writeBuffer->append($data);
    }

    public function writeBuffer(): WriteBuffer
    {
        return $this->writeBuffer;
    }

    public function writeBufferLength(): int
    {
        return $this->writeBuffer->length();
    }

    /**
     * True when more than $bytes are queued for this connection — the
     * backpressure signal a slow client triggers (Phase 18).
     */
    public function hasBufferedMoreThan(int $bytes): bool
    {
        return $this->writeBuffer->length() > $bytes;
    }

    /**
     * Attempt a flush of the write buffer to the socket; bytes not accepted
     * stay queued for the next writable event.
     *
     * @param resource $stream
     */
    public function flushWrite(mixed $stream): int
    {
        $written = $this->writeBuffer->flushTo($stream);

        $this->bytesWritten += $written;
        $this->lastActivityAt = $this->clock->now();

        return $written;
    }

    public function isClosed(): bool
    {
        return $this->state === ConnectionState::CLOSED;
    }

    private function assertState(ConnectionState $expected, string $action): void
    {
        if ($this->state !== $expected) {
            throw new ConnectionException(sprintf(
                'Cannot %s connection #%d in state %s (expected %s).',
                $action,
                $this->id,
                $this->state->value,
                $expected->value,
            ));
        }
    }

    private function moveTo(ConnectionState $state, string $action): void
    {
        $this->assertNotClosed($action);
        $this->state = $state;
    }

    private function assertNotClosed(string $action): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            throw new ConnectionException(sprintf(
                'Cannot %s: connection #%d is closed.',
                $action,
                $this->id,
            ));
        }
    }
}
