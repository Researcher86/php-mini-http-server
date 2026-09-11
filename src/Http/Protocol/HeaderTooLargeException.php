<?php

declare(strict_types=1);

namespace App\Http\Protocol;

use App\Http\Response\HttpStatusCode;

/**
 * The request's header block exceeds the configured limit — answer
 * 431 Request Header Fields Too Large instead of buffering it forever.
 */
final class HeaderTooLargeException extends RequestException
{
    public function __construct(string $message)
    {
        parent::__construct($message, HttpStatusCode::HEADER_TOO_LARGE);
    }
}
