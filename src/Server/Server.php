<?php

declare(strict_types=1);

namespace App\Server;

use App\Connection\Connection;

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

    private ServerState $state = ServerState::STOPPED;

    public function __construct(
        private readonly ServerConfig $config,
    ) {
    }

    public function start(): void
    {
        $address = sprintf('tcp://%s:%d', $this->config->host, $this->config->port);

        $socket = @stream_socket_server(
            $address,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
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
     * @return Connection|null the new connection, or null when nothing is pending
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

        stream_set_blocking($client, false);

        $id = $this->nextConnectionId++;
        $connection = Connection::accepted($id, $client, (string) $peer);
        $connection->connect();

        $this->connections[$id] = $connection;

        return $connection;
    }

    public function close(Connection $connection): void
    {
        $connection->close();
        unset($this->connections[$connection->id]);
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

    public function stop(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];

        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }

        $this->state = ServerState::STOPPED;
    }

    public function isRunning(): bool
    {
        return $this->state === ServerState::RUNNING;
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
}