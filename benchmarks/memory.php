#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * What does a connection cost in memory?
 *
 * This is the number that justifies the whole architecture. A thread or a
 * process per connection costs megabytes each; here a connection is one
 * PHP object, two buffers and two entries in the loop's watch lists.
 *
 * Connections are opened in steps and left open. After each step the
 * server is asked — over HTTP, since it is a separate process and its
 * memory cannot be read from here — how much memory it is now using, and
 * every connection is given a request to prove it is genuinely live rather
 * than merely an open file descriptor.
 */

require __DIR__ . '/bootstrap.php';

[$port, $pid] = benchServer();

$steps = [1, 10, 50, 100, 250, 500];
$clients = [];

$baseline = benchServerMemory($port);

printf("Server baseline, no connections held: %s\n\n", benchFormatBytes($baseline));
printf("%-14s %-16s %-18s %s\n", 'connections', 'server memory', 'per connection', 'all still served');

foreach ($steps as $target) {
    while (count($clients) < $target) {
        $clients[] = benchConnect($port);
    }

    // Live, not merely open: each one is given a request of its own.
    $answered = 0;

    foreach ($clients as $client) {
        fwrite($client, benchRequestBytes('/hello'));

        if (benchReadResponse($client)) {
            $answered++;
        }
    }

    $used = max(0, benchServerMemory($port) - $baseline);

    printf(
        "%-14d %-16s %-18s %d/%d\n",
        $target,
        benchFormatBytes($used),
        benchFormatBytes(intdiv($used, $target)),
        $answered,
        $target,
    );
}

foreach ($clients as $client) {
    fclose($client);
}

benchStop($pid);
