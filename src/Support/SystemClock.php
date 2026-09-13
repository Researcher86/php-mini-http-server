<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Support;

/** The real wall clock. */
final readonly class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }
}
