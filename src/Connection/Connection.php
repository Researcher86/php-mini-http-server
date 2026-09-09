<?php

declare(strict_types=1);

namespace App\Connection;

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
 * Read and write buffers start as plain strings on purpose. Phase 4 turns
 * the read side into a ReadBuffer that answers "is a full request here?";
 * Phase 8 turns the write side into a WriteBuffer that survives partial
 * writes. Keeping them dumb here keeps each phase's lesson isolated.
 */
final class Connection
{
    private ConnectionState $state;

    private ReadBuffer $readBuffer;

    private WriteBuffer $writeBuffer;

    private int $bytesRead = 0;

    private int $bytesWritten = 0;

    private float $lastActivityAt;

    public function __construct(
        public readonly int $id,
        private readonly mixed $socket,
        private readonly string $remoteAddress,
        private readonly float $connectedAt,
    ) {
        $this->readBuffer = new ReadBuffer();
        $this->writeBuffer = new WriteBuffer();
        $this->state = ConnectionState::NEW;
        $this->lastActivityAt = $connectedAt;
    }

    /**
     * Wrap a freshly accepted socket in a Connection.
     *
     * @param resource $socket
     */
    public static function accepted(int $id, mixed $socket, string $remoteAddress, ?float $now = null): self
    {
        return new self($id, $socket, $remoteAddress, $now ?? microtime(true));
    }

    public function connect(): void
    {
        $this->assertState(ConnectionState::NEW, 'connect');
        $this->state = ConnectionState::CONNECTED;
    }

    public function startReading(): void
    {
        $this->assertNotClosed('startReading');
        $this->state = ConnectionState::READING;
    }

    public function startProcessing(): void
    {
        $this->assertNotClosed('startProcessing');
        $this->state = ConnectionState::PROCESSING;
    }

    public function startWriting(): void
    {
        $this->assertNotClosed('startWriting');
        $this->state = ConnectionState::WRITING;
    }

    /**
     * Back to READING without closing — the keep-alive path the lifecycle
     * diagram draws as WRITING → READING.
     */
    public function backToReading(): void
    {
        $this->assertNotClosed('backToReading');
        $this->state = ConnectionState::READING;
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
        $this->lastActivityAt = microtime(true);
    }

    public function readBuffer(): ReadBuffer
    {
        return $this->readBuffer;
    }

    /**
     * True when the accumulated bytes contain a full request header block.
     *
     * Header delimiter choice "\r\n\r\n" is HTTP's own; the parser will teach
     * the buffer to also respect body lengths in a later phase.
     */
    public function hasCompleteRequest(): bool
    {
        return $this->readBuffer->contains("\r\n\r\n");
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
     * Attempt a flush of the write buffer to the socket; bytes not accepted
     * stay queued for the next writable event.
     *
     * @param resource $stream
     */
    public function flushWrite(mixed $stream): int
    {
        $written = $this->writeBuffer->flushTo($stream);

        $this->bytesWritten += $written;
        $this->lastActivityAt = microtime(true);

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