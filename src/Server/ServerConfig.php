<?php

declare(strict_types=1);

namespace App\Server;

/**
 * Immutable server settings.
 *
 * port 0 means "let the OS pick a free port" — the real value
 * can be read back via {@see Server::getPort()}, which makes
 * tests and examples independent of fixed port numbers.
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
    ) {
    }
}