<?php

declare(strict_types=1);

namespace App\Http\Protocol;

use App\Http\Response\HttpStatusCode;

/**
 * The declared (or delivered) body exceeds the configured limit — answer
 * 413 Payload Too Large instead of letting memory grow without bound.
 */
final class BodyTooLargeException extends RequestException
{
    public function __construct(string $message)
    {
        parent::__construct($message, HttpStatusCode::PAYLOAD_TOO_LARGE);
    }
}
