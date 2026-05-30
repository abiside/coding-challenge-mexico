<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Arbitrage\Engine\DTO\OpportunityCandidate;
use App\Arbitrage\Engine\LiquidityCalculator;
use App\Arbitrage\MarketData\BookState;
use PHPUnit\Framework\TestCase;

class LiquidityCalculatorTest extends TestCase
{
    use ArbitrageTestFactory;

    public function test_walks_depth_and_computes_weighted_prices_with_partial_fill(): void
    {
        // Compra: asks 100x0.5, 101x0.5 ; Venta: bids 110x0.4, 108x0.6
        $buyBook = BookState::fromSnapshot(
            $this->snapshot('binance', 'BTC/USDT', [[90, 5]], [[100, 0.5], [101, 0.5]]),
            receivedAtMs: 1_000,
        );
        $sellBook = BookState::fromSnapshot(
            $this->snapshot('kraken', 'BTC/USDT', [[110, 0.4], [108, 0.6]], [[120, 5]]),
            receivedAtMs: 1_000,
        );

        $candidate = new OpportunityCandidate(
            symbol: 'BTC/USDT',
            buyBook: $buyBook,
            sellBook: $sellBook,
            buyAsk: 100.0,
            sellBid: 110.0,
            detectedAtMs: 1_000,
        );

        $calc = new LiquidityCalculator();
        $result = $calc->evaluate($candidate, targetBaseVolume: 1.0);

        // Profundidad total compra=1.0, venta=1.0 -> ejecutable 1.0, no parcial.
        $this->assertEqualsWithDelta(1.0, $result->executableBaseVolume, 1e-9);
        $this->assertFalse($result->partial);

        // Buy notional = 100*0.5 + 101*0.5 = 100.5 -> weighted 100.5
        $this->assertEqualsWithDelta(100.5, $result->weightedBuyPrice, 1e-9);
        // Sell notional = 110*0.4 + 108*0.6 = 44 + 64.8 = 108.8 -> weighted 108.8
        $this->assertEqualsWithDelta(108.8, $result->weightedSellPrice, 1e-9);
    }

    public function test_executable_limited_by_thinner_side(): void
    {
        $buyBook = BookState::fromSnapshot(
            $this->snapshot('binance', 'BTC/USDT', [[90, 5]], [[100, 0.2]]),
            receivedAtMs: 1_000,
        );
        $sellBook = BookState::fromSnapshot(
            $this->snapshot('kraken', 'BTC/USDT', [[110, 5]], [[120, 5]]),
            receivedAtMs: 1_000,
        );

        $candidate = new OpportunityCandidate('BTC/USDT', $buyBook, $sellBook, 100.0, 110.0, 1_000);

        $result = (new LiquidityCalculator())->evaluate($candidate, targetBaseVolume: 1.0);

        $this->assertEqualsWithDelta(0.2, $result->executableBaseVolume, 1e-9);
        $this->assertTrue($result->partial);
    }
}
