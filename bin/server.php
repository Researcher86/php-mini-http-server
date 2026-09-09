<?php

declare(strict_types=1);

use App\Connection\Connection;
use App\EventLoop\SelectLoop;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\MalformedRequestException;
use App\Http\Protocol\ResponseEncoder;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;
use App\Http\Response\HttpStatusCode;
use App\Http\Response\ResponseFactory;
use App\Router\RouteNotFoundException;
use App\Router\Router;
use App\Server\Server;
use App\Server\ServerConfig;
use App\Server\ServerStartException;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Phases 5-9: raw TCP bytes become HttpRequest objects, the router picks the
 * handler, and the response is queued and flushed through the connection's
 * WriteBuffer, surviving partial socket writes.
 *
 * A request is parsed out of the read buffer, the matching response is
 * queued, and a writable watcher drains the write buffer until it is empty.
 * This is the write path every later phase (keep-alive, backpressure) builds
 * on. Responses that happen to be small are still routed through the same
 * buffer so one code path serves them all.
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
$router = new Router();
$loop = new SelectLoop();

$router->get('/', static fn (): HttpResponse => ResponseFactory::text('Hello, world!' . PHP_EOL));
$router->get('/hello', static fn (HttpRequest $r): HttpResponse => ResponseFactory::text(
    sprintf("Hello, %s!\n", $r->query()['name'] ?? 'world'),
));
$router->post('/users', static fn (HttpRequest $r): HttpResponse => ResponseFactory::json(
    ['received' => $r->body],
    HttpStatusCode::CREATED,
));

pcntl_async_signals(true);
pcntl_signal(SIGINT, static fn () => $loop->stop());
pcntl_signal(SIGTERM, static fn () => $loop->stop());

/**
 * Drain a connection's write buffer into its socket until it is empty, then
 * stop watching for writable events. Everything flows through the buffer so
 * a socket that accepts only part of a large response is handled the same
 * way as a socket that takes it all at once.
 */
$flush = static function (SelectLoop $loop, Connection $connection): void {
    $stream = $connection->socket();
    $written = $connection->flushWrite($stream);

    if ($written <= 0 || $connection->writeBuffer()->isEmpty()) {
        $loop->removeWritable($stream);
    }
};

$loop->onReadable($server->socket(), static function ($stream) use ($loop, $server, $parser, $encoder, $router, $flush): void {
    $connection = $server->accept();

    if ($connection === null) {
        return;
    }

    printf("[#%d] connected from %s\n", $connection->id, $connection->remoteAddress());

    $connection->startReading();

    $loop->onReadable($connection->socket(), static function ($stream) use ($loop, $server, $connection, $parser, $encoder, $router, $flush): void {
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

        try {
            $response = $router->dispatch($request);
        } catch (RouteNotFoundException) {
            $response = ResponseFactory::text('Not Found' . PHP_EOL, HttpStatusCode::NOT_FOUND);
        }

        $connection->startWriting();
        $connection->queueWrite($encoder->encode($response));
        $flush($loop, $connection);

        $loop->removeReadable($stream);
        $server->close($connection);
    });
});

printf("Listening on tcp://%s:%d\n", $server->getHost(), $server->getPort());
printf("State: %s\n", $server->state()->value);

$loop->run();

$server->stop();
printf("Shutdown complete.\n");