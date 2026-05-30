<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Arbitrage\Realtime\MetricsAggregator;
use App\Arbitrage\Risk\Decision;
use PHPUnit\Framework\TestCase;

class MetricsAggregatorTest extends TestCase
{
    public function test_accumulates_and_drains_window_snapshot(): void
    {
        $start = 1_000_000;
        $metrics = new MetricsAggregator($start);

        $metrics->recordSnapshot();
        $metrics->recordSnapshot();
        $metrics->recordCandidate();
        $metrics->recordCandidate();
        $metrics->recordDecision(Decision::Execute);
        $metrics->recordDecision(Decision::Reject);
        $metrics->recordExecution(pnl: 5.0, baseVolume: 0.1, margin: 0.002);
        $metrics->recordExecution(pnl: 3.0, baseVolume: 0.2, margin: 0.001);

        $end = 1_060_000;
        $window = $metrics->drain($end);

        $this->assertSame($start, $window['window_start_ms']);
        $this->assertSame($end, $window['window_end_ms']);
        $this->assertSame(2, $window['snapshots']);
        $this->assertSame(2, $window['candidates']);
        $this->assertSame(2, $window['executions']);
        $this->assertSame(1, $window['rejects']);
        $this->assertEqualsWithDelta(8.0, $window['realized_pnl'], 1e-9);
        $this->assertEqualsWithDelta(0.3, $window['executed_volume'], 1e-9);
        $this->assertEqualsWithDelta(0.0015, $window['avg_margin'], 1e-9);
    }

    public function test_drain_resets_state_and_advances_window(): void
    {
        $metrics = new MetricsAggregator(1_000_000);
        $metrics->recordSnapshot();
        $metrics->recordExecution(2.0, 0.1, 0.001);
        $metrics->drain(1_060_000);

        // Tras drain todo queda en 0 y el siguiente window_start es el end del anterior.
        $second = $metrics->drain(1_120_000);
        $this->assertSame(1_060_000, $second['window_start_ms']);
        $this->assertSame(1_120_000, $second['window_end_ms']);
        $this->assertSame(0, $second['snapshots']);
        $this->assertSame(0.0, $second['realized_pnl']);
    }

    public function test_avg_margin_is_zero_without_executions(): void
    {
        $metrics = new MetricsAggregator;
        $metrics->recordSnapshot();
        $this->assertSame(0.0, $metrics->avgMargin());
    }

    public function test_discard_funnel_aggregates_sorted_and_resets_on_drain(): void
    {
        $metrics = new MetricsAggregator(1_000_000);
        $metrics->recordDiscard('not_crossed');
        $metrics->recordDiscard('not_crossed');
        $metrics->recordDiscard('not_crossed');
        $metrics->recordDiscard('risk:low_net_profit');

        $discards = $metrics->discards();
        $this->assertSame(['not_crossed' => 3, 'risk:low_net_profit' => 1], $discards);
        // La razón más frecuente queda primero (orden descendente).
        $this->assertSame('not_crossed', array_key_first($discards));

        $window = $metrics->drain(1_060_000);
        $this->assertSame(['not_crossed' => 3, 'risk:low_net_profit' => 1], $window['discards']);

        // Tras drain el embudo se reinicia.
        $this->assertSame([], $metrics->discards());
    }
}
