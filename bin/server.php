<?php

declare(strict_types=1);

use App\EventLoop\SelectLoop;
use App\Http\Handler\HelloHandler;
use App\Http\Handler\RequestHandler;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\LoggingMiddleware;
use App\Http\Middleware\MiddlewareInterface;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\ResponseEncoder;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;
use App\Http\Response\HttpStatusCode;
use App\Http\Response\ResponseFactory;
use App\Metrics\ServerMetrics;
use App\Router\Router;
use App\Server\ConnectionHandler;
use App\Server\Server;
use App\Server\ServerConfig;
use App\Server\ServerStartException;
use App\Support\StderrLogger;

require __DIR__ . '/../vendor/autoload.php';

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
$application = new MiddlewarePipeline($router);

// Logging is outermost so it sees the final status even for requests that
// error — the error handler below converts the exception into a response
// before it bubbles back to the logger.
$application->add(new LoggingMiddleware($logger));
$application->add(new ErrorHandlerMiddleware());
$application->add(new class implements MiddlewareInterface {
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

// A keep-alive connection sitting between requests has nothing left to
// finish, and drain() guarantees it will never be allowed to start another
// one — so reap it now instead of waiting out the idle timeout. Called both
// the moment shutdown starts and on every tick after, because connections
// keep arriving at rest as their last response flushes.
$reapRestingConnections = static function () use ($server, $logger): void {
    foreach ($server->closeRestingConnections() as $connection) {
        $logger->log(sprintf('#%d closed: drain (no active request)', $connection->id));
    }
};

$onSignal = static function () use (&$draining, $loop, $server, $listenStream, $logger, $reapRestingConnections): void {
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

    $reapRestingConnections();
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

$loop->every(1.0, static function () use (&$draining, $loop, $server, $logger, $reapRestingConnections): void {
    // Phase 19: during drain, connections that answer a refused request
    // (503 + close) or flush a last in-flight response end up at rest here
    // and are reaped on this tick rather than on the next idle timeout.
    if ($draining) {
        $reapRestingConnections();
    }

    foreach ($server->closeIdleConnections($server->config()->connectionTimeout) as $connection) {
        $logger->log(sprintf('#%d closed: idle timeout', $connection->id));
    }

    // Slowloris guard: connections stuck mid-header past the header timeout
    // are reaped even though they keep dribbling bytes.
    foreach ($server->closeSlowHeaderReads($server->config()->headerTimeout) as $connection) {
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
 * Accept new clients and hand each connection its own ConnectionHandler —
 * the per-connection state machine that parses, routes and flushes (Phase
 * 18 backpressure, Phase 14 keep-alive, Phase 13 errors all live inside).
 */
$loop->onReadable($server->socket(), static function ($stream) use ($loop, $server, $parser, $encoder, $application, $metrics, $logger): void {
    $connection = $server->accept();

    if ($connection === null) {
        return;
    }

    $logger->log(sprintf('#%d connected from %s', $connection->id, $connection->remoteAddress()));

    (new ConnectionHandler(
        loop: $loop,
        server: $server,
        connection: $connection,
        parser: $parser,
        application: $application,
        encoder: $encoder,
        metrics: $metrics,
        logger: $logger,
    ))->start();
});

printf("Listening on tcp://%s:%d\n", $server->getHost(), $server->getPort());
printf("State: %s\n", $server->state()->value);

$loop->run();

$server->stop();
printf("Shutdown complete.\n");