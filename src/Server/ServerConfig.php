<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Server;

/**
 * Immutable server settings.
 *
 * port 0 means "let the OS pick a free port" — the real value
 * can be read back via {@see Server::getPort()}, which makes
 * tests and examples independent of fixed port numbers.
 *
 * maxConnections is a hard ceiling, and a low one on purpose. The event
 * loop waits with stream_select(), which cannot be given a descriptor
 * numbered at or above FD_SETSIZE — 1024 in a standard PHP build. Past
 * that it refuses the whole wait, not just the offending stream, and a
 * loop that cannot wait cannot serve anybody. So the server stops
 * accepting well before it gets there, and says so, rather than walking
 * into a limit it has no way to recover from.
 */
final readonly class ServerConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 8080,
        public int $backlog = 128,
        public float $connectionTimeout = 30.0,
        public float $headerTimeout = 5.0,
        public int $maxHeaderBytes = 8192,
        public int $maxBodyBytes = 1_048_576,
        public int $maxConnections = 512,
    ) {
    }
}
