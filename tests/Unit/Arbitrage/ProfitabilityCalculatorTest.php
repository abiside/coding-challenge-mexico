<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Arbitrage\Engine\DTO\LiquidityResult;
use App\Arbitrage\Engine\DTO\OpportunityCandidate;
use App\Arbitrage\Engine\FeeSchedule;
use App\Arbitrage\Engine\ProfitabilityCalculator;
use App\Arbitrage\MarketData\BookState;
use PHPUnit\Framework\TestCase;

class ProfitabilityCalculatorTest extends TestCase
{
    use ArbitrageTestFactory;

    private function candidate(): OpportunityCandidate
    {
        $buyBook = BookState::fromSnapshot($this->snapshot('binance', 'BTC/USDT', [[99, 1]], [[100, 1]]), 1_000);
        $sellBook = BookState::fromSnapshot($this->snapshot('kraken', 'BTC/USDT', [[110, 1]], [[111, 1]]), 1_000);

        return new OpportunityCandidate('BTC/USDT', $buyBook, $sellBook, 100.0, 110.0, 1_000);
    }

    public function test_net_profit_discounts_fees(): void
    {
        $liquidity = new LiquidityResult(
            executableBaseVolume: 1.0,
            weightedBuyPrice: 100.0,
            weightedSellPrice: 110.0,
            buyNotional: 100.0,
            sellNotional: 110.0,
            partial: false,
        );

        $fees = new FeeSchedule(['binance' => 0.001, 'kraken' => 0.002], 0.001);
        $calc = new ProfitabilityCalculator($fees, latencyPenaltyPerMs: 0.0, fixedCost: 0.0);

        $result = $calc->evaluate($this->candidate(), $liquidity, combinedAgeMs: 0);

        // gross = 110 - 100 = 10
        $this->assertEqualsWithDelta(10.0, $result->grossProfit, 1e-9);
        // buyFee = 100*0.001 = 0.1 ; sellFee = 110*0.002 = 0.22
        $this->assertEqualsWithDelta(0.1, $result->buyFee, 1e-9);
        $this->assertEqualsWithDelta(0.22, $result->sellFee, 1e-9);
        // net = 10 - 0.1 - 0.22 = 9.68
        $this->assertEqualsWithDelta(9.68, $result->netProfit, 1e-9);
        $this->assertTrue($result->isProfitable());
    }

    public function test_latency_penalty_and_fixed_cost_reduce_net(): void
    {
        $liquidity = new LiquidityResult(1.0, 100.0, 110.0, 100.0, 110.0, false);
        $fees = new FeeSchedule([], 0.0);
        $calc = new ProfitabilityCalculator($fees, latencyPenaltyPerMs: 0.01, fixedCost: 2.0);

        $result = $calc->evaluate($this->candidate(), $liquidity, combinedAgeMs: 100);

        // gross 10 - latency (100*0.01=1) - fixed 2 = 7
        $this->assertEqualsWithDelta(1.0, $result->latencyPenalty, 1e-9);
        $this->assertEqualsWithDelta(7.0, $result->netProfit, 1e-9);
    }
}
