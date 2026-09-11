<?php

declare(strict_types=1);

namespace App\Http\Protocol;

use App\Http\Response\HttpResponse;
use RuntimeException;

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

        // 204 and 304 are always bodyless. Dropping a body supplied by an
        // application here keeps an invalid response from corrupting the
        // next response on a persistent connection. This server deliberately
        // does not emit Content-Length for either status (see framesBody()).
        $body = $response->status->framesBody() ? $response->body : '';

        if (!$response->status->framesBody()) {
            foreach ($headers as $name => $_) {
                if (strtolower($name) === 'content-length') {
                    unset($headers[$name]);
                }
            }
        }

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
        if ($response->status->framesBody()) {
            $contentLength = $response->headers->get('Content-Length');

            if ($contentLength === null) {
                $headers['Content-Length'] = (string) $response->framedContentLength();
            } elseif (!$this->isContentLengthFor($contentLength, $response->framedContentLength())) {
                throw new RuntimeException(sprintf(
                    'Content-Length %s does not match the %d-byte response representation.',
                    $contentLength,
                    $response->framedContentLength(),
                ));
            }
        }

        $head = $statusLine;

        foreach ($headers as $name => $value) {
            // Response splitting guard: a header value is a single line on
            // the wire, so CR/LF smuggled in by a handler must never reach
            // it. ConnectionHandler turns this refusal into a 500 — it
            // cannot be the pipeline's error handler, which has already
            // returned by the time encoding starts.
            if (!$this->isToken($name) || !$this->isFieldValue($value)) {
                throw new RuntimeException(sprintf(
                    'Malformed response header: %s',
                    $name,
                ));
            }

            $head .= sprintf("%s: %s\r\n", $name, $value);
        }

        return $head . "\r\n" . $body;
    }

    private function isContentLengthFor(string $value, int $bodyLength): bool
    {
        if (preg_match('/^[0-9]+$/', $value) !== 1) {
            return false;
        }

        return ltrim($value, '0') === ltrim((string) $bodyLength, '0');
    }

    private function isToken(string $name): bool
    {
        return $name !== '' && preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) === 1;
    }

    private function isFieldValue(string $value): bool
    {
        return preg_match('/^[\t\x20-\x7E\x80-\xFF]*$/', $value) === 1;
    }
}
