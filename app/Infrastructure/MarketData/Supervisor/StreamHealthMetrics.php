<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Supervisor;

final class StreamHealthMetrics
{
    private int $totalMessages = 0;

    private ?int $firstIngestAtMs = null;

    private ?int $lastIngestAtMs = null;

    private ?int $lastSourceAtMs = null;

    /**
     * @var array<int, int>
     */
    private array $interArrivalMs = [];

    /**
     * @var array<int, int>
     */
    private array $sourceToIngestLatencyMs = [];

    public function __construct(private readonly int $windowSize = 512)
    {
    }

    public function observe(int $sourceTimestampMs, int $ingestTimestampMs): void
    {
        $this->totalMessages++;
        $this->firstIngestAtMs ??= $ingestTimestampMs;

        if ($this->lastIngestAtMs !== null) {
            $this->pushWindowValue($this->interArrivalMs, max(0, $ingestTimestampMs - $this->lastIngestAtMs));
        }

        $this->pushWindowValue($this->sourceToIngestLatencyMs, max(0, $ingestTimestampMs - $sourceTimestampMs));
        $this->lastIngestAtMs = $ingestTimestampMs;
        $this->lastSourceAtMs = $sourceTimestampMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $p50Inter = $this->percentile($this->interArrivalMs, 50);
        $p95Inter = $this->percentile($this->interArrivalMs, 95);
        $p99Inter = $this->percentile($this->interArrivalMs, 99);

        $p50Lat = $this->percentile($this->sourceToIngestLatencyMs, 50);
        $p95Lat = $this->percentile($this->sourceToIngestLatencyMs, 95);
        $p99Lat = $this->percentile($this->sourceToIngestLatencyMs, 99);

        return [
            'messages_total' => $this->totalMessages,
            'last_ingest_at' => $this->lastIngestAtMs,
            'last_source_at' => $this->lastSourceAtMs,
            'inter_arrival_ms' => [
                'p50' => $p50Inter,
                'p95' => $p95Inter,
                'p99' => $p99Inter,
            ],
            'source_to_ingest_ms' => [
                'p50' => $p50Lat,
                'p95' => $p95Lat,
                'p99' => $p99Lat,
            ],
            'estimated_refresh_hz' => $p50Inter !== null && $p50Inter > 0
                ? round(1000 / $p50Inter, 2)
                : null,
        ];
    }

    /**
     * @param array<int, int> $window
     */
    private function percentile(array $window, int $percent): ?int
    {
        if ($window === []) {
            return null;
        }

        sort($window);
        $index = (int) ceil(($percent / 100) * count($window)) - 1;
        $index = max(0, min($index, count($window) - 1));

        return $window[$index];
    }

    /**
     * @param array<int, int> $window
     */
    private function pushWindowValue(array &$window, int $value): void
    {
        $window[] = $value;
        if (count($window) > $this->windowSize) {
            array_shift($window);
        }
    }
}
