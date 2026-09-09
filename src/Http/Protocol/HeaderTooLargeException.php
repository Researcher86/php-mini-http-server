<?php

declare(strict_types=1);

namespace App\Http\Protocol;

use RuntimeException;

/**
 * The request's header block exceeds the configured limit — answer
 * 431 Request Header Fields Too Large instead of buffering it forever.
 */
final class HeaderTooLargeException extends RuntimeException
{
}