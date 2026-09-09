<?php

declare(strict_types=1);

namespace App\Http\Protocol;

/**
 * HTTP methods the parser understands.
 *
 * The server starts with GET and POST (the two a body-less and a
 * body-carrying method) and grows PUT/DELETE/PATCH as the router needs
 * them. Unknown methods are rejected as malformed input.
 */
enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
    case PATCH = 'PATCH';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';

    /**
     * @throws MalformedRequestException when the wire method is unknown
     */
    public static function fromWire(string $raw): self
    {
        $method = self::tryFrom(strtoupper($raw));

        if ($method === null) {
            throw new MalformedRequestException(sprintf('Unsupported HTTP method: %s', $raw));
        }

        return $method;
    }
}