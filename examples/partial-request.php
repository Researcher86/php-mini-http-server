#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One question: what does the server do with half a request?
 *
 * TCP does not preserve message boundaries, so "GET /hel" really can be
 * everything that arrives for a while. The server must hold those bytes and
 * say nothing — answering early would mean guessing — and then answer the
 * moment the last byte of the request lands.
 *
 * The request below is dribbled out one byte at a time, with the script
 * checking after each byte whether anything came back. Nothing does, until
 * the final CRLF.
 */

require __DIR__ . '/bootstrap.php';

$client = exampleConnect();
stream_set_blocking($client, false);

$request = "GET /hello HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n";
$bytes = str_split($request);
$last = count($bytes) - 1;

printf("Sending a %d-byte request one byte at a time:\n\n", strlen($request));

foreach ($bytes as $position => $byte) {
    fwrite($client, $byte);
    usleep(20_000);

    // stream_select() asks whether anything is there to read without
    // taking it. Reading would consume the response, and the point of the
    // last line below is to print the response in full.
    $read = [$client];
    $write = [];
    $except = [];
    $answered = stream_select($read, $write, $except, 0, 0) === 1;

    // Only the last byte may draw an answer. Anything earlier would mean
    // the server decided where the request ended before it got there.
    $expected = $position === $last;

    printf(
        "  %2d/%d  sent %-8s → server said %s%s\n",
        $position + 1,
        count($bytes),
        '"' . addcslashes($byte, "\r\n") . '"',
        $answered ? 'SOMETHING' : 'nothing',
        $answered === $expected ? '' : '   ← WRONG',
    );

    if ($answered !== $expected) {
        fclose($client);

        exit(1);
    }
}

printf(
    "\n%d partial reads sat in the buffer unanswered. The %dth byte completed\nthe request, and only then:\n\n",
    $last,
    $last + 1,
);

stream_set_blocking($client, true);
$response = exampleReadResponse($client);

printf("%s\n\n%s\n", $response['head'], $response['body']);

fclose($client);
