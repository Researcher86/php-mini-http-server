<?php

declare(strict_types=1);

use PhpMiniHttpServer\EventLoop\SelectLoop;
use PhpMiniHttpServer\Http\Handler\HelloHandler;
use PhpMiniHttpServer\Http\Handler\RequestHandler;
use PhpMiniHttpServer\Http\Middleware\ErrorHandlerMiddleware;
use PhpMiniHttpServer\Http\Middleware\LoggingMiddleware;
use PhpMiniHttpServer\Http\Middleware\MiddlewareInterface;
use PhpMiniHttpServer\Http\Middleware\MiddlewarePipeline;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\HttpStatusCode;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PhpMiniHttpServer\Metrics\ServerMetrics;
use PhpMiniHttpServer\Router\Router;
use PhpMiniHttpServer\Server\ConnectionHandler;
use PhpMiniHttpServer\Server\GracefulShutdown;
use PhpMiniHttpServer\Server\Server;
use PhpMiniHttpServer\Server\ServerConfig;
use PhpMiniHttpServer\Server\ServerStartException;
use PhpMiniHttpServer\Support\StderrLogger;

require __DIR__ . '/bootstrap.php';

/**
 * Phases 5-16: raw TCP bytes become HttpRequest objects, the router picks
 * the handler, and responses flush through the WriteBuffer. One read may
 * carry several pipelined requests — each is parsed and answered in order —
 * and a periodic timer runs scheduled work between the read/write events.
 */
$host = getenv('HTTP_SERVER_HOST') ?: '127.0.0.1';
$port = (int) (getenv('HTTP_SERVER_PORT') ?: '8080');

// Short timeouts for the demo: a connection that goes quiet for 5 seconds
// is reclaimed by the periodic sweep, and a shutdown waits at most 10
// seconds for in-flight requests before closing what is left.
$config = new ServerConfig(
    host: $host,
    port: $port,
    connectionTimeout: 5.0,
    headerTimeout: 5.0,
    drainTimeout: 10.0,
);
$server = new Server($config);

try {
    $server->start();
} catch (ServerStartException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

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
$router->get('/metrics', static function () use ($metrics, $server, $loop): HttpResponse {
    $loopMetrics = $loop->metrics();

    return ResponseFactory::text(sprintf(
        "active_connections %d\n"
        . "total_requests %d\n"
        . "requests_per_second %.2f\n"
        . "bytes_read %d\n"
        . "bytes_written %d\n"
        . "avg_request_duration_ms %.3f\n"
        . "uptime_seconds %.1f\n"
        // The loop's own numbers. max_lag is the worst delay any other
        // connection could have suffered waiting for its turn, so it is
        // where a slow handler shows up — the request counters above would
        // look perfectly healthy while it happened.
        . "loop_iterations %d\n"
        . "loop_busy_seconds %.3f\n"
        . "loop_idle_seconds %.3f\n"
        . "loop_utilisation %.3f\n"
        . "loop_max_lag_ms %.3f\n"
        . "refused_connections %d\n",
        $server->connectionCount(),
        $metrics->totalRequests(),
        $metrics->requestsPerSecond(),
        $metrics->bytesRead(),
        $metrics->bytesWritten(),
        $metrics->averageRequestDuration() * 1000,
        $metrics->uptimeSeconds(),
        $loopMetrics->iterations(),
        $loopMetrics->busySeconds(),
        $loopMetrics->idleSeconds(),
        $loopMetrics->utilisation(),
        $loopMetrics->maxLagSeconds() * 1000,
        $server->refusedConnections(),
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
        // hrtime: a duration, so a monotonic reading (see PhpMiniHttpServer\Support\Clock).
        $started = hrtime(true);
        $response = $next->handle($request);
        $response->headers->set('X-Response-Time', sprintf('%.4f', (hrtime(true) - $started) / 1e9));

        return $response;
    }
});

/**
 * Phase 19: RUNNING → DRAINING → FINISHING → STOPPED.
 *
 * First signal stops accepting new connections but lets active requests
 * finish and flush, for at most drainTimeout seconds. A second signal skips
 * the waiting and forces the end. The signal handlers only wake the loop;
 * the shutdown itself runs on the loop's turn — see GracefulShutdown.
 */
new GracefulShutdown($loop, $server, $logger)->listenForSignals(SIGTERM, SIGINT);

/**
 * Phase 16+17: scheduled work alongside read/write events. The heartbeat
 * tick keeps the loop awake, and the periodic sweep closes connections that
 * have been idle past the configured timeout, so dead clients do not hold a
 * socket forever.
 */
$loop->every(2.0, static function () use ($server, $logger): void {
    $logger->log(sprintf('tick: %d active connection(s)', $server->connectionCount()));
});

$loop->every(1.0, static function () use ($server, $logger): void {
    foreach ($server->closeIdleConnections($server->config()->connectionTimeout) as $connection) {
        $logger->log(sprintf('#%d closed: idle timeout', $connection->id));
    }

    // Slowloris guard: connections stuck mid-header past the header timeout
    // are reaped even though they keep dribbling bytes.
    foreach ($server->closeSlowHeaderReads($server->config()->headerTimeout) as $connection) {
        $logger->log(sprintf('#%d closed: header timeout', $connection->id));
    }
});

/**
 * Accept new clients and hand each connection its own ConnectionHandler —
 * the per-connection state machine that parses, routes and flushes (Phase
 * 18 backpressure, Phase 14 keep-alive, Phase 13 errors all live inside).
 */
ConnectionHandler::acceptOn($loop, $server, $application, $metrics, $logger);

printf("Listening on tcp://%s:%d\n", $server->getHost(), $server->getPort());
printf("State: %s\n", $server->state()->value);

$loop->run();

$server->stop();
printf("Shutdown complete.\n");
