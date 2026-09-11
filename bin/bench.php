<?php

declare(strict_types=1);

require __DIR__ . '/../benchmarks/bootstrap.php';

/**
 * Phase 21: measure the server, event-loop style.
 *
 * The question here is concurrency: how throughput and latency move as the
 * number of simultaneous connections grows. A forked child runs the real
 * server — benchmarks/bootstrap.php, which every benchmark shares, so they
 * all measure the same thing — and this process forks a configurable
 * number of blocking keep-alive clients, each firing a fixed number of
 * sequential requests and recording per-request latency. The results are
 * aggregated into requests per second, mean latency, p50/p95/p99 and peak
 * memory.
 *
 * The other load questions — what pipelining buys, what a connection costs
 * to set up, what it costs in memory — have a script each under
 * benchmarks/.
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

[$port, $serverPid] = benchServer();

printf("concurrency  requests  rps       mean ms   p50 ms    p95 ms    p99 ms    peak mem\n");

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
        benchFormatBytes($result['memory']),
    );
}

benchStop($serverPid);

// Measured in this (driver) process. What a connection costs the SERVER is
// a different question, and benchmarks/memory.php asks the server itself.
printf("\nPeak driver memory: %s\n", benchFormatBytes($memoryPeak));
printf("Done.\n");

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

    return [
        'requests' => $total,
        'rps' => $total / max($elapsed, 0.0001),
        'mean' => $total > 0 ? array_sum($latencies) / $total : 0.0,
        'p50' => benchPercentile($latencies, 50),
        'p95' => benchPercentile($latencies, 95),
        'p99' => benchPercentile($latencies, 99),
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

    $socket = benchConnect($port);
    $request = benchRequestBytes('/hello');
    $lines = [];

    for ($i = 0; $i < $requests; $i++) {
        $start = hrtime(true);

        fwrite($socket, $request);

        // benchReadResponse() stops on this response's last byte and never
        // over-reads into the next one, which matters here: the next thing
        // this loop times is exactly that next response.
        if (!benchReadResponse($socket)) {
            fwrite(STDERR, "bench client lost the connection\n");

            exit(1);
        }

        $lines[] = (string) ((hrtime(true) - $start) / 1e6);
    }

    fclose($socket);
    file_put_contents(latenciesFile((int) getmypid()), implode("\n", $lines) . "\n");

    exit(0);
}

/**
 * Temp file a worker writes its latencies to. Reuses the worker's id so the
 * parent can read it back after waitpid.
 */
function latenciesFile(int $id): string
{
    return sys_get_temp_dir() . "/bench-$id.lat";
}
