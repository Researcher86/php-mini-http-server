<?php

declare(strict_types=1);

namespace App\EventLoop;

use Closure;

/**
 * One scheduled unit of work.
 *
 * Mutable on purpose: cancellation is a flag flipped by the loop, and a
 * periodic timer reaches the next round by moving its own deadline forward.
 * Holding the callback behind a private read makes it impossible for the
 * loop to accidentally fire an already-cancelled timer.
 */
final class Timer
{
    private bool $cancelled = false;

    public function __construct(
        public readonly int $id,
        public float $dueAt,
        public readonly ?float $interval,
        private readonly Closure $callback,
    ) {
    }

    public function fire(): void
    {
        ($this->callback)();
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function cancelled(): bool
    {
        return $this->cancelled;
    }

    public function isPeriodic(): bool
    {
        return $this->interval !== null;
    }

    /**
     * Advance a periodic timer to its next deadline.
     */
    public function reschedule(): void
    {
        if ($this->interval !== null) {
            $this->dueAt += $this->interval;
        }
    }
}