<?php

declare(strict_types=1);

use App\EventLoop\SelectLoop;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\ResponseEncoder;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;
use App\Http\Response\ResponseFactory;
use App\Metrics\ServerMetrics;
use App\Router\Router;
use App\Server\ConnectionHandler;
use App\Server\Server;
use App\Server\ServerConfig;
use App\Support\NullLogger;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Phase 21: measure the server, event-loop style.
 *
 * A forked child runs the real server (same bin/server.php pipeline, one
 * event loop, N connections). The parent forks a configurable number of
 * blocking keep-alive clients, each firing a fixed number of sequential
 * requests and recording per-request latency. The parent aggregates:
 * requests per second, mean latency, p50/p95/p99 and peak memory.
 *
 * Usage:
 *     php bin/bench.php                # 1, 10, 100, 1000 connections
 *     php bin/bench.php 100 50         # one level, custom requests each
 */

$levels = [1, 10, 100, 1000];
$requestsPerConnection = 50;

if (isset($argv[1])) {
    $levels = [(int) $argv[1]];
}

if (isset($argv[2])) {
    $requestsPerConnection = (int) $argv[2];
}

[$parentPipe, $childPipe] = serverPair();

$serverPid = pcntl_fork();

if ($serverPid === 0) {
    runServer($parentPipe, $childPipe);
}

fclose($childPipe);
$port = (int) trim((string) fgets($parentPipe));
fclose($parentPipe);

printf(
    "concurrency  requests  rps       mean ms   p50 ms    p95 ms    p99 ms    peak mem\n",
);

$memoryPeak = 0;

foreach ($levels as $concurrency) {
    $result = runLevel($port, $concurrency, $requestsPerConnection);

    $memoryPeak = max($memoryPeak, $result['memory']);

    printf(
        "%4d       %7d   %7.1f   %8.3f   %7.3f   %7.3f   %7.3f   %s\n",
        $concurrency,
        $result['requests'],
        $result['rps'],
        $result['mean'],
        $result['p50'],
        $result['p95'],
        $result['p99'],
        formatBytes($result['memory']),
    );
}

posix_kill($serverPid, SIGTERM);
pcntl_waitpid($serverPid, $status);

// measured in this (driver) process: the forked server's memory is not
// visible from here, so this is honest reporting of what can be measured.
printf("\nPeak driver memory: %s\n", formatBytes($memoryPeak));
printf("Done.\n");

/**
 * The forked server process: same pipeline as bin/server.php minus the
 * demo noise, kept alive until the parent kills it.
 */
function runServer(mixed $parentPipe, mixed $childPipe): never
{
    fclose($parentPipe);

    $server = new Server(new ServerConfig(host: '127.0.0.1', port: 0));
    $server->start();

    $parser = new HttpParser();
    $encoder = new ResponseEncoder();
    $router = new Router();
    $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello, world!'));
    $pipeline = new MiddlewarePipeline($router);
    $pipeline->add(new ErrorHandlerMiddleware());

    $metrics = new ServerMetrics();
    $loop = new SelectLoop();

    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static fn () => $loop->stop());

    // Each accepted connection runs the same ConnectionHandler state machine
    // as bin/server.php, so the benchmark measures the real pipeline.
    $loop->onReadable($server->socket(), static function ($stream) use ($loop, $server, $parser, $encoder, $pipeline, $metrics): void {
        $connection = $server->accept();

        if ($connection === null) {
            return;
        }

        (new ConnectionHandler(
            loop: $loop,
            server: $server,
            connection: $connection,
            parser: $parser,
            application: $pipeline,
            encoder: $encoder,
            metrics: $metrics,
            logger: new NullLogger(),
        ))->start();
    });

    fwrite($childPipe, (string) $server->getPort() . "\n");
    fclose($childPipe);

    $loop->run();
    $server->stop();

    exit(0);
}

/**
 * Fork $concurrency client workers; each opens one keep-alive connection and
 * fires $requests sequential requests, writing latencies to its own temp
 * file. Collect, aggregate and report.
 *
 * @return array{requests: int, rps: float, mean: float, p50: float, p95: float, p99: float, memory: int}
 */
function runLevel(int $port, int $concurrency, int $requests): array
{
    $workers = [];

    // A start gate: children wait for this file, so every worker begins at
    // roughly the same instant and the elapsed time measures the request
    // phase alone, not the (serial) fork storm.
    $goFile = sys_get_temp_dir() . '/bench-go-' . getmypid() . '.go';
    @unlink($goFile);

    for ($i = 0; $i < $concurrency; $i++) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            runClient($port, $requests, $goFile);
        }

        $workers[] = $pid;
    }

    // Durations throughout the benchmark are measured with hrtime(), which
    // is monotonic: an NTP correction mid-run would otherwise show up as a
    // negative latency or a nonsense rps.
    $started = hrtime(true);
    file_put_contents($goFile, 'go');

    $latencies = [];

    foreach ($workers as $pid) {
        pcntl_waitpid($pid, $status);
        $file = latenciesFile($pid);

        if (is_file($file)) {
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                if ($line !== '') {
                    $latencies[] = (float) $line;
                }
            }
            unlink($file);
        }
    }

    @unlink($goFile);
    $elapsed = (hrtime(true) - $started) / 1e9;
    $total = count($latencies);

    sort($latencies);

    $pct = static function (float $q) use ($latencies, $total): float {
        if ($total === 0) {
            return 0.0;
        }

        $index = (int) ceil($q / 100 * $total) - 1;

        return $latencies[max(0, $index)];
    };

    return [
        'requests' => $total,
        'rps' => $total / max($elapsed, 0.0001),
        'mean' => $total > 0 ? array_sum($latencies) / $total : 0.0,
        'p50' => $pct(50),
        'p95' => $pct(95),
        'p99' => $pct(99),
        'memory' => memory_get_peak_usage(true),
    ];
}

/**
 * One blocking keep-alive client: $requests sequential GETs, each timed.
 * Waits on the start gate so all workers begin together.
 */
function runClient(int $port, int $requests, string $goFile): never
{
    while (!file_exists($goFile)) {
        usleep(1000);
    }

    $socket = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 10);

    if ($socket === false) {
        fwrite(STDERR, "bench client failed: $errstr\n");
        exit(1);
    }

    $lines = [];

    for ($i = 0; $i < $requests; $i++) {
        $start = hrtime(true);

        fwrite($socket, "GET /hello HTTP/1.1\r\nHost: bench\r\n\r\n");

        // Read one complete response: head through the blank line, then
        // exactly Content-Length body bytes. Never over-read — the next
        // request's response must be the next thing we time.
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $head .= fread($socket, 8192);
        }

        $headerEnd = strpos($head, "\r\n\r\n") + 4;
        $length = 0;

        if (preg_match('/^Content-Length:\s*(\d+)$/mi', substr($head, 0, $headerEnd), $m) === 1) {
            $length = (int) $m[1];
        }

        $body = substr($head, $headerEnd);

        while (strlen($body) < $length) {
            $body .= fread($socket, 8192);
        }

        $lines[] = (string) ((hrtime(true) - $start) / 1e6);
    }

    fclose($socket);
    file_put_contents(latenciesFile((int) getmypid()), implode("\n", $lines) . "\n");
    exit(0);
}

/**
 * A guaranteed-open socket pair, or the process cannot measure anything.
 *
 * @return array{0: resource, 1: resource}
 */
function serverPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($pair === false) {
        fwrite(STDERR, "cannot create socket pair\n");
        exit(1);
    }

    return [$pair[0], $pair[1]];
}

/**
 * Temp file a worker writes its latencies to. Reuses the worker's id so the
 * parent can read it back after waitpid.
 */
function latenciesFile(int $id): string
{
    return sys_get_temp_dir() . "/bench-$id.lat";
}

function formatBytes(int $bytes): string
{
    return $bytes < 1024 * 1024
        ? sprintf('%.1f KB', $bytes / 1024)
        : sprintf('%.1f MB', $bytes / (1024 * 1024));
}