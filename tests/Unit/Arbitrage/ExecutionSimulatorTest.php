<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Engine\DTO\LiquidityResult;
use App\Arbitrage\Engine\DTO\OpportunityCandidate;
use App\Arbitrage\Engine\DTO\ProfitabilityResult;
use App\Arbitrage\Execution\ExecutionSimulator;
use App\Arbitrage\Execution\WalletManager;
use App\Arbitrage\MarketData\BookState;
use PHPUnit\Framework\TestCase;

class ExecutionSimulatorTest extends TestCase
{
    use ArbitrageTestFactory;

    private function evaluated(): EvaluatedOpportunity
    {
        $buyBook = BookState::fromSnapshot($this->snapshot('binance', 'BTC/USDT', [[99, 5]], [[100, 5]]), 1_000);
        $sellBook = BookState::fromSnapshot($this->snapshot('kraken', 'BTC/USDT', [[110, 5]], [[111, 5]]), 1_000);
        $candidate = new OpportunityCandidate('BTC/USDT', $buyBook, $sellBook, 100.0, 110.0, 1_000);

        $liquidity = new LiquidityResult(1.0, 100.0, 110.0, 100.0, 110.0, false);
        $profit = new ProfitabilityResult(1.0, 10.0, 0.1, 0.22, 0.0, 0.0, 9.68, 100.0);

        return new EvaluatedOpportunity($candidate, $liquidity, $profit);
    }

    public function test_simulation_moves_balances_across_both_legs(): void
    {
        $wallets = new WalletManager([
            'binance' => ['USDT' => 1000.0, 'BTC' => 0.0],
            'kraken' => ['USDT' => 0.0, 'BTC' => 2.0],
        ]);
        $simulator = new ExecutionSimulator($wallets);

        $result = $simulator->simulate($this->evaluated(), 'op-1');

        // Binance: -100 - 0.1 fee USDT, +1 BTC
        $this->assertEqualsWithDelta(899.9, $wallets->available('binance', 'USDT'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $wallets->available('binance', 'BTC'), 1e-9);
        // Kraken: -1 BTC, +110 - 0.22 fee USDT
        $this->assertEqualsWithDelta(1.0, $wallets->available('kraken', 'BTC'), 1e-9);
        $this->assertEqualsWithDelta(109.78, $wallets->available('kraken', 'USDT'), 1e-9);

        $this->assertFalse($result->duplicate);
        $this->assertEqualsWithDelta(9.68, $result->realizedPnl, 1e-9);
    }

    public function test_idempotency_prevents_double_execution(): void
    {
        $wallets = new WalletManager([
            'binance' => ['USDT' => 1000.0, 'BTC' => 0.0],
            'kraken' => ['USDT' => 0.0, 'BTC' => 2.0],
        ]);
        $simulator = new ExecutionSimulator($wallets);

        $simulator->simulate($this->evaluated(), 'op-1');
        $second = $simulator->simulate($this->evaluated(), 'op-1');

        $this->assertTrue($second->duplicate);
        // Balances no cambian en el segundo intento.
        $this->assertEqualsWithDelta(899.9, $wallets->available('binance', 'USDT'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $wallets->available('binance', 'BTC'), 1e-9);
    }
}
