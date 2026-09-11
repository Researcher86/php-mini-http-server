<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Timestamped lines to STDERR - kept apart from STDOUT, so server logs and
 * script output (client responses, bench tables, /metrics dumps) can each be
 * redirected or filtered independently.
 */
final readonly class StderrLogger implements Logger
{
    public function log(string $message): void
    {
        fwrite(STDERR, sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL));
    }
}
