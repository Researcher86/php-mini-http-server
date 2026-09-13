<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Protocol;

use PhpMiniHttpServer\Http\Response\HttpStatusCode;

/**
 * The bytes did not parse as a valid HTTP request — the server should
 * answer 400 Bad Request, not crash.
 */
final class MalformedRequestException extends RequestException
{
    public function __construct(string $message)
    {
        parent::__construct($message, HttpStatusCode::BAD_REQUEST);
    }
}
