<?php

declare(strict_types=1);

namespace App\Router;

use RuntimeException;

/**
 * The path exists but no route is registered for this HTTP method on it —
 * the error handling phase answers 405 Method Not Allowed, with an Allow
 * header listing the methods that do match.
 */
final class MethodNotAllowedException extends RuntimeException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(
        public readonly array $allowed,
    ) {
        parent::__construct(sprintf('Method not allowed. Allowed: %s', implode(', ', $allowed)));
    }
}
