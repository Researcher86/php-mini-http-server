<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\EventLoop\SelectLoop;
use App\Metrics\LoopMetrics;
use PHPUnit\Framework\TestCase;

final class LoopMetricsTest extends TestCase
{
    public function testStartsAtZero(): void
    {
        $metrics = new LoopMetrics();

        $this->assertSame(0, $metrics->iterations());
        $this->assertSame(0.0, $metrics->busySeconds());
        $this->assertSame(0.0, $metrics->idleSeconds());
        $this->assertSame(0.0, $metrics->maxLagSeconds());
        $this->assertSame(0.0, $metrics->utilisation());
    }

    public function testMaxLagKeepsTheWorstPassNotTheLastOne(): void
    {
        $metrics = new LoopMetrics();

        $metrics->recordIteration(busySeconds: 0.001, idleSeconds: 0.5);
        $metrics->recordIteration(busySeconds: 0.250, idleSeconds: 0.1);
        $metrics->recordIteration(busySeconds: 0.002, idleSeconds: 0.4);

        $this->assertSame(3, $metrics->iterations());
        $this->assertSame(0.250, $metrics->maxLagSeconds());
        $this->assertEqualsWithDelta(0.253, $metrics->busySeconds(), 0.0001);
        $this->assertEqualsWithDelta(1.0, $metrics->idleSeconds(), 0.0001);
        $this->assertEqualsWithDelta(0.2018, $metrics->utilisation(), 0.001);
    }

    public function testASlowHandlerShowsUpAsLoopLag(): void
    {
        [$server, $client] = $this->socketPair();

        $loop = new SelectLoop();

        // The thing the README warns about, measured: one handler that
        // blocks for 50ms is 50ms during which no other connection on this
        // loop is looked at.
        $loop->onReadable($server, static function ($stream) use ($loop): void {
            fread($stream, 8192);
            usleep(50_000);
            $loop->stop();
        });

        fwrite($client, 'slow');
        $loop->run();

        $metrics = $loop->metrics();

        $this->assertGreaterThan(0, $metrics->iterations());
        $this->assertGreaterThanOrEqual(0.05, $metrics->maxLagSeconds());
        $this->assertLessThan(0.5, $metrics->maxLagSeconds());

        fclose($server);
        fclose($client);
    }

    public function testAnIdleLoopReportsItsWaitingAsIdle(): void
    {
        $loop = new SelectLoop();

        // Nothing to do but wait for a timer: the loop should account for
        // that as idle, not as work.
        $loop->addTimer(0.05, static fn () => $loop->stop());
        $loop->run();

        $metrics = $loop->metrics();

        $this->assertGreaterThan(0.0, $metrics->idleSeconds());
        $this->assertGreaterThan($metrics->busySeconds(), $metrics->idleSeconds());
        $this->assertLessThan(0.5, $metrics->utilisation());
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);

        return [$pair[0], $pair[1]];
    }
}
