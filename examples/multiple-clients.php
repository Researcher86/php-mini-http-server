#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One question: how many clients can one process, with one event loop and
 * no threads, hold open and serve at the same time?
 *
 * Fifty connections are opened and kept open, then each one is asked for a
 * response. No connection is closed until the end, so all fifty are alive
 * in the server's connection table simultaneously — /metrics is asked to
 * confirm it rather than taking the script's word.
 */

require __DIR__ . '/bootstrap.php';

$count = 50;
$clients = [];

for ($i = 0; $i < $count; $i++) {
    $clients[] = exampleConnect();
}

printf("Opened %d connections and left every one of them open.\n", $count);
printf("Server reports active_connections = %d\n\n", (int) (exampleMetrics()['active_connections'] ?? 0));

$served = 0;

foreach ($clients as $i => $client) {
    exampleRequest($client, '/users/' . $i);
    $response = exampleReadResponse($client);

    if ($response['status'] === 200) {
        $served++;
    }
}

printf("All %d connections asked and answered: %d of %d got a 200.\n", $count, $served, $count);

foreach ($clients as $client) {
    fclose($client);
}

$metrics = exampleMetrics();

printf("\nAfter closing them: active_connections = %d\n", (int) ($metrics['active_connections'] ?? 0));
printf(
    "The loop made %d passes in total, and was busy for %.1f%% of its life.\n",
    (int) ($metrics['loop_iterations'] ?? 0),
    ($metrics['loop_utilisation'] ?? 0.0) * 100,
);
echo "\nOne process. One loop. No thread, no fork, per connection.\n";
