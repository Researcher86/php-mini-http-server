<?php

declare(strict_types=1);

namespace App\Http\Protocol;

use App\Http\Response\HttpResponse;

/**
 * Turns an HttpResponse into the exact bytes that go on the wire.
 *
 * The reverse of HttpParser: status line, one header per line, the blank
 * line, then the raw body. The parser and the encoder agree on the same
 * \r\n line endings, so any client that accepts our requests accepts our
 * responses.
 */
final class ResponseEncoder
{
    public function encode(HttpResponse $response): string
    {
        $statusLine = sprintf(
            "%s %d %s\r\n",
            $response->version->wire(),
            $response->statusCode(),
            $response->reasonPhrase(),
        );

        $headers = $response->headers->normalized();

        // Framing: every response must state how many bytes to expect,
        // empty ones included — on a kept-alive connection "the body ends
        // when the socket closes" does not hold, so a bare 200 would leave
        // the client waiting for a body that never comes. The only statuses
        // that never frame a body are 1xx and 204 (their length is implicit
        // in the status line); everything else that has no Content-Length
        // yet is framed right here.
        //
        // The "already framed?" question goes through Headers, which knows
        // names are case-insensitive: asking the normalized array directly
        // would miss a handler's "content-length" and emit a second, capital
        // copy — two Content-Length lines, which recipients must reject.
        if (!$response->headers->has('Content-Length') && $response->status->framesBody()) {
            $headers['Content-Length'] = (string) $response->contentLength();
        }

        $head = $statusLine;

        foreach ($headers as $name => $value) {
            // Response splitting guard: a header value is a single line on
            // the wire, so CR/LF smuggled in by a handler must never reach
            // it. The error handler turns this into a 500.
            if (str_contains($name, "\r") || str_contains($name, "\n")
                || str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new \RuntimeException(sprintf(
                    'Header name or value contains CR/LF: %s',
                    $name,
                ));
            }

            $head .= sprintf("%s: %s\r\n", $name, $value);
        }

        return $head . "\r\n" . $response->body;
    }
}
