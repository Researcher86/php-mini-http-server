<?php

declare(strict_types=1);

namespace App\Http\Response;

/**
 * HTTP status codes the server can produce, with their RFC reason phrases.
 *
 * The reason phrase is part of the status line on the wire
 * ("HTTP/1.1 200 OK"), so it lives here once, next to the numeric code,
 * and the encoder does not have to maintain its own table.
 */
enum HttpStatusCode: int
{
    case OK = 200;
    case CREATED = 201;
    case NO_CONTENT = 204;
    case MOVED_PERMANENTLY = 301;
    case FOUND = 302;
    case NOT_MODIFIED = 304;
    case BAD_REQUEST = 400;
    case UNAUTHORIZED = 401;
    case FORBIDDEN = 403;
    case NOT_FOUND = 404;
    case METHOD_NOT_ALLOWED = 405;
    case CONFLICT = 409;
    case PAYLOAD_TOO_LARGE = 413;
    case UNPROCESSABLE_ENTITY = 422;
    case TOO_MANY_REQUESTS = 429;
    case HEADER_TOO_LARGE = 431;
    case INTERNAL_SERVER_ERROR = 500;
    case NOT_IMPLEMENTED = 501;
    case SERVICE_UNAVAILABLE = 503;

    public function reasonPhrase(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::CREATED => 'Created',
            self::NO_CONTENT => 'No Content',
            self::MOVED_PERMANENTLY => 'Moved Permanently',
            self::FOUND => 'Found',
            self::NOT_MODIFIED => 'Not Modified',
            self::BAD_REQUEST => 'Bad Request',
            self::UNAUTHORIZED => 'Unauthorized',
            self::FORBIDDEN => 'Forbidden',
            self::NOT_FOUND => 'Not Found',
            self::METHOD_NOT_ALLOWED => 'Method Not Allowed',
            self::CONFLICT => 'Conflict',
            self::PAYLOAD_TOO_LARGE => 'Payload Too Large',
            self::UNPROCESSABLE_ENTITY => 'Unprocessable Entity',
            self::TOO_MANY_REQUESTS => 'Too Many Requests',
            self::HEADER_TOO_LARGE => 'Request Header Fields Too Large',
            self::INTERNAL_SERVER_ERROR => 'Internal Server Error',
            self::NOT_IMPLEMENTED => 'Not Implemented',
            self::SERVICE_UNAVAILABLE => 'Service Unavailable',
        };
    }

    /**
     * Whether a response with this status carries body framing on the wire.
     *
     * 1xx, 204 and 304 never frame a body:
     *   - 1xx: the distinction is guaranteed by construction (no 1xx in enum).
     *   - 204 No Content: length is implicit in the status line.
     *   - 304 Not Modified: carries validators / metadata, never a body.
     *
     * Everything else that has no Content-Length yet is framed by the encoder.
     */
    public function framesBody(): bool
    {
        return $this !== self::NO_CONTENT && $this !== self::NOT_MODIFIED;
    }
}
