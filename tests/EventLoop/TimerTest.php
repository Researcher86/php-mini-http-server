<?php

declare(strict_types=1);

namespace App\Tests\EventLoop;

use App\EventLoop\Timer;
use PHPUnit\Framework\TestCase;

final class TimerTest extends TestCase
{
    public function testOneShotTimerIsNotPeriodic(): void
    {
        $timer = new Timer(1, 100.0, null, static function (): void {
        });

        $this->assertFalse($timer->isPeriodic());
        $this->assertFalse($timer->cancelled());
    }

    public function testCancelFlipsTheFlag(): void
    {
        $timer = new Timer(1, 100.0, 1.0, static function (): void {
        });
        $timer->cancel();

        $this->assertTrue($timer->cancelled());
    }

    public function testPeriodicRescheduleStaysAnchoredToTheOriginalSchedule(): void
    {
        // Anchor at t=100, interval 10 → expected firings at 100, 110, 120…
        $timer = new Timer(1, 100.0, 10.0, static function (): void {
        });

        // A slightly-late loop at t=113.4 must land on the next anchored
        // firing (120), not drift to 123.4.
        $timer->reschedule(113.4);
        $this->assertSame(120.0, $timer->dueAt);

        $timer->reschedule(120.0);
        $this->assertSame(120.0, $timer->dueAt);

        // Even after a long stall, cadence snaps back to the schedule.
        $timer->reschedule(155.0);
        $this->assertSame(160.0, $timer->dueAt);
    }

    public function testRescheduleDoesNothingForOneShotTimers(): void
    {
        $timer = new Timer(1, 100.0, null, static function (): void {
        });
        $timer->reschedule(500.0);

        $this->assertSame(100.0, $timer->dueAt);
    }

    public function testFireInvokesTheCallback(): void
    {
        $fired = false;
        $timer = new Timer(1, 100.0, null, static function () use (&$fired): void {
            $fired = true;
        });

        $timer->fire();

        $this->assertTrue($fired);
    }
}
