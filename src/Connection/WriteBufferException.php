<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Connection;

use RuntimeException;

/**
 * A socket write failed on the wire itself (as opposed to merely blocking),
 * so retrying on the next writable event cannot help.
 */
final class WriteBufferException extends RuntimeException
{
}
