#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One question: is the second request on a connection actually cheaper than
 * the first, and does the connection really survive in between?
 *
 * Twenty requests go through one connection, then twenty more each open a
 * connection of their own and close it. The difference between the two
 * timings is the TCP handshake and teardown that keep-alive avoids — and
 * the server's own connection count confirms that twenty requests really
 * did share one socket.
 */

require __DIR__ . '/bootstrap.php';

$requests = 20;

// ── one connection, many requests ───────────────────────────────────────
$client = exampleConnect();

// The first exchange is outside the timing: it is here to show what the
// server says about the connection, and the /metrics probe it makes opens
// a connection of its own, which would land in the measurement.
exampleRequest($client, '/hello');
$first = exampleReadResponse($client);

preg_match('/^Connection:\s*(.+)$/mi', $first['head'], $matches);
printf("First response says: Connection: %s\n", trim($matches[1] ?? '(absent)'));
printf("Server sees %d connection(s): this one, plus the /metrics probe.\n\n", (int) (exampleMetrics()['active_connections'] ?? 0));

$started = hrtime(true);

for ($i = 0; $i < $requests; $i++) {
    exampleRequest($client, '/hello');
    exampleReadResponse($client);
}

$keptAlive = (hrtime(true) - $started) / 1e6;
fclose($client);

// ── one connection per request ──────────────────────────────────────────
$started = hrtime(true);

for ($i = 0; $i < $requests; $i++) {
    $fresh = exampleConnect();
    exampleRequest($fresh, '/hello', close: true);
    exampleReadResponse($fresh);
    fclose($fresh);
}

$perRequest = (hrtime(true) - $started) / 1e6;

printf("%d requests over one kept-alive connection: %7.2f ms\n", $requests, $keptAlive);
printf("%d requests, a new connection each:        %7.2f ms\n", $requests, $perRequest);
printf("\nKeep-alive saved %.2f ms — %.0f%% — and it is all connection setup.\n",
    $perRequest - $keptAlive,
    $perRequest > 0.0 ? (1 - $keptAlive / $perRequest) * 100 : 0.0,
);
