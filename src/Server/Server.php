<?php

declare(strict_types=1);

namespace App\Server;

use App\Connection\Connection;
use App\Support\Clock;
use App\Support\SystemClock;

/**
 * A minimal TCP server owning a single listening socket.
 *
 * Lifecycle is deliberately explicit:
 *
 *     start()  →  accept()...  →  stop()
 *
 * The listening socket is created non-blocking so a later phase can hand
 * it to the Event Loop and let stream_select() do the waiting instead of
 * blocking accept(). Until then accept() returns null when no client is
 * waiting to be connected.
 *
 * Accepted sockets are wrapped in Connections and tracked by id, so the
 * server can tell how many clients are alive and close them on stop().
 */
final class Server
{
    /** @var resource|null */
    private $socket = null;

    /** @var array<int, Connection> keyed by connection id */
    private array $connections = [];

    private int $nextConnectionId = 1;

    private int $refusedConnections = 0;

    private ServerState $state = ServerState::STOPPED;

    public function __construct(
        private readonly ServerConfig $config,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function start(): void
    {
        $address = sprintf('tcp://%s:%d', $this->config->host, $this->config->port);

        // The backlog config is honoured through the socket context: without
        // it the kernel default (often small) applies, which would drop
        // pending connections under a connection burst.
        $context = stream_context_create([
            'socket' => ['backlog' => $this->config->backlog],
        ]);

        $socket = @stream_socket_server(
            $address,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($socket === false) {
            throw new ServerStartException(sprintf(
                'Cannot start server on %s: %s (%d)',
                $address,
                $errstr !== '' ? $errstr : 'unknown error',
                $errno,
            ));
        }

        stream_set_blocking($socket, false);

        $this->socket = $socket;
        $this->state = ServerState::RUNNING;
    }

    /**
     * Accept one pending client connection and wrap it in a Connection.
     *
     * @return Connection|null the new connection, or null when nothing is
     *                         pending or the connection ceiling is reached
     */
    public function accept(): ?Connection
    {
        if ($this->socket === null) {
            throw new ServerStartException('Cannot accept: server is not started.');
        }

        $client = @stream_socket_accept($this->socket, 0, $peer);

        if ($client === false) {
            return null;
        }

        // At the ceiling the connection is accepted and immediately closed,
        // rather than left waiting in the backlog. The client learns at
        // once that it will not be served; leaving it queued would look
        // like a server that is merely slow, and it would stay queued until
        // its own timeout. See ServerConfig on why there is a ceiling.
        if (count($this->connections) >= $this->config->maxConnections) {
            fclose($client);
            $this->refusedConnections++;

            return null;
        }

        stream_set_blocking($client, false);

        $id = $this->nextConnectionId++;
        $connection = Connection::accepted($id, $client, (string) $peer, $this->clock);
        $connection->connect();

        $this->connections[$id] = $connection;

        return $connection;
    }

    /**
     * Close every connection that has been idle longer than $idleSeconds.
     *
     * The periodic connection sweep (Phase 17) calls this on a timer, so a
     * client that connects and sends nothing — or goes quiet mid-request —
     * is eventually reclaimed instead of holding a socket forever.
     *
     * @return list<Connection> the connections that were closed
     */
    public function closeIdleConnections(float $idleSeconds): array
    {
        $now = $this->clock->now();
        $closed = [];

        foreach ($this->connections as $connection) {
            if ($now - $connection->lastActivityAt() > $idleSeconds) {
                $this->close($connection);
                $closed[] = $connection;
            }
        }

        return $closed;
    }

    /**
     * Close every connection whose request headers have been arriving too
     * slowly to ever complete — the Slowloris guard.
     *
     * A trickling client stays alive under the idle sweep (it sends bytes),
     * but it never finishes a header block; this sweep bounds how long a
     * connection may sit mid-header.
     *
     * @return list<Connection> the connections that were closed
     */
    public function closeSlowHeaderReads(float $timeoutSeconds): array
    {
        $now = $this->clock->now();
        $closed = [];

        foreach ($this->connections as $connection) {
            $since = $connection->waitingForHeadersSince();

            if ($since > 0.0 && $now - $since > $timeoutSeconds) {
                $this->close($connection);
                $closed[] = $connection;
            }
        }

        return $closed;
    }

    public function close(Connection $connection): void
    {
        $connection->close();
        unset($this->connections[$connection->id]);
    }

    /**
     * Close every connection currently at rest — no request bytes mid-parse
     * (read buffer empty, not waiting for a header block), nothing waiting
     * to flush, no response mid-write. During DRAINING such a connection is
     * keep-alive between requests, and it will never be allowed to start
     * another one, so reaping it immediately makes shutdown fast and
     * predictable instead of waiting out the idle timeout.
     *
     * @return list<Connection> the connections that were closed
     */
    public function closeRestingConnections(): array
    {
        $closed = [];

        foreach ($this->connections as $connection) {
            if ($connection->writeBuffer()->isEmpty()
                && $connection->readBuffer()->isEmpty()
                && $connection->waitingForHeadersSince() === 0.0) {
                $this->close($connection);
                $closed[] = $connection;
            }
        }

        return $closed;
    }

    /**
     * @return list<Connection>
     */
    public function connections(): array
    {
        return array_values($this->connections);
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * How many connections have been turned away at the ceiling. A number
     * that is not zero means the server is smaller than its traffic.
     */
    public function refusedConnections(): int
    {
        return $this->refusedConnections;
    }

    /**
     * Graceful shutdown step one: RUNNING → DRAINING.
     *
     * The listening socket closes so no new clients can connect, but the
     * connections already accepted stay alive — in-flight requests finish,
     * responses flush, and only then does the caller call finish().
     */
    public function drain(): void
    {
        if ($this->state !== ServerState::RUNNING) {
            return;
        }

        $this->closeListeningSocket();

        $this->state = ServerState::DRAINING;
    }

    /**
     * Graceful shutdown step two: close whatever connections are still open
     * and land in STOPPED via FINISHING.
     */
    public function finish(): void
    {
        $this->state = ServerState::FINISHING;

        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
        $this->state = ServerState::STOPPED;
    }

    /**
     * Stop at once: the abrupt shutdown, with none of drain()'s waiting.
     * Tests and demo scripts use it to put a server down in one call.
     */
    public function stop(): void
    {
        $this->closeListeningSocket();
        $this->finish();
    }

    private function closeListeningSocket(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function isRunning(): bool
    {
        return $this->state === ServerState::RUNNING;
    }

    /**
     * Whether the server has started a graceful shutdown yet.
     *
     * Once true, established keep-alive connections must not start new
     * requests — ConnectionHandler consults this before handling the next
     * parsed request and refuses it with 503 + close.
     */
    public function isDraining(): bool
    {
        return $this->state === ServerState::DRAINING;
    }

    public function state(): ServerState
    {
        return $this->state;
    }

    /**
     * The actual bound port. Meaningful after start(), and the only way to
     * learn the OS-assigned port when ServerConfig was created with port 0.
     */
    public function getPort(): int
    {
        if ($this->socket === null) {
            return $this->config->port;
        }

        $name = stream_socket_get_name($this->socket, false);

        if ($name === false) {
            return $this->config->port;
        }

        $colon = strrpos($name, ':');

        if ($colon === false) {
            return $this->config->port;
        }

        return (int) substr($name, $colon + 1);
    }

    public function getHost(): string
    {
        return $this->config->host;
    }

    public function config(): ServerConfig
    {
        return $this->config;
    }

    /**
     * The listening socket, for registering the server with an Event Loop.
     *
     * @return resource
     */
    public function socket(): mixed
    {
        if ($this->socket === null) {
            throw new ServerStartException('Server has no socket: not started.');
        }

        return $this->socket;
    }
}
