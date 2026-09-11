<?php

declare(strict_types=1);

use App\EventLoop\SelectLoop;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\ResponseEncoder;
use App\Http\Response\HttpResponse;
use App\Http\Response\ResponseFactory;
use App\Metrics\ServerMetrics;
use App\Router\Router;
use App\Server\ConnectionHandler;
use App\Server\Server;
use App\Server\ServerConfig;
use App\Support\NullLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * The server every benchmark measures, defined once.
 *
 * It is deliberately not `make run-server`. That one logs every request to
 * stderr and stamps a timing header on it, which is the right thing for a
 * demo and the wrong thing to measure: the numbers would be about the
 * logger. This is the same runtime — same event loop, same parser, same
 * ConnectionHandler — with the demo's instrumentation left off.
 *
 * Forked, on an OS-assigned port, so a benchmark never fights `make
 * run-server` for 8080 and never depends on one being up.
 *
 * @return array{0: int, 1: int} the port it bound, and the child's pid
 */
function benchServer(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($pair === false) {
        fwrite(STDERR, "cannot create a socket pair to learn the port over\n");

        exit(1);
    }

    [$parentPipe, $childPipe] = $pair;
    $pid = pcntl_fork();

    if ($pid === -1) {
        fwrite(STDERR, "fork failed\n");

        exit(1);
    }

    if ($pid === 0) {
        fclose($parentPipe);
        benchRunServer($childPipe);
    }

    fclose($childPipe);
    $port = (int) trim((string) fgets($parentPipe));
    fclose($parentPipe);

    return [$port, $pid];
}

/**
 * The child half of {@see benchServer()}: bind, announce the port back up
 * the pipe, then serve until SIGTERM.
 *
 * @param resource $childPipe
 */
function benchRunServer(mixed $childPipe): never
{
    $server = new Server(new ServerConfig(host: '127.0.0.1', port: 0));
    $server->start();

    $router = new Router();
    $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello, world!'));
    $router->get('/big', static fn (): HttpResponse => ResponseFactory::text(str_repeat('x', 32_768)));

    // The server's own footprint, which no other process can read. A
    // benchmark that wants to know what a connection costs has to ask the
    // process that holds it.
    $router->get('/memory', static fn (): HttpResponse => ResponseFactory::text(
        memory_get_usage() . "\n",
    ));

    $application = new MiddlewarePipeline($router);
    $application->add(new ErrorHandlerMiddleware());

    $loop = new SelectLoop();
    $parser = new HttpParser();
    $encoder = new ResponseEncoder();
    $metrics = new ServerMetrics();

    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static fn () => $loop->stop());

    $loop->onReadable($server->socket(), static function () use ($loop, $server, $parser, $encoder, $application, $metrics): void {
        $connection = $server->accept();

        if ($connection === null) {
            return;
        }

        (new ConnectionHandler(
            loop: $loop,
            server: $server,
            connection: $connection,
            parser: $parser,
            application: $application,
            encoder: $encoder,
            metrics: $metrics,
            logger: new NullLogger(),
        ))->start();
    });

    fwrite($childPipe, $server->getPort() . "\n");
    fclose($childPipe);

    $loop->run();
    $server->stop();

    exit(0);
}

function benchStop(int $pid): void
{
    posix_kill($pid, SIGTERM);
    pcntl_waitpid($pid, $status);
}

/**
 * @return resource
 */
function benchConnect(int $port): mixed
{
    $stream = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 10.0);

    if ($stream === false) {
        fwrite(STDERR, sprintf("cannot reach the benchmark server: %s (%d)\n", $errstr, $errno));

        exit(1);
    }

    return $stream;
}

/**
 * Ask the benchmark server how much memory it is using right now.
 */
function benchServerMemory(int $port): int
{
    $client = benchConnect($port);
    fwrite($client, benchRequestBytes('/memory', close: true));

    $body = benchReadBody($client);
    fclose($client);

    return (int) trim($body);
}

/**
 * Read exactly one response and stop on its last byte.
 *
 * The head is taken one byte at a time on purpose: a bulk read would also
 * swallow the beginning of the next response, and on a pipelined
 * connection that is precisely what must not happen — the next timing
 * would start with bytes that had already arrived.
 *
 * @param resource $stream
 */
function benchReadResponse(mixed $stream): bool
{
    return benchReadBody($stream) !== null;
}

/**
 * The same read, when the body itself is wanted.
 *
 * @param resource $stream
 */
function benchReadBody(mixed $stream): ?string
{
    $head = '';

    while (!str_ends_with($head, "\r\n\r\n")) {
        $byte = fread($stream, 1);

        if ($byte === false || $byte === '') {
            return null;
        }

        $head .= $byte;
    }

    $length = 0;

    // The \r is matched explicitly: with /m, PCRE ends a line at \n, so a
    // pattern anchored with $ would refuse the CR that HTTP puts there.
    if (preg_match('/^Content-Length:[ \t]*(\d+)[ \t]*\r?$/mi', $head, $matches) === 1) {
        $length = (int) $matches[1];
    }

    $body = '';

    while (strlen($body) < $length) {
        $chunk = fread($stream, max(1, $length - strlen($body)));

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $body .= $chunk;
    }

    return $body;
}

function benchRequestBytes(string $path, bool $close = false): string
{
    return sprintf(
        "GET %s HTTP/1.1\r\nHost: bench\r\n%s\r\n",
        $path,
        $close ? "Connection: close\r\n" : '',
    );
}

/**
 * @param list<float> $values
 */
function benchPercentile(array $values, float $percentile): float
{
    if ($values === []) {
        return 0.0;
    }

    sort($values);
    $index = (int) ceil($percentile / 100 * count($values)) - 1;

    return $values[max(0, $index)];
}

function benchFormatBytes(int $bytes): string
{
    return $bytes < 1024 * 1024
        ? sprintf('%.1f KB', $bytes / 1024)
        : sprintf('%.1f MB', $bytes / (1024 * 1024));
}
