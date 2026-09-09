<?php

declare(strict_types=1);

namespace App\Http\Protocol;

use RuntimeException;

/**
 * The bytes did not parse as a valid HTTP request — the server should
 * answer 400 Bad Request, not crash.
 */
final class MalformedRequestException extends RuntimeException
{
}