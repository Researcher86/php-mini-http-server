<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Response;

use PhpMiniHttpServer\Http\Headers\Headers;
use PhpMiniHttpServer\Http\Protocol\HttpVersion;
use RuntimeException;

/**
 * Convenience helpers for building common responses.
 *
 * The factories take care of the plumbing that every HTTP response needs —
 * protocol version, Content-Type and Content-Length — so handlers can say
 * ResponseFactory::text('Hello') instead of assembling a Headers object.
 */
final class ResponseFactory
{
    private const HttpVersion DEFAULT_VERSION = HttpVersion::HTTP_1_1;

    public static function text(
        string $body,
        int|HttpStatusCode $status = 200,
        Headers $headers = new Headers(),
    ): HttpResponse {
        return self::body($body, 'text/plain; charset=utf-8', $status, $headers);
    }

    public static function json(
        mixed $data,
        int|HttpStatusCode $status = 200,
        Headers $headers = new Headers(),
    ): HttpResponse {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new RuntimeException(sprintf('Cannot encode response body as JSON: %s', json_last_error_msg()));
        }

        return self::body($body, 'application/json; charset=utf-8', $status, $headers);
    }

    /**
     * A response with no body at all — the caller decides whether that is a
     * 204, a redirect, or a 200 that simply has nothing to say. Content-Length
     * is left to the encoder, which knows which statuses may carry it.
     */
    public static function empty(int|HttpStatusCode $status = 200, Headers $headers = new Headers()): HttpResponse
    {
        return new HttpResponse(self::DEFAULT_VERSION, self::status($status), $headers, '');
    }

    /**
     * The plumbing both body factories need: declare the type, declare the
     * length, and let the status be given either way round.
     */
    private static function body(
        string $body,
        string $contentType,
        int|HttpStatusCode $status,
        Headers $headers,
    ): HttpResponse {
        $headers->set('Content-Type', $contentType);
        $headers->set('Content-Length', (string) strlen($body));

        return new HttpResponse(self::DEFAULT_VERSION, self::status($status), $headers, $body);
    }

    private static function status(int|HttpStatusCode $status): HttpStatusCode
    {
        return $status instanceof HttpStatusCode ? $status : HttpStatusCode::from($status);
    }
}
