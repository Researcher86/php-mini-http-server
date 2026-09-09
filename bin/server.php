<?php

declare(strict_types=1);

use App\Connection\Connection;
use App\EventLoop\SelectLoop;
use App\Http\Handler\HelloHandler;
use App\Http\Handler\RequestHandler;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\LoggingMiddleware;
use App\Http\Middleware\MiddlewareInterface;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Protocol\BodyTooLargeException;
use App\Http\Protocol\HeaderTooLargeException;
use App\Http\Protocol\HttpMethod;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\MalformedRequestException;
use App\Http\Protocol\ResponseEncoder;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;
use App\Http\Response\HttpStatusCode;
use App\Http\Response\ResponseFactory;
use App\Metrics\ServerMetrics;
use App\Router\Router;
use App\Server\Server;
use App\Server\ServerConfig;
use App\Server\ServerStartException;
use App\Support\StderrLogger;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Phase 18: once a connection's queued responses exceed this many bytes we
 * stop reading from that client until the write buffer drains back down.
 */
const MAX_BUFFERED_RESPONSE_BYTES = 65536;

/**
 * Phases 5-16: raw TCP bytes become HttpRequest objects, the router picks
 * the handler, and responses flush through the WriteBuffer. One read may
 * carry several pipelined requests — each is parsed and answered in order —
 * and a periodic timer runs scheduled work between the read/write events.
 */
$host = getenv('HTTP_SERVER_HOST') ?: '127.0.0.1';
$port = (int) (getenv('HTTP_SERVER_PORT') ?: '8080');

// Short idle timeout for the demo: a connection that goes quiet for 5
// seconds is reclaimed by the periodic sweep.
$config = new ServerConfig(
    host: $host,
    port: $port,
    connectionTimeout: 5.0,
    headerTimeout: 5.0,
);
$server = new Server($config);

try {
    $server->start();
} catch (ServerStartException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$parser = new HttpParser($config->maxHeaderBytes, $config->maxBodyBytes);
$encoder = new ResponseEncoder();
$router = new Router();
$metrics = new ServerMetrics();
$logger = new StderrLogger();
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

// A deliberately large body so the Phase 18 backpressure demo has something
// to overflow the write buffer with.
$router->get('/big', static fn (): HttpResponse => ResponseFactory::text(str_repeat('x', 32_768)));

/**
 * Phase 20: observability. The /metrics route dumps the counters the server
 * has been feeding since it started.
 */
$router->get('/metrics', static function () use ($metrics, $server): HttpResponse {
    return ResponseFactory::text(sprintf(
        "active_connections %d\ntotal_requests %d\nrequests_per_second %.2f\nbytes_read %d\nbytes_written %d\navg_request_duration_ms %.3f\nuptime_seconds %.1f\n",
        $server->connectionCount(),
        $metrics->totalRequests(),
        $metrics->requestsPerSecond(),
        $metrics->bytesRead(),
        $metrics->bytesWritten(),
        $metrics->averageRequestDuration() * 1000,
        $metrics->uptimeSeconds(),
    ));
});

/**
 * Phase 11+13: the request travels through a middleware pipeline before the
 * router sees it. The logging middleware runs before and after the router;
 * the timing middleware stamps the response with how long the whole chain
 * took; the error handler turns exceptions into proper 400/404/405/500
 * responses instead of killing the process.
 */
$routerNotFound = new MiddlewarePipeline($router);

// Logging is outermost so it sees the final status even for requests that
// error — the error handler below converts the exception into a response
// before it bubbles back to the logger.
$routerNotFound->add(new LoggingMiddleware($logger));

$routerNotFound->add(new ErrorHandlerMiddleware());

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

/**
 * Phase 19: RUNNING → DRAINING → FINISHING → STOPPED.
 *
 * First signal stops accepting new connections but lets active requests
 * finish and flush. A second signal skips the waiting and forces the end.
 */
$listenStream = $server->socket();
$draining = false;

$onSignal = static function () use (&$draining, $loop, $server, $listenStream, $logger): void {
    if ($draining) {
        $logger->log('shutdown forced');
        $server->finish();
        $loop->stop();
        return;
    }

    $draining = true;
    $logger->log('shutdown draining: no new connections, finishing active requests');
    $loop->removeReadable($listenStream);
    $server->drain();
};

pcntl_signal(SIGINT, $onSignal);
pcntl_signal(SIGTERM, $onSignal);

/**
 * Phase 16+17: scheduled work alongside read/write events. The heartbeat
 * tick keeps the loop awake, and the periodic sweep closes connections that
 * have been idle past the configured timeout, so dead clients do not hold a
 * socket forever.
 */
$loop->every(2.0, static function () use ($server, $logger): void {
    $logger->log(sprintf('tick: %d active connection(s)', $server->connectionCount()));
});

$loop->every(1.0, static function () use (&$draining, $loop, $server, $logger): void {
    $closed = $server->closeIdleConnections($server->config()->connectionTimeout);

    foreach ($closed as $connection) {
        $logger->log(sprintf('#%d closed: idle timeout', $connection->id));
    }

    // Slowloris guard: connections stuck mid-header past the header timeout
    // are reaped even though they keep dribbling bytes.
    $slow = $server->closeSlowHeaderReads($server->config()->headerTimeout);

    foreach ($slow as $connection) {
        $logger->log(sprintf('#%d closed: header timeout', $connection->id));
    }

    // Phase 19: when draining and every connection is done, shut down.
    if ($draining && $server->connectionCount() === 0) {
        $logger->log('shutdown: all connections finished');
        $server->finish();
        $loop->stop();
    }
});

/**
 * Drain a connection's write buffer into its socket, waiting on writable
 * events for however long the socket needs, then run $onDrained once the
 * buffer is fully empty. Everything flows through the buffer so a socket
 * that accepts only part of a large response is handled the same way as a
 * socket that takes it all at once.
 *
 * @param Closure(): void|null $onDrained
 */
$drain = static function (SelectLoop $loop, Connection $connection, ?Closure $onDrained = null) use ($metrics): void {
    $stream = $connection->socket();

    $loop->onWritable($stream, static function ($s) use ($loop, $connection, $onDrained, $metrics): void {
        $metrics->recordBytesWritten($connection->flushWrite($s));

        if ($connection->writeBuffer()->isEmpty()) {
            $loop->removeWritable($s);

            if ($onDrained !== null) {
                $onDrained();
            }
        }
    });
};

$loop->onReadable($server->socket(), static function ($stream) use ($loop, $server, $parser, $encoder, $routerNotFound, $drain, $metrics, $logger): void {
    $connection = $server->accept();

    if ($connection === null) {
        return;
    }

    $logger->log(sprintf('#%d connected from %s', $connection->id, $connection->remoteAddress()));

    $connection->startReading();

    $onData = null;

    $onData = static function ($stream) use ($loop, $server, $connection, $parser, $encoder, $routerNotFound, $drain, $metrics, $logger, &$onData): void {
        $data = fread($stream, 8192);

        if ($data === false || $data === '') { // EOF → client is gone
            $loop->removeReadable($stream);
            $server->close($connection);
            return;
        }

        $connection->appendRead($data);
        $metrics->recordBytesRead(strlen($data));

        $closeAfterDrain = false;
        $queued = false;
        $paused = false;

        // Phase 15: one read may carry several pipelined requests. Keep
        // parsing while the buffer holds complete requests, queueing their
        // responses in order; stop only when a request says "close", there
        // is nothing complete left, or the write buffer hits the ceiling.
        while (true) {
            try {
                $parsed = $parser->parse((string) $connection->readBuffer());
            } catch (MalformedRequestException $e) {
                $logger->log(sprintf('#%d malformed request: %s', $connection->id, $e->getMessage()));

                $connection->queueWrite($encoder->encode(
                    ResponseFactory::text('Bad Request' . PHP_EOL, HttpStatusCode::BAD_REQUEST),
                ));
                $closeAfterDrain = true;
                $queued = true;
                break;
            } catch (HeaderTooLargeException $e) {
                $logger->log(sprintf('#%d header too large: %s', $connection->id, $e->getMessage()));

                $connection->queueWrite($encoder->encode(
                    ResponseFactory::text('Request Header Fields Too Large' . PHP_EOL, HttpStatusCode::HEADER_TOO_LARGE),
                ));
                $closeAfterDrain = true;
                $queued = true;
                break;
            } catch (BodyTooLargeException $e) {
                $logger->log(sprintf('#%d body too large: %s', $connection->id, $e->getMessage()));

                $connection->queueWrite($encoder->encode(
                    ResponseFactory::text('Payload Too Large' . PHP_EOL, HttpStatusCode::PAYLOAD_TOO_LARGE),
                ));
                $closeAfterDrain = true;
                $queued = true;
                break;
            }

            if ($parsed === null) {
                // A complete request has not arrived yet — the header clock
                // starts (or keeps running) so the Slowloris sweep can reap
                // a connection that never finishes its headers.
                $connection->noteWaitingForHeaders();
                break; // wait for more bytes
            }

            $connection->doneWaitingForHeaders();
            $connection->readBuffer()->consume($parsed->consumedBytes);

            $request = $parsed->request;
            $startedAt = microtime(true);

            $keepAlive = $request->wantsKeepAlive();
            $response = $routerNotFound->handle($request);

            // HEAD is GET without a body: keep the headers (Content-Length
            // reflects the would-be GET body) but drop the body itself.
            if ($request->method === HttpMethod::HEAD) {
                $response = new HttpResponse(
                    $response->version,
                    $response->status,
                    $response->headers,
                    '',
                );
            }

            $response->headers->set('Connection', $keepAlive ? 'keep-alive' : 'close');

            $metrics->recordRequest(microtime(true) - $startedAt);

            $connection->queueWrite($encoder->encode($response));
            $queued = true;

            if (!$keepAlive) {
                $closeAfterDrain = true;
                break;
            }

            // Phase 18: a slow client lets the write buffer grow. Past the
            // ceiling we stop pulling more requests off the socket — reading
            // pauses until the buffer drains below it, then resumes.
            if ($connection->hasBufferedMoreThan(MAX_BUFFERED_RESPONSE_BYTES)) {
                $paused = true;
                break;
            }
        }

        if (!$queued) {
            return; // nothing complete yet — wait for more bytes
        }

        $connection->startWriting();

        if ($paused) {
            // Backpressure: stop accepting more data until the write buffer
            // has drained, then re-arm the reader.
            $logger->log(sprintf('#%d write buffer at %d bytes — pausing reads', $connection->id, $connection->writeBufferLength()));
            $loop->removeReadable($stream);

            $drain($loop, $connection, static function () use ($loop, $stream, $connection, $logger, &$onData): void {
                $connection->backToReading();
                $loop->onReadable($stream, $onData);
                $logger->log(sprintf('#%d write buffer drained — resuming reads', $connection->id));
            });
            return;
        }

        // Phase 14+15: after the queued responses are fully written the
        // connection either goes back to reading (keep-alive) or closes.
        $drain($loop, $connection, $closeAfterDrain
            ? static function () use ($loop, $stream, $server, $connection): void {
                $loop->removeReadable($stream);
                $server->close($connection);
            }
            : static function () use ($connection): void {
                $connection->backToReading();
            });
    };

    $loop->onReadable($connection->socket(), $onData);
});

printf("Listening on tcp://%s:%d\n", $server->getHost(), $server->getPort());
printf("State: %s\n", $server->state()->value);

$loop->run();

$server->stop();
printf("Shutdown complete.\n");