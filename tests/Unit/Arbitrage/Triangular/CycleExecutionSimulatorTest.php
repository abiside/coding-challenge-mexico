<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage\Triangular;

use App\Arbitrage\Engine\FeeSchedule;
use App\Arbitrage\Execution\WalletManager;
use App\Arbitrage\MarketData\OrderBookStore;
use App\Arbitrage\Triangular\DTO\EvaluatedCycle;
use App\Arbitrage\Triangular\DTO\CycleProfitabilityResult;
use App\Arbitrage\Triangular\Engine\CycleLiquidityCalculator;
use App\Arbitrage\Triangular\Engine\CycleScanner;
use App\Arbitrage\Triangular\Execution\CycleExecutionSimulator;
use App\Arbitrage\Triangular\Graph\GraphBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Arbitrage\ArbitrageTestFactory;

class CycleExecutionSimulatorTest extends TestCase
{
    use ArbitrageTestFactory;

    public function test_deltas_apply_atomically_and_close_cycle(): void
    {
        $store = new OrderBookStore;
        $now = 1_000;
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[99, 10]], [[100, 10]]), receivedAtMs: $now);
        $store->apply($this->snapshot('binance', 'ETH/BTC', [[0.049, 100]], [[0.05, 100]]), receivedAtMs: $now);
        $updated = $store->apply($this->snapshot('binance', 'ETH/USDT', [[6, 100]], [[6.1, 100]]), receivedAtMs: $now);

        $fees = new FeeSchedule([], 0.0);
        $builder = new GraphBuilder($store, $fees, 5_000, crossExchange: false);
        $scanner = new CycleScanner($builder, ['USDT'], maxCycleLength: 3);
        $candidates = $scanner->scan($updated, nowMs: $now);

        $candidate = null;
        foreach ($candidates as $c) {
            if ($c->length() === 3 && abs($c->netRateProduct - 1.20) < 1e-6) {
                $candidate = $c;
                break;
            }
        }
        $this->assertNotNull($candidate);

        $liquidity = (new CycleLiquidityCalculator)->evaluate($candidate, 100.0);
        $evaluated = new EvaluatedCycle(
            candidate: $candidate,
            liquidity: $liquidity,
            profitability: new CycleProfitabilityResult(
                startAmount: 100.0,
                endAmount: 120.0,
                grossProfit: 20.0,
                totalFeesInStart: 0.0,
                latencyPenalty: 0.0,
                fixedCost: 0.0,
                netProfit: 20.0,
            ),
        );

        $wallets = new WalletManager([
            'binance' => ['USDT' => 100.0, 'BTC' => 0.0, 'ETH' => 0.0],
        ]);

        $simulator = new CycleExecutionSimulator($wallets);
        $result = $simulator->simulate($evaluated, 'idem-1');

        $this->assertFalse($result->duplicate);
        $this->assertEqualsWithDelta(100.0, $result->startAmount, 1e-9);
        $this->assertEqualsWithDelta(120.0, $result->endAmount, 1e-9);
        $this->assertEqualsWithDelta(20.0, $result->realizedPnl, 1e-9);

        // Tras el ciclo: USDT pasa de 100 a 120; BTC y ETH vuelven a 0.
        $this->assertEqualsWithDelta(120.0, $wallets->available('binance', 'USDT'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $wallets->available('binance', 'BTC'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $wallets->available('binance', 'ETH'), 1e-9);
    }

    public function test_idempotency_prevents_double_application(): void
    {
        $store = new OrderBookStore;
        $now = 1_000;
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[99, 10]], [[100, 10]]), receivedAtMs: $now);
        $store->apply($this->snapshot('binance', 'ETH/BTC', [[0.049, 100]], [[0.05, 100]]), receivedAtMs: $now);
        $updated = $store->apply($this->snapshot('binance', 'ETH/USDT', [[6, 100]], [[6.1, 100]]), receivedAtMs: $now);

        $fees = new FeeSchedule([], 0.0);
        $builder = new GraphBuilder($store, $fees, 5_000, crossExchange: false);
        $scanner = new CycleScanner($builder, ['USDT'], maxCycleLength: 3);
        $candidates = $scanner->scan($updated, nowMs: $now);

        $candidate = null;
        foreach ($candidates as $c) {
            if ($c->length() === 3 && abs($c->netRateProduct - 1.20) < 1e-6) {
                $candidate = $c;
                break;
            }
        }
        $this->assertNotNull($candidate);

        $liquidity = (new CycleLiquidityCalculator)->evaluate($candidate, 100.0);
        $evaluated = new EvaluatedCycle(
            candidate: $candidate,
            liquidity: $liquidity,
            profitability: new CycleProfitabilityResult(100.0, 120.0, 20.0, 0.0, 0.0, 0.0, 20.0),
        );

        $wallets = new WalletManager([
            'binance' => ['USDT' => 1_000.0, 'BTC' => 0.0, 'ETH' => 0.0],
        ]);

        $simulator = new CycleExecutionSimulator($wallets);
        $simulator->simulate($evaluated, 'idem-42');
        $balanceAfterFirst = $wallets->available('binance', 'USDT');

        $second = $simulator->simulate($evaluated, 'idem-42');
        $this->assertTrue($second->duplicate);
        $this->assertSame($balanceAfterFirst, $wallets->available('binance', 'USDT'));
    }
}
