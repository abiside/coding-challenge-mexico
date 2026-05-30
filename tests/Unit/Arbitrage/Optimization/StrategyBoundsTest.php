<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage\Optimization;

use App\Arbitrage\Optimization\StrategyBounds;
use PHPUnit\Framework\TestCase;

class StrategyBoundsTest extends TestCase
{
    public function test_clamps_values_outside_range_to_bounds(): void
    {
        $clamped = StrategyBounds::clamp([
            'min_net_profit' => -10.0,
            'min_net_margin' => 2.0,
            'min_base_volume' => 0.000000001,
            'max_base_volume' => 100000.0,
            'freshness_ms' => 50,
            'latency_max_ms' => 999999,
        ]);

        $ranges = StrategyBounds::ranges();
        $this->assertEquals($ranges['min_net_profit']['min'], $clamped['min_net_profit']);
        $this->assertEquals($ranges['min_net_margin']['max'], $clamped['min_net_margin']);
        $this->assertGreaterThanOrEqual($ranges['min_base_volume']['min'], $clamped['min_base_volume']);
        $this->assertEquals($ranges['max_base_volume']['max'], $clamped['max_base_volume']);
        $this->assertSame(100, $clamped['freshness_ms']);
        $this->assertSame((int) $ranges['latency_max_ms']['max'], $clamped['latency_max_ms']);
    }

    public function test_int_typed_fields_are_cast_to_int(): void
    {
        $clamped = StrategyBounds::clamp([
            'freshness_ms' => 2000.7,
            'latency_max_ms' => 1500.3,
        ]);

        $this->assertSame(2001, $clamped['freshness_ms']);
        $this->assertSame(1500, $clamped['latency_max_ms']);
    }

    public function test_ensures_min_volume_strictly_below_max(): void
    {
        $clamped = StrategyBounds::clamp([
            'min_base_volume' => 5.0,
            'max_base_volume' => 1.0,
        ]);

        $this->assertLessThan($clamped['max_base_volume'], $clamped['min_base_volume']);
    }

    public function test_apply_rewrites_only_provided_fields(): void
    {
        $base = [
            'symbols' => ['BTC/USDT'],
            'thresholds' => [
                'min_net_profit' => 1.0,
                'min_net_margin' => 0.0005,
                'min_base_volume' => 0.0001,
                'max_base_volume' => 1.0,
            ],
            'freshness_ms' => 2000,
            'latency' => ['max_ms' => 1500, 'penalty_per_ms' => 0.0],
            'fees' => ['default' => 0.001],
        ];

        $applied = StrategyBounds::apply($base, [
            'min_net_profit' => 2.5,
            'latency_max_ms' => 1200,
        ]);

        $this->assertSame(2.5, $applied['thresholds']['min_net_profit']);
        $this->assertSame(0.0005, $applied['thresholds']['min_net_margin']);
        $this->assertSame(1200, $applied['latency']['max_ms']);
        $this->assertSame(0.0, $applied['latency']['penalty_per_ms']);
        $this->assertSame(['BTC/USDT'], $applied['symbols']);
    }

    public function test_extract_returns_floats_for_all_mutable_fields(): void
    {
        $config = [
            'thresholds' => [
                'min_net_profit' => 1.5,
                'min_net_margin' => 0.001,
                'min_base_volume' => 0.0002,
                'max_base_volume' => 0.5,
            ],
            'freshness_ms' => 1800,
            'latency' => ['max_ms' => 1400],
        ];

        $params = StrategyBounds::extract($config);

        $this->assertSame(1.5, $params['min_net_profit']);
        $this->assertSame(0.001, $params['min_net_margin']);
        $this->assertSame(1800.0, $params['freshness_ms']);
        $this->assertSame(1400.0, $params['latency_max_ms']);
    }
}
