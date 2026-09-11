<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Metrics\ServerMetrics;
use App\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class ServerMetricsTest extends TestCase
{
    public function testStartsWithZeroCounters(): void
    {
        $metrics = new ServerMetrics(new FakeClock(1000.0));

        $this->assertSame(0, $metrics->totalRequests());
        $this->assertSame(0, $metrics->bytesRead());
        $this->assertSame(0, $metrics->bytesWritten());
        $this->assertSame(0.0, $metrics->requestsPerSecond());
        $this->assertSame(0.0, $metrics->averageRequestDuration());
    }

    public function testRecordsRequestsAndDuration(): void
    {
        $metrics = new ServerMetrics();
        $metrics->recordRequest(0.010);
        $metrics->recordRequest(0.020);

        $this->assertSame(2, $metrics->totalRequests());
        $this->assertSame(0.015, $metrics->averageRequestDuration());
    }

    public function testRecordsBytes(): void
    {
        $metrics = new ServerMetrics();
        $metrics->recordBytesRead(100);
        $metrics->recordBytesRead(50);
        $metrics->recordBytesWritten(1000);

        $this->assertSame(150, $metrics->bytesRead());
        $this->assertSame(1000, $metrics->bytesWritten());
    }

    public function testRequestsPerSecondUsesUptime(): void
    {
        $clock = new FakeClock(1000.0);
        $metrics = new ServerMetrics($clock);

        // Ten requests over five seconds of uptime is two per second — an
        // exact number now that the clock is the test's to move.
        for ($i = 0; $i < 10; $i++) {
            $metrics->recordRequest(0.0);
        }

        $clock->advance(5.0);

        $this->assertSame(5.0, $metrics->uptimeSeconds());
        $this->assertSame(2.0, $metrics->requestsPerSecond());
    }
}
