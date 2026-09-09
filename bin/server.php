<?php

declare(strict_types=1);

use App\Connection\Connection;
use App\EventLoop\SelectLoop;
use App\Http\Handler\HelloHandler;
use App\Http\Handler\RequestHandler;
use App\Http\Middleware\ErrorHandlerMiddleware;
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
 * Phases 5-14: raw TCP bytes become HttpRequest objects, the router picks
 * the handler, and the response is flushed through the WriteBuffer. After a
 * full response the connection either goes back to reading (keep-alive) or
 * closes, so many requests reuse one TCP connection.
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
$router->get('/hello', (new HelloHandler())->handle(...));
$router->post('/users', static fn (HttpRequest $r): HttpResponse => ResponseFactory::json(
    ['received' => $r->body],
    HttpStatusCode::CREATED,
));
$router->get('/users/{id}', static fn (HttpRequest $r, array $params): HttpResponse => ResponseFactory::json([
    'id' => $params['id'],
]));

/**
 * Phase 11+13: the request travels through a middleware pipeline before the
 * router sees it. The logging middleware runs before and after the router;
 * the timing middleware stamps the response with how long the whole chain
 * took; the error handler turns exceptions into proper 400/404/405/500
 * responses instead of killing the process.
 */
$routerNotFound = new MiddlewarePipeline($router);

$routerNotFound->add(new ErrorHandlerMiddleware());

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

pcntl_async_signals(true);
pcntl_signal(SIGINT, static fn () => $loop->stop());
pcntl_signal(SIGTERM, static fn () => $loop->stop());

/**
 * Drain a connection's write buffer into its socket, waiting on writable
 * events for however long the socket needs, then run $onDrained once the
 * buffer is fully empty. Everything flows through the buffer so a socket
 * that accepts only part of a large response is handled the same way as a
 * socket that takes it all at once.
 *
 * @param Closure(): void|null $onDrained
 */
$drain = static function (SelectLoop $loop, Connection $connection, ?Closure $onDrained = null): void {
    $stream = $connection->socket();

    $loop->onWritable($stream, static function ($s) use ($loop, $connection, $onDrained): void {
        $connection->flushWrite($s);

        if ($connection->writeBuffer()->isEmpty()) {
            $loop->removeWritable($s);

            if ($onDrained !== null) {
                $onDrained();
            }
        }
    });
};

$loop->onReadable($server->socket(), static function ($stream) use ($loop, $server, $parser, $encoder, $routerNotFound, $drain): void {
    $connection = $server->accept();

    if ($connection === null) {
        return;
    }

    printf("[#%d] connected from %s\n", $connection->id, $connection->remoteAddress());

    $connection->startReading();

    $loop->onReadable($connection->socket(), static function ($stream) use ($loop, $server, $connection, $parser, $encoder, $routerNotFound, $drain): void {
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
            // Phase 13: malformed bytes answer 400 instead of killing the
            // connection without a word — but the socket is unusable after
            // a garbage request, so close it right after flushing.
            printf("[#%d] malformed request: %s\n", $connection->id, $e->getMessage());

            $response = ResponseFactory::text('Bad Request' . PHP_EOL, HttpStatusCode::BAD_REQUEST);

            $connection->startWriting();
            $connection->queueWrite($encoder->encode($response));
            $drain($loop, $connection, static function () use ($loop, $stream, $server, $connection): void {
                $loop->removeReadable($stream);
                $server->close($connection);
            });
            return;
        }

        if ($parsed === null) {
            return; // wait for more bytes
        }

        $connection->readBuffer()->consume($parsed->consumedBytes);

        $request = $parsed->request;

        $keepAlive = $request->wantsKeepAlive();
        $response = $routerNotFound->handle($request);
        $response->headers->set('Connection', $keepAlive ? 'keep-alive' : 'close');

        $connection->startWriting();
        $connection->queueWrite($encoder->encode($response));

        // Phase 14: after the response is fully written the connection is
        // free to read the next request (keep-alive) or must be torn down.
        $drain($loop, $connection, $keepAlive
            ? static function () use ($connection): void {
                $connection->backToReading();
            }
            : static function () use ($loop, $stream, $server, $connection): void {
                $loop->removeReadable($stream);
                $server->close($connection);
            });
    });
});

printf("Listening on tcp://%s:%d\n", $server->getHost(), $server->getPort());
printf("State: %s\n", $server->state()->value);

$loop->run();

$server->stop();
printf("Shutdown complete.\n");