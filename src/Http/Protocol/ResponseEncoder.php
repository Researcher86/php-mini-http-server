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

        // Body-carrying responses must tell the client how many bytes to
        // expect; empty responses never send a body.
        if ($response->body !== '' && !isset($headers['Content-Length'])) {
            $headers['Content-Length'] = (string) $response->contentLength();
        }

        $head = $statusLine;

        foreach ($headers as $name => $value) {
            $head .= sprintf("%s: %s\r\n", $name, $value);
        }

        return $head . "\r\n" . $response->body;
    }
}