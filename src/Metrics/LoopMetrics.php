<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Observability for the event loop itself.
 *
 * {@see ServerMetrics} counts what the server did — requests, bytes. This
 * counts what the loop was doing while it did them, which in a
 * single-process server is the more revealing number of the two: one loop
 * serves every client, so any stretch spent inside a handler is a stretch
 * during which nobody else's socket or timer is being looked at.
 *
 *     idle     waiting in stream_select() — healthy; the loop has capacity
 *     busy     running handlers and timers
 *     max lag  the longest single busy stretch there has ever been
 *
 * Max lag is the one to watch. It is the worst delay any other connection
 * could have suffered waiting for its turn, and it is what turns "a
 * blocking call in a handler blocks the whole server" from a warning in
 * the README into a number you can read off /metrics.
 *
 * Recording never changes loop behaviour, and the loop keeps time with
 * hrtime() rather than microtime(): a clock that can step backwards would
 * report negative stretches.
 */
final class LoopMetrics
{
    private int $iterations = 0;

    private float $busySeconds = 0.0;

    private float $idleSeconds = 0.0;

    private float $maxLagSeconds = 0.0;

    /**
     * Record one completed wait-and-dispatch pass.
     */
    public function recordIteration(float $busySeconds, float $idleSeconds): void
    {
        $this->iterations++;
        $this->busySeconds += $busySeconds;
        $this->idleSeconds += $idleSeconds;
        $this->maxLagSeconds = max($this->maxLagSeconds, $busySeconds);
    }

    public function iterations(): int
    {
        return $this->iterations;
    }

    public function busySeconds(): float
    {
        return $this->busySeconds;
    }

    public function idleSeconds(): float
    {
        return $this->idleSeconds;
    }

    /**
     * The longest the loop has ever been busy in one pass — i.e. the
     * furthest behind it has ever fallen on servicing everyone else.
     */
    public function maxLagSeconds(): float
    {
        return $this->maxLagSeconds;
    }

    /**
     * Share of loop time spent running handlers rather than waiting.
     *
     * Near 0 means an idle server; near 1 means a loop with no slack left,
     * where the next arriving connection waits behind the current handler.
     */
    public function utilisation(): float
    {
        $total = $this->busySeconds + $this->idleSeconds;

        return $total > 0.0 ? $this->busySeconds / $total : 0.0;
    }
}
