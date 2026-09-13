<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Connection;

use RuntimeException;

/**
 * Raised when a lifecycle transition is illegal or a closed
 * connection is used — programming errors, not network errors.
 */
final class ConnectionException extends RuntimeException
{
}
