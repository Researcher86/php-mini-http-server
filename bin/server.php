<?php

declare(strict_types=1);

use App\Server\Server;
use App\Server\ServerConfig;
use App\Server\ServerStartException;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Phase 1: a bare TCP server.
 *
 * Accepts connections and echoes the first line back, then closes.
 * Run with Ctrl+C / SIGTERM to stop.
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
    $client = $server->accept();

    if ($client === null) {
        usleep(10_000);
        continue;
    }

    $line = fgets($client);

    if ($line !== false) {
        fwrite($client, "echo: " . trim($line) . "\n");
    }

    fclose($client);
}