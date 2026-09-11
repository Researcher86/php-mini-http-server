<?php

declare(strict_types=1);

namespace App\Http\Response;

use App\Http\Headers\Headers;
use App\Http\Protocol\HttpVersion;

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
        $status = self::status($status);

        $headers->set('Content-Type', 'text/plain; charset=utf-8');
        $headers->set('Content-Length', (string) strlen($body));

        return new HttpResponse(self::DEFAULT_VERSION, $status, $headers, $body);
    }

    public static function json(
        mixed $data,
        int|HttpStatusCode $status = 200,
        Headers $headers = new Headers(),
    ): HttpResponse {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new \RuntimeException(sprintf('Cannot encode response body as JSON: %s', json_last_error_msg()));
        }

        $status = self::status($status);

        $headers->set('Content-Type', 'application/json; charset=utf-8');
        $headers->set('Content-Length', (string) strlen($body));

        return new HttpResponse(self::DEFAULT_VERSION, $status, $headers, $body);
    }

    public static function empty(int|HttpStatusCode $status = 200, Headers $headers = new Headers()): HttpResponse
    {
        return new HttpResponse(self::DEFAULT_VERSION, self::status($status), $headers, '');
    }

    private static function status(int|HttpStatusCode $status): HttpStatusCode
    {
        if ($status instanceof HttpStatusCode) {
            return $status;
        }

        return HttpStatusCode::from($status);
    }
}