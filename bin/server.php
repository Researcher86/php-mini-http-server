<?php

declare(strict_types=1);

use App\EventLoop\SelectLoop;
use App\Server\Server;
use App\Server\ServerConfig;
use App\Server\ServerStartException;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Phase 3: one event loop, many connections.
 *
 * The server socket and every accepted client socket are registered with a
 * single SelectLoop. No blocking accept(), no blocking fgets() — the loop
 * tells us when a listener has a connection pending and when a client sent
 * bytes.
 *
 * Each received line is echoed back, then the connection closes.
 * Ctrl+C / SIGTERM stops the loop and the server.
 */
$host = getenv('HTTP_SERVER_HOST') ?: '127.0.0.1';
$port = (int) (getenv('HTTP_SERVER_PORT') ?: '8080');

$server = new Server(new ServerConfig(host: $host, port: $port));

try {
    $server->start();
} catch (ServerStartException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$loop = new SelectLoop();

pcntl_async_signals(true);
pcntl_signal(SIGINT, static fn () => $loop->stop());
pcntl_signal(SIGTERM, static fn () => $loop->stop());

// New clients wake the loop through the listening socket.
$loop->onReadable($server->socket(), static function ($stream) use ($loop, $server): void {
    $connection = $server->accept();

    if ($connection === null) {
        return;
    }

    printf("[#%d] connected from %s\n", $connection->id, $connection->remoteAddress());

    $connection->startReading();

    $loop->onReadable($connection->socket(), static function ($stream) use ($loop, $server, $connection): void {
        $data = fread($stream, 8192);

        if ($data === false || $data === '') { // EOF or error → client is gone
            $loop->removeReadable($stream);
            $loop->removeWritable($stream);
            $server->close($connection);
            printf("[#%d] closed\n", $connection->id);
            return;
        }

        $connection->appendRead($data);
        $connection->startWriting();

        // A socket is usually writable immediately; writing in a dedicated
        // writable phase keeps the read phase free for other connections.
        $loop->onWritable($stream, static function ($stream) use ($loop, $server, $connection): void {
            fwrite($stream, 'echo: ' . trim((string) $connection->readBuffer()) . "\n");
            $loop->removeWritable($stream);
            $loop->removeReadable($stream);
            $server->close($connection);
            printf("[#%d] closed\n", $connection->id);
        });
    });
});

printf("Listening on tcp://%s:%d\n", $server->getHost(), $server->getPort());
printf("State: %s\n", $server->state()->value);

$loop->run();

$server->stop();
printf("Shutdown complete.\n");