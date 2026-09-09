<?php

declare(strict_types=1);

namespace App\Server;

use RuntimeException;

/**
 * Raised when the server socket cannot be created, bound or put
 * into listen mode — the process has nothing left to do.
 */
final class ServerStartException extends RuntimeException
{
}