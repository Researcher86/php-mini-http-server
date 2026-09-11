<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal logging seam for events that are useful to observe but must never
 * crash the request path: connection lifecycle changes, shutdown stages and
 * per-request logging from LoggingMiddleware. Deliberately one method, not
 * PSR-3: this codebase has no dependencies and only needs "record that this
 * happened", not levels, channels, or placeholders. Swap in PSR-3 behind
 * this interface if a real application ever needs more.
 */
interface Logger
{
    public function log(string $message): void;
}
