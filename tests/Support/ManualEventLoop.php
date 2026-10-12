<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\Support;

use Closure;
use LogicException;
use PhpMiniHttpServer\EventLoop\EventLoop;

/**
 * An EventLoop that never waits: it records the watchers it is given, and a
 * test fires them by hand.
 *
 * select() only reports a stream once the kernel says it is ready, so a real
 * loop cannot show what a handler does with a readiness that turns out to be
 * empty. This one can — it hands the handler the stream whenever asked.
 */
final class ManualEventLoop implements EventLoop
{
    /** @var array<int, Closure> keyed by stream object id */
    private array $readHandlers = [];

    /** @var array<int, Closure> keyed by stream object id */
    private array $writeHandlers = [];

    private bool $running = false;

    public function onReadable(mixed $stream, Closure $handler): void
    {
        $this->readHandlers[(int) $stream] = $handler;
    }

    public function onWritable(mixed $stream, Closure $handler): void
    {
        $this->writeHandlers[(int) $stream] = $handler;
    }

    public function removeReadable(mixed $stream): void
    {
        unset($this->readHandlers[(int) $stream]);
    }

    public function removeWritable(mixed $stream): void
    {
        unset($this->writeHandlers[(int) $stream]);
    }

    public function addTimer(float $delay, Closure $handler): int
    {
        throw new LogicException('ManualEventLoop has no timers.');
    }

    public function every(float $interval, Closure $handler): int
    {
        throw new LogicException('ManualEventLoop has no timers.');
    }

    public function cancelTimer(int $timerId): void
    {
    }

    public function run(): void
    {
        $this->running = true;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function isWatchingReadable(mixed $stream): bool
    {
        return isset($this->readHandlers[(int) $stream]);
    }

    /**
     * Call $stream's read handler as if select() had reported it ready.
     */
    public function fireReadable(mixed $stream): void
    {
        $handler = $this->readHandlers[(int) $stream] ?? throw new LogicException('Stream is not watched for reads.');
        $handler($stream);
    }
}
