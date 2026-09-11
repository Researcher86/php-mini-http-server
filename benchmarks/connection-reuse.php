#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * What does keep-alive cost the server, and what does closing cost it?
 *
 * Every new connection is an accept(), a Connection object, two watchers in
 * the event loop and a socket the kernel has to set up and tear down.
 * Keep-alive pays that once and then amortises it; Connection: close pays
 * it per request.
 *
 * The same number of requests is sent both ways and timed, with latency
 * percentiles for each — the tail is where connection setup shows up most,
 * because it is the part that occasionally waits.
 */

require __DIR__ . '/bootstrap.php';

[$port, $pid] = benchServer();

$requests = 2_000;

printf("%-22s %-12s %-14s %-10s %-10s %s\n", 'mode', 'requests', 'requests/sec', 'mean ms', 'p95 ms', 'p99 ms');

foreach (['keep-alive' => false, 'connection: close' => true] as $label => $close) {
    $latencies = [];
    $client = $close ? null : benchConnect($port);
    $started = hrtime(true);

    for ($i = 0; $i < $requests; $i++) {
        $requestStarted = hrtime(true);

        if ($close) {
            $client = benchConnect($port);
        }

        fwrite($client, benchRequestBytes('/hello', $close));
        benchReadResponse($client);

        if ($close) {
            fclose($client);
        }

        $latencies[] = (hrtime(true) - $requestStarted) / 1e6;
    }

    $elapsed = (hrtime(true) - $started) / 1e9;

    if (!$close && $client !== null) {
        fclose($client);
    }

    printf(
        "%-22s %-12d %-14.0f %-10.3f %-10.3f %.3f\n",
        $label,
        $requests,
        $requests / max($elapsed, 1e-9),
        array_sum($latencies) / count($latencies),
        benchPercentile($latencies, 95),
        benchPercentile($latencies, 99),
    );
}

benchStop($pid);
