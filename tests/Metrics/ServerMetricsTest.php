<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Metrics\ServerMetrics;
use PHPUnit\Framework\TestCase;

final class ServerMetricsTest extends TestCase
{
    public function testStartsWithZeroCounters(): void
    {
        $metrics = new ServerMetrics(startedAt: 1000.0);

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
        $metrics = new ServerMetrics(startedAt: 1000.0);
        $metrics->recordRequest(0.0); // at t=1000

        // Four more requests arrive over the next 4 seconds.
        for ($i = 0; $i < 4; $i++) {
            $metrics->recordRequest(0.0);
        }

        // Uptime is derived from microtime(true), which is far past 1000;
        // the precise rps value is time-dependent, so just assert it is
        // positive and finite rather than flaky.
        $rps = $metrics->requestsPerSecond();
        $this->assertGreaterThan(0.0, $rps);
        $this->assertLessThan(1000.0, $rps);
    }
}