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
     * The method token is case-sensitive (RFC 7230 3.1.1): `get` is not a
     * spelling of GET, it is an unknown method. Upper-casing it here would
     * be one more place where this server is quietly more accepting than
     * whatever is in front of it.
     *
     * @throws MalformedRequestException when the wire method is unknown
     */
    public static function fromWire(string $raw): self
    {
        $method = self::tryFrom($raw);

        if ($method === null) {
            throw new MalformedRequestException(sprintf('Unsupported HTTP method: %s', $raw));
        }

        return $method;
    }
}