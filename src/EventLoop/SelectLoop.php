<?php

declare(strict_types=1);

namespace App\EventLoop;

use Closure;
use TypeError;
use ValueError;

/**
 * A single-threaded, single-process event loop built on stream_select().
 *
 * One PHP process, one loop, N connections: that is the whole point of the
 * architecture. There is no per-connection thread or process — the loop
 * simply asks the kernel which streams are ready and fires the matching
 * callbacks.
 *
 * Watch states are tracked by stream object id, so a stream that is both
 * readable and writable (a client socket usually is) holds two independent
 * handler slots.
 *
 * Timers participate in the same wait: the select timeout is derived from
 * the nearest timer deadline, so an idle server still wakes up to run
 * scheduled work.
 */
final class SelectLoop implements EventLoop
{
    private const int IDLE_WAIT_SECONDS = 1;

    /** @var array<int, resource> keyed by stream object id */
    private array $readable = [];

    /** @var array<int, resource> keyed by stream object id */
    private array $writable = [];

    /** @var array<int, Closure(resource): void> keyed by stream object id */
    private array $readHandlers = [];

    /** @var array<int, Closure(resource): void> keyed by stream object id */
    private array $writeHandlers = [];

    /** @var array<int, Timer> keyed by timer id */
    private array $timers = [];

    private int $nextTimerId = 1;

    private bool $running = false;

    public function onReadable(mixed $stream, Closure $handler): void
    {
        $this->assertStream($stream);

        $id = (int) $stream;

        $this->readable[$id] = $stream;
        $this->readHandlers[$id] = $handler;
    }

    public function onWritable(mixed $stream, Closure $handler): void
    {
        $this->assertStream($stream);

        $id = (int) $stream;

        $this->writable[$id] = $stream;
        $this->writeHandlers[$id] = $handler;
    }

    public function removeReadable(mixed $stream): void
    {
        $id = (int) $stream;

        unset($this->readable[$id], $this->readHandlers[$id]);
    }

    public function removeWritable(mixed $stream): void
    {
        $id = (int) $stream;

        unset($this->writable[$id], $this->writeHandlers[$id]);
    }

    public function addTimer(float $delay, Closure $handler): int
    {
        return $this->scheduleTimer(new Timer($this->nextTimerId++, microtime(true) + $delay, null, $handler));
    }

    public function every(float $interval, Closure $handler): int
    {
        return $this->scheduleTimer(new Timer($this->nextTimerId++, microtime(true) + $interval, $interval, $handler));
    }

    public function cancelTimer(int $timerId): void
    {
        if (isset($this->timers[$timerId])) {
            $this->timers[$timerId]->cancel();
        }
    }

    public function run(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        while ($this->running) {
            if (!$this->hasWork()) {
                // Nothing left to wait for — no streams, no live timers.
                // A server always keeps its listening socket watched, so it
                // blocks in select() instead of reaching this branch.
                $this->running = false;
                break;
            }

            $timeout = $this->secondsUntilNearestTimer();

            [$readyToRead, $readyToWrite] = $this->waitForStreams($timeout, $this->hasWatchedStreams());

            foreach ($readyToRead as $id => $stream) {
                $this->dispatchRead($id, $stream);
            }

            foreach ($readyToWrite as $id => $stream) {
                $this->dispatchWrite($id, $stream);
            }

            $this->runDueTimers();
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function watchedReadableCount(): int
    {
        return count($this->readable);
    }

    public function watchedWritableCount(): int
    {
        return count($this->writable);
    }

    public function timerCount(): int
    {
        return count($this->timers);
    }

    private function scheduleTimer(Timer $timer): int
    {
        $this->timers[$timer->id] = $timer;

        return $timer->id;
    }

    /**
     * @return float|null seconds until the nearest live timer, or null when none
     */
    private function secondsUntilNearestTimer(): ?float
    {
        $nearest = null;

        foreach ($this->timers as $timer) {
            if ($timer->cancelled()) {
                continue;
            }

            $nearest = $nearest === null ? $timer->dueAt : min($nearest, $timer->dueAt);
        }

        if ($nearest === null) {
            return null;
        }

        return max(0.0, $nearest - microtime(true));
    }

    private function hasWatchedStreams(): bool
    {
        return $this->readable !== [] || $this->writable !== [];
    }

    /**
     * True when there is at least one stream to watch or one live timer.
     * Cancelled timers are swept out here so they never block an exit.
     */
    private function hasWork(): bool
    {
        if ($this->readable !== [] || $this->writable !== []) {
            return true;
        }

        foreach ($this->timers as $id => $timer) {
            if ($timer->cancelled()) {
                unset($this->timers[$id]);
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Non-blocking wait via stream_select().
     *
     * stream_select() needs at least one non-empty array, so with only
     * timers left we sleep instead and let the timer sweep re-run.
     *
     * @return array{0: array<int, resource>, 1: array<int, resource>}
     */
    private function waitForStreams(?float $timeout, bool $hasStreams): array
    {
        if (!$hasStreams) {
            if ($timeout !== null && $timeout > 0.0) {
                usleep((int) ($timeout * 1_000_000));
            }

            return [[], []];
        }

        $read = array_values($this->readable);
        $write = array_values($this->writable);
        $except = [];

        [$seconds, $microseconds] = $this->splitTimeout($timeout);

        try {
            // Being interrupted by a handled signal is normal for select().
            // So is a stream that was closed between watcher collection and
            // this call: PHP cannot build a valid descriptor set for a
            // resource that no longer exists. Neither condition is a loop
            // failure — drop stale watchers and re-wait on the next pass.
            @stream_select($read, $write, $except, $seconds, $microseconds);
        } catch (\ValueError|\TypeError) {
            // A watched stream vanished between loop iterations — drop the dead ones.
            $this->dropClosedStreams();

            return [[], []];
        }

        return [$this->indexByObjectId($read), $this->indexByObjectId($write)];
    }

    /**
     * stream_select() takes integer seconds + integer microseconds,
     * so a fractional timeout is converted with ceil to avoid a 0-second wait.
     *
     * @return array{0: int, 1: int}
     */
    private function splitTimeout(?float $timeout): array
    {
        if ($timeout === null) {
            return [self::IDLE_WAIT_SECONDS, 0];
        }

        if ($timeout <= 0.0) {
            return [0, 0];
        }

        $seconds = (int) $timeout;
        $microseconds = (int) ceil(($timeout - $seconds) * 1_000_000);

        return [$seconds, $microseconds];
    }

    private function dispatchRead(int $id, mixed $stream): void
    {
        if (!isset($this->readHandlers[$id])) {
            return;
        }

        ($this->readHandlers[$id])($stream);
    }

    private function dispatchWrite(int $id, mixed $stream): void
    {
        if (!isset($this->writeHandlers[$id])) {
            return;
        }

        ($this->writeHandlers[$id])($stream);
    }

    private function runDueTimers(): void
    {
        $now = microtime(true);

        foreach ($this->timers as $id => $timer) {
            if ($timer->cancelled()) {
                unset($this->timers[$id]);
                continue;
            }

            if ($timer->dueAt > $now) {
                continue;
            }

            $timer->fire();

            if ($timer->isPeriodic()) {
                $timer->reschedule($now);
            } else {
                unset($this->timers[$id]);
            }
        }
    }

    /**
     * Remove closed streams from the watch lists.
     */
    private function dropClosedStreams(): void
    {
        foreach ($this->readable as $id => $stream) {
            if (!is_resource($stream)) {
                unset($this->readable[$id], $this->readHandlers[$id]);
            }
        }

        foreach ($this->writable as $id => $stream) {
            if (!is_resource($stream)) {
                unset($this->writable[$id], $this->writeHandlers[$id]);
            }
        }
    }

    /**
     * @param list<resource> $streams
     *
     * @return array<int, resource>
     */
    private function indexByObjectId(array $streams): array
    {
        $indexed = [];

        foreach ($streams as $stream) {
            $indexed[(int) $stream] = $stream;
        }

        return $indexed;
    }

    private function assertStream(mixed $stream): void
    {
        if (is_resource($stream) && get_resource_type($stream) === 'stream') {
            return;
        }

        throw new ValueError('Event loop watchers require an open stream resource.');
    }
}