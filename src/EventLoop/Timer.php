<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\EventLoop;

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

    /** The deadline of the first firing — the fixed anchor for periodic timers. */
    private readonly float $anchor;

    public function __construct(
        public readonly int $id,
        public float $dueAt,
        public readonly ?float $interval,
        private readonly Closure $callback,
    ) {
        $this->anchor = $dueAt;
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
     * Advance a periodic timer to the next firing, anchored to the original
     * schedule rather than sliding by a full interval each time — under a
     * slow loop that keeps the cadence honest instead of drifting late.
     */
    public function reschedule(float $now): void
    {
        if ($this->interval === null) {
            return;
        }

        $periods = (int) ceil(($now - $this->anchor) / $this->interval);
        $this->dueAt = $this->anchor + $periods * $this->interval;
    }
}
