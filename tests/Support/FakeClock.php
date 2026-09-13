<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\Support;

use PhpMiniHttpServer\Support\Clock;

/**
 * Test double for {@see Clock}: time moves only when the test says so.
 *
 * A timeout test can then be about the timeout rather than about waiting —
 * advance five seconds, assert the sweep reaped the connection, in no wall
 * time at all.
 */
final class FakeClock implements Clock
{
    public function __construct(
        private float $now = 1_000.0,
    ) {
    }

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
