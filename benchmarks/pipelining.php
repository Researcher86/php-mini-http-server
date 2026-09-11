#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * How much does pipelining actually buy?
 *
 * A request and its response cost two things: the work of serving it, and
 * one round trip of waiting. Pipelining removes the second — the client
 * writes N requests without waiting, and the server answers them in order
 * from one read buffer (Phase 15).
 *
 * So this sends the same total number of requests at several batch sizes
 * and reports throughput for each. The curve flattens once the round trip
 * is no longer what the client is spending its time on; where it flattens
 * is where the server's own per-request cost takes over.
 */

require __DIR__ . '/bootstrap.php';

[$port, $pid] = benchServer();

$total = 2_000;
$request = benchRequestBytes('/hello');

printf("%-12s %-10s %-12s %s\n", 'batch', 'requests', 'elapsed ms', 'requests/sec');

foreach ([1, 10, 100, 1000] as $batch) {
    $client = benchConnect($port);
    $batches = intdiv($total, $batch);

    $started = hrtime(true);

    for ($i = 0; $i < $batches; $i++) {
        fwrite($client, str_repeat($request, $batch));

        for ($j = 0; $j < $batch; $j++) {
            if (!benchReadResponse($client)) {
                fwrite(STDERR, "the server stopped answering mid-batch\n");

                exit(1);
            }
        }
    }

    $elapsed = (hrtime(true) - $started) / 1e9;
    fclose($client);

    printf(
        "%-12d %-10d %-12.1f %.0f\n",
        $batch,
        $batches * $batch,
        $elapsed * 1000,
        ($batches * $batch) / max($elapsed, 1e-9),
    );
}

benchStop($pid);
