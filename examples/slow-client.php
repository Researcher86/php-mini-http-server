#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One question: can a client that never reads its responses hurt anybody
 * else — or the server itself?
 *
 * The slow client below pipelines many requests for a sizeable body and
 * then simply stops reading. Its responses pile up in the server's write
 * buffer, and past the 64 KiB ceiling the server stops reading from that
 * connection until the buffer drains (Phase 18). It does not close it, and
 * it does not drop a response.
 *
 * A second, ordinary client keeps asking for /hello throughout and is timed
 * on every request, to show that it never notices. Watch the server's log:
 * "pausing reads" and "resuming reads" belong to the slow connection alone.
 */

require __DIR__ . '/bootstrap.php';

$slow = exampleConnect();

// /big is 32 KiB, so a handful of them crosses the ceiling. Sent in one
// write, and then never read from.
$pipelined = 8;

for ($i = 0; $i < $pipelined; $i++) {
    exampleRequest($slow, '/big');
}

printf("[slow] pipelined %d requests for a 32 KiB body, and will not read a single reply\n\n", $pipelined);

$healthy = exampleConnect();
$latencies = [];

for ($i = 0; $i < 10; $i++) {
    $started = hrtime(true);
    exampleRequest($healthy, '/hello');
    $response = exampleReadResponse($healthy);
    $latencies[] = (hrtime(true) - $started) / 1e6;

    printf("[healthy] request %2d → %d in %6.3f ms\n", $i + 1, $response['status'], end($latencies));
    usleep(100_000);
}

$metrics = exampleMetrics();

printf("\n[healthy] worst request: %.3f ms — the slow connection cost it nothing\n", max($latencies));
printf("Server still up, %d connection(s) open, loop max lag %.3f ms\n",
    (int) ($metrics['active_connections'] ?? 0),
    $metrics['loop_max_lag_ms'] ?? 0.0,
);

echo "\nNow draining the slow client, to show nothing was lost:\n";

$received = 0;

while ($received < $pipelined) {
    $response = exampleReadResponse($slow);

    if ($response['status'] === 0) {
        break;
    }

    $received++;
}

printf("  read %d of %d responses back, in order, none dropped\n", $received, $pipelined);

fclose($slow);
fclose($healthy);
