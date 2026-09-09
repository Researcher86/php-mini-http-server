<?php

declare(strict_types=1);

use App\Server\Server;
use App\Server\ServerConfig;
use App\Server\ServerStartException;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Phase 2: the server wraps every accepted socket in a Connection,
 * which tracks state and metadata. Each incoming line is echoed back
 * once, then the connection closes.
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

printf("Listening on tcp://%s:%d\n", $server->getHost(), $server->getPort());
printf("State: %s\n", $server->state()->value);

while ($server->isRunning()) {
    $connection = $server->accept();

    if ($connection === null) {
        usleep(10_000);
        continue;
    }

    printf("[#%d] connected from %s\n", $connection->id, $connection->remoteAddress());

    $connection->startReading();
    $line = fgets($connection->socket());

    if ($line !== false) {
        $connection->startWriting();
        fwrite($connection->socket(), 'echo: ' . trim($line) . "\n");
    }

    $server->close($connection);
    printf("[#%d] closed\n", $connection->id);
}