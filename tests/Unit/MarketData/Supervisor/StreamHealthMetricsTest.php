<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Supervisor;

use App\Infrastructure\MarketData\Supervisor\StreamHealthMetrics;
use PHPUnit\Framework\TestCase;

class StreamHealthMetricsTest extends TestCase
{
    public function test_computes_percentiles_and_refresh_rate(): void
    {
        $metrics = new StreamHealthMetrics(windowSize: 10);

        $metrics->observe(sourceTimestampMs: 1000, ingestTimestampMs: 1010); // lat 10
        $metrics->observe(sourceTimestampMs: 1100, ingestTimestampMs: 1115); // inter 105, lat 15
        $metrics->observe(sourceTimestampMs: 1200, ingestTimestampMs: 1220); // inter 105, lat 20
        $metrics->observe(sourceTimestampMs: 1300, ingestTimestampMs: 1330); // inter 110, lat 30

        $summary = $metrics->summary();

        $this->assertSame(4, $summary['messages_total']);
        $this->assertSame(1330, $summary['last_ingest_at']);
        $this->assertSame(1300, $summary['last_source_at']);
        $this->assertSame(105, $summary['inter_arrival_ms']['p50']);
        $this->assertSame(110, $summary['inter_arrival_ms']['p99']);
        $this->assertSame(15, $summary['source_to_ingest_ms']['p50']);
        $this->assertNotNull($summary['estimated_refresh_hz']);
    }
}
