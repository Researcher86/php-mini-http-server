<?php

declare(strict_types=1);

namespace App\Http\Protocol;

use App\Http\Response\HttpStatusCode;

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
