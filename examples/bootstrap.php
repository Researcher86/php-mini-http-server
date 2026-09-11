<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Shared by every script under examples/.
 *
 * Each example answers one question against a real, already-running server
 * (start it first with `make run-server`), and each talks to it in raw
 * HTTP/1.1 — no client class in between, so what you read here is what
 * goes on the wire.
 *
 * The helpers below exist only so the examples can be about the one thing
 * they demonstrate, rather than about socket bookkeeping.
 */

/**
 * Open a connection to the demo server, or explain what is missing.
 *
 * @return resource
 */
function exampleConnect(float $timeoutSeconds = 5.0): mixed
{
    $host = getenv('HTTP_SERVER_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('HTTP_SERVER_PORT') ?: '8080');

    $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeoutSeconds);

    if ($stream === false) {
        fwrite(STDERR, sprintf(
            "Cannot reach %s:%d: %s (%d) — is `make run-server` running?\n",
            $host,
            $port,
            $errstr,
            $errno,
        ));

        exit(1);
    }

    return $stream;
}

/**
 * The request line and headers of a GET, as bytes.
 *
 * `Connection: close` is not the default here: most of these examples are
 * about a connection that stays open, and saying "close" would end the very
 * thing being demonstrated.
 *
 * @param resource $stream
 */
function exampleRequest(mixed $stream, string $path, bool $close = false): void
{
    $host = getenv('HTTP_SERVER_HOST') ?: '127.0.0.1';

    fwrite($stream, sprintf(
        "GET %s HTTP/1.1\r\nHost: %s\r\n%s\r\n",
        $path,
        $host,
        $close ? "Connection: close\r\n" : '',
    ));
}

/**
 * Read exactly one response: the head, then as many body bytes as
 * Content-Length declares — never a byte more.
 *
 * Over-reading is the classic mistake on a kept-alive connection. The
 * bytes past this response belong to the next one, and swallowing them
 * makes the following read look like a server that answered nothing.
 *
 * @param resource $stream
 *
 * @return array{head: string, body: string, status: int}
 */
function exampleReadResponse(mixed $stream): array
{
    // The head is read one byte at a time. That is slow and deliberate: a
    // bulk read returns whatever has arrived, which on a kept-alive or
    // pipelined connection is routinely the start of the NEXT response —
    // and those bytes would then be missing when somebody asks for it.
    // Stopping exactly at the blank line, then taking exactly
    // Content-Length bytes, leaves the socket positioned on a boundary.
    //
    // The server has the same problem in the other direction and solves it
    // the same way: HttpParser reports consumedBytes, and ReadBuffer drops
    // exactly that many.
    $head = '';

    while (!str_ends_with($head, "\r\n\r\n")) {
        $byte = fread($stream, 1);

        if ($byte === false || $byte === '') {
            return ['head' => $head, 'body' => '', 'status' => 0];
        }

        $head .= $byte;
    }

    $head = substr($head, 0, -4);
    $length = 0;

    // The \r is matched explicitly: with /m, PCRE ends a line at \n, so a
    // pattern anchored with $ would refuse to match a value followed by
    // the CR that HTTP puts there. Getting this wrong reads zero body
    // bytes and leaves the real ones to corrupt the next response.
    if (preg_match('/^Content-Length:[ \t]*(\d+)[ \t]*\r?$/mi', $head, $matches) === 1) {
        $length = (int) $matches[1];
    }

    $body = '';

    while (strlen($body) < $length) {
        $chunk = fread($stream, max(1, $length - strlen($body)));

        if ($chunk === false || $chunk === '') {
            break;
        }

        $body .= $chunk;
    }

    $status = 0;

    if (preg_match('#^HTTP/1\.[01] (\d{3})#', $head, $matches) === 1) {
        $status = (int) $matches[1];
    }

    return ['head' => $head, 'body' => $body, 'status' => $status];
}

/**
 * Whatever the server has printed into /metrics right now, as a map.
 *
 * @return array<string, float>
 */
function exampleMetrics(): array
{
    $stream = exampleConnect();
    exampleRequest($stream, '/metrics', close: true);
    $response = exampleReadResponse($stream);
    fclose($stream);

    $metrics = [];

    foreach (explode("\n", $response['body']) as $line) {
        if (preg_match('/^(\w+) (-?[\d.]+)$/', $line, $matches) === 1) {
            $metrics[$matches[1]] = (float) $matches[2];
        }
    }

    return $metrics;
}
