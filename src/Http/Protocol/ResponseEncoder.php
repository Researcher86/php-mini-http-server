<?php

declare(strict_types=1);

namespace App\Http\Protocol;

use App\Http\Response\HttpResponse;
use App\Http\Response\HttpStatusCode;

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
        // that never frame a body are 1xx and 204 (they are unambiguous
        // from the status line alone); everything else that has no
        // Content-Length yet is framed right here.
        if (!isset($headers['Content-Length']) && !self::neverFramesBody($response->statusCode())) {
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

    /**
     * Statuses that never carry a response body, so a keep-alive client can
     * tell where the response ends from the status line alone and
     * Content-Length is omitted (a MUST NOT for 1xx and 204).
     */
    private static function neverFramesBody(int $status): bool
    {
        return ($status >= 100 && $status < 200) || $status === HttpStatusCode::NO_CONTENT->value;
    }
}
