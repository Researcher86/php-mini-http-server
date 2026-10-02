<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Protocol;

use PhpMiniHttpServer\Http\Headers\Headers;
use PhpMiniHttpServer\Http\Response\HttpResponse;
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
        $body = $response->body;

        if (!$response->status->framesBody()) {
            // 204 and 304 are always bodyless. Dropping a body supplied by an
            // application here keeps an invalid response from corrupting the
            // next response on a persistent connection. This server
            // deliberately does not emit Content-Length for either status.
            $body = '';
            $headers = array_filter(
                $headers,
                static fn (string $name): bool => strtolower($name) !== 'content-length',
                ARRAY_FILTER_USE_KEY,
            );
        } elseif (!$response->headers->has('Content-Length')) {
            // Framing: every response must state how many bytes to expect,
            // empty ones included — on a kept-alive connection "the body
            // ends when the socket closes" does not hold, so a bare 200
            // would leave the client waiting for a body that never comes.
            //
            // The "already framed?" question goes through Headers, which
            // knows names are case-insensitive: asking the normalized array
            // directly would miss a handler's "content-length" and emit a
            // second, capital copy — two Content-Length lines, which
            // recipients must reject.
            $headers['Content-Length'] = (string) $response->framedContentLength();
        } else {
            $this->assertDeclaredLengthMatches($response);
        }

        $head = $statusLine;

        foreach ($headers as $name => $value) {
            // Response splitting guard: a header value is a single line on
            // the wire, so CR/LF smuggled in by a handler must never reach
            // it. ConnectionHandler turns this refusal into a 500 — it
            // cannot be the pipeline's error handler, which has already
            // returned by the time encoding starts.
            if (!Headers::isToken($name) || !Headers::isFieldValue($value)) {
                throw new RuntimeException(sprintf('Malformed response header: %s', $name));
            }

            $head .= sprintf("%s: %s\r\n", $name, $value);
        }

        return $head . "\r\n" . $body;
    }

    /**
     * A handler that sets Content-Length itself must be right about it: a
     * wrong length desynchronises every later response on the connection.
     */
    private function assertDeclaredLengthMatches(HttpResponse $response): void
    {
        $declared = (string) $response->headers->get('Content-Length');
        $length = $response->framedContentLength();

        if (!ctype_digit($declared) || ltrim($declared, '0') !== ltrim((string) $length, '0')) {
            throw new RuntimeException(sprintf(
                'Content-Length %s does not match the %d-byte response representation.',
                $declared,
                $length,
            ));
        }
    }
}
