<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * A tiny observability counter for the running server.
 *
 * Handlers report into it at the edges — request handled, bytes read,
 * bytes written — and the /metrics route (or a periodic dump) reads the
 * aggregated numbers back out. None of the counters are atomic and the
 * process is single-threaded, so plain ints are safe.
 */
final class ServerMetrics
{
    private readonly float $startedAt;

    private int $totalRequests = 0;

    private int $totalBytesRead = 0;

    private int $totalBytesWritten = 0;

    private float $totalRequestDuration = 0.0;

    public function __construct(?float $startedAt = null)
    {
        $this->startedAt = $startedAt ?? microtime(true);
    }

    public function recordRequest(float $durationSeconds): void
    {
        $this->totalRequests++;
        $this->totalRequestDuration += $durationSeconds;
    }

    public function recordBytesRead(int $bytes): void
    {
        $this->totalBytesRead += $bytes;
    }

    public function recordBytesWritten(int $bytes): void
    {
        $this->totalBytesWritten += $bytes;
    }

    public function totalRequests(): int
    {
        return $this->totalRequests;
    }

    public function bytesRead(): int
    {
        return $this->totalBytesRead;
    }

    public function bytesWritten(): int
    {
        return $this->totalBytesWritten;
    }

    public function uptimeSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function requestsPerSecond(): float
    {
        if ($this->totalRequests === 0) {
            return 0.0;
        }

        return $this->totalRequests / $this->uptimeSeconds();
    }

    public function averageRequestDuration(): float
    {
        if ($this->totalRequests === 0) {
            return 0.0;
        }

        return $this->totalRequestDuration / $this->totalRequests;
    }
}