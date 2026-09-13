<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\EventLoop;

use Closure;

/**
 * The seam the whole server runs on.
 *
 * The loop owns the "wait, then react" cycle:
 *
 *     while (running) {
 *         events = waitForEvents();      // stream_select + timers
 *         foreach (events as event) {
 *             handle(event);
 *         }
 *     }
 *
 * A loop must multiplex:
 *
 *     Read Events    some stream has data or a client is connecting
 *     Write Events   some stream can accept more bytes
 *     Timers         wall-clock work not tied to any stream
 *
 * @param resource $stream
 */
interface EventLoop
{
    public function onReadable(mixed $stream, Closure $handler): void;

    public function onWritable(mixed $stream, Closure $handler): void;

    public function removeReadable(mixed $stream): void;

    public function removeWritable(mixed $stream): void;

    /**
     * Fire $handler once after $delay seconds.
     *
     * Returns a timer id usable with {@see cancelTimer()}.
     *
     * @param Closure(): void $handler
     */
    public function addTimer(float $delay, Closure $handler): int;

    /**
     * Fire $handler every $interval seconds until cancelled.
     *
     * @param Closure(): void $handler
     */
    public function every(float $interval, Closure $handler): int;

    public function cancelTimer(int $timerId): void;

    public function run(): void;

    public function stop(): void;

    public function isRunning(): bool;
}
