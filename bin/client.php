<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * A deliberately small HTTP/1.1 client — say what you want, read what you get.
 *
 * It exists to prove the server's wire format by hand: one raw request, the
 * raw response printed as-is, no parsing library in between. Run it against
 * `make run-server` (or `make run-example`) and you will see the same status
 * line, headers and body the server really wrote to the socket.
 *
 *     php bin/client.php /hello
 *     php bin/client.php /users/42
 *     php bin/client.php /nope        # the 404 handler answers in plain HTTP
 *
 * Host and port come from the same environment variables bin/server.php
 * reads, so the two agree by default even though they are separate processes.
 */

$host = getenv('HTTP_SERVER_HOST') ?: '127.0.0.1';
$port = (int) (getenv('HTTP_SERVER_PORT') ?: '8080');
$path = $argv[1] ?? '/hello';

$stream = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10.0);

if ($stream === false) {
    fwrite(STDERR, sprintf("Cannot connect to %s:%d: %s (%d)\n", $host, $port, $errstr, $errno));
    exit(1);
}

// "Connection: close" asks the server to shut the socket once the response
// is fully written, so "read until EOF" below is a well-defined, complete
// answer rather than a guess at when the server is done talking.
fwrite($stream, sprintf(
    "GET %s HTTP/1.1\r\nHost: %s\r\nConnection: close\r\n\r\n",
    $path,
    $host,
));

$response = '';

while (!feof($stream)) {
    $chunk = fread($stream, 8192);

    if ($chunk === false) {
        fwrite(STDERR, "Read failed.\n");
        exit(1);
    }

    $response .= $chunk;
}

fclose($stream);

$headEnd = strpos($response, "\r\n\r\n");
$head = $headEnd === false ? $response : substr($response, 0, $headEnd);
$body = $headEnd === false ? '' : substr($response, $headEnd + 4);

printf("GET %s\n%s\n\n%s\n", $path, $head, $body);
