<?php

declare(strict_types=1);

use App\EventLoop\SelectLoop;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\MalformedRequestException;
use App\Http\Protocol\ResponseEncoder;
use App\Http\Response\ResponseFactory;
use App\Server\Server;
use App\Server\ServerConfig;
use App\Server\ServerStartException;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Phase 5: raw TCP bytes become HttpRequest objects.
 *
 * Every client socket stays in the read phase until its read buffer holds a
 * complete request (the parser says so), then a text summary is echoed back
 * and the connection closes. Partial and pipelined bytes are handled by the
 * read buffer + parser, not by hand.
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

$parser = new HttpParser();
$encoder = new ResponseEncoder();
$loop = new SelectLoop();

pcntl_async_signals(true);
pcntl_signal(SIGINT, static fn () => $loop->stop());
pcntl_signal(SIGTERM, static fn () => $loop->stop());

$loop->onReadable($server->socket(), static function ($stream) use ($loop, $server, $parser, $encoder): void {
    $connection = $server->accept();

    if ($connection === null) {
        return;
    }

    printf("[#%d] connected from %s\n", $connection->id, $connection->remoteAddress());

    $connection->startReading();

    $loop->onReadable($connection->socket(), static function ($stream) use ($loop, $server, $connection, $parser, $encoder): void {
        $data = fread($stream, 8192);

        if ($data === false || $data === '') { // EOF → client is gone
            $loop->removeReadable($stream);
            $server->close($connection);
            return;
        }

        $connection->appendRead($data);

        try {
            $parsed = $parser->parse((string) $connection->readBuffer());
        } catch (MalformedRequestException $e) {
            printf("[#%d] malformed request: %s\n", $connection->id, $e->getMessage());
            $loop->removeReadable($stream);
            $server->close($connection);
            return;
        }

        if ($parsed === null) {
            return; // wait for more bytes
        }

        $connection->readBuffer()->consume($parsed->consumedBytes);

        $request = $parsed->request;
        printf("[#%d] %s %s\n", $connection->id, $request->method->value, $request->target);

        $response = ResponseFactory::text(sprintf(
            "Parsed %s %s (headers: %d, body: %d bytes)\n",
            $request->method->value,
            $request->target,
            $request->headers->count(),
            strlen($request->body),
        ));

        $connection->startWriting();
        fwrite($stream, $encoder->encode($response));

        $loop->removeReadable($stream);
        $server->close($connection);
    });
});

printf("Listening on tcp://%s:%d\n", $server->getHost(), $server->getPort());
printf("State: %s\n", $server->state()->value);

$loop->run();

$server->stop();
printf("Shutdown complete.\n");