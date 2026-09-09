<?php

declare(strict_types=1);

use App\Connection\Connection;
use App\EventLoop\SelectLoop;
use App\Http\Handler\RequestHandler;
use App\Http\Middleware\MiddlewareInterface;
use App\Http\Middleware\MiddlewarePipeline;
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
$router->get('/users/{id}', static fn (HttpRequest $r, array $params): HttpResponse => ResponseFactory::json([
    'id' => $params['id'],
]));

/**
 * Phase 11: the request travels through a middleware pipeline before the
 * router sees it. The logging middleware runs before and after the router;
 * the timing middleware stamps the response with how long the whole chain
 * took, and turns missing routes into 404 responses on the way back out.
 */
$routerNotFound = new MiddlewarePipeline($router);

$routerNotFound->add(new class implements MiddlewareInterface {
    public function process(HttpRequest $request, RequestHandler $next): HttpResponse
    {
        printf("[%s %s] start\n", $request->method->value, $request->target);
        $response = $next->handle($request);
        printf("[%s %s] %d\n", $request->method->value, $request->target, $response->statusCode());

        return $response;
    }
});

$routerNotFound->add(new class implements MiddlewareInterface {
    public function process(HttpRequest $request, RequestHandler $next): HttpResponse
    {
        $started = microtime(true);
        $response = $next->handle($request);
        $response->headers->set('X-Response-Time', sprintf('%.4f', microtime(true) - $started));

        return $response;
    }
});

$routerNotFound->add(new class implements MiddlewareInterface {
    public function process(HttpRequest $request, RequestHandler $next): HttpResponse
    {
        try {
            return $next->handle($request);
        } catch (RouteNotFoundException) {
            return ResponseFactory::text('Not Found' . PHP_EOL, HttpStatusCode::NOT_FOUND);
        }
    }
});

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

$loop->onReadable($server->socket(), static function ($stream) use ($loop, $server, $parser, $encoder, $routerNotFound, $flush): void {
    $connection = $server->accept();

    if ($connection === null) {
        return;
    }

    printf("[#%d] connected from %s\n", $connection->id, $connection->remoteAddress());

    $connection->startReading();

    $loop->onReadable($connection->socket(), static function ($stream) use ($loop, $server, $connection, $parser, $encoder, $routerNotFound, $flush): void {
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

        $response = $routerNotFound->handle($request);

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