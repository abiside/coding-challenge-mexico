<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage\Triangular;

use App\Arbitrage\Engine\FeeSchedule;
use App\Arbitrage\MarketData\OrderBookStore;
use App\Arbitrage\Triangular\Engine\CycleLiquidityCalculator;
use App\Arbitrage\Triangular\Engine\CycleScanner;
use App\Arbitrage\Triangular\Graph\GraphBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Arbitrage\ArbitrageTestFactory;

class CycleLiquidityCalculatorTest extends TestCase
{
    use ArbitrageTestFactory;

    public function test_walks_legs_and_computes_amounts(): void
    {
        $store = new OrderBookStore;
        $now = 1_000;
        // Ciclo USDT->BTC->ETH->USDT en binance.
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[99, 10]], [[100, 10]]), receivedAtMs: $now);
        $store->apply($this->snapshot('binance', 'ETH/BTC', [[0.049, 100]], [[0.05, 100]]), receivedAtMs: $now);
        $updated = $store->apply($this->snapshot('binance', 'ETH/USDT', [[6, 100]], [[6.1, 100]]), receivedAtMs: $now);

        $fees = new FeeSchedule([], 0.0);
        $builder = new GraphBuilder($store, $fees, 5_000, crossExchange: false);
        $scanner = new CycleScanner($builder, ['USDT'], maxCycleLength: 3);
        $candidates = $scanner->scan($updated, nowMs: $now);

        // Tomamos el ciclo de 3 patas USDT->BTC->ETH->USDT (producto = 1.20).
        $cycle = null;
        foreach ($candidates as $c) {
            if ($c->length() === 3 && $c->startAsset() === 'USDT'
                && abs($c->netRateProduct - 1.20) < 1e-6) {
                $cycle = $c;
                break;
            }
        }
        $this->assertNotNull($cycle, 'esperaba el ciclo USDT->BTC->ETH->USDT con producto 1.20');

        $calc = new CycleLiquidityCalculator;
        // Con 100 USDT de inicio: 100/100=1 BTC, 1/0.05=20 ETH, 20*6=120 USDT.
        $result = $calc->evaluate($cycle, 100.0);

        $this->assertCount(3, $result->legs);
        $this->assertEqualsWithDelta(100.0, $result->startAmount, 1e-9);
        $this->assertEqualsWithDelta(120.0, $result->endAmount, 1e-9);
        $this->assertFalse($result->partial);
        $this->assertEqualsWithDelta(20.0, $result->deltaInStartAsset(), 1e-9);
    }

    public function test_caps_volume_by_thinnest_leg(): void
    {
        $store = new OrderBookStore;
        $now = 1_000;
        // Misma estructura, pero ETH/BTC con muy poca profundidad: solo 0.5 ETH
        // disponible al best ask.
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[99, 1000]], [[100, 1000]]), receivedAtMs: $now);
        $store->apply($this->snapshot('binance', 'ETH/BTC', [[0.049, 100]], [[0.05, 0.5]]), receivedAtMs: $now);
        $updated = $store->apply($this->snapshot('binance', 'ETH/USDT', [[6, 1000]], [[6.1, 1000]]), receivedAtMs: $now);

        $fees = new FeeSchedule([], 0.0);
        $builder = new GraphBuilder($store, $fees, 5_000, crossExchange: false);
        $scanner = new CycleScanner($builder, ['USDT'], maxCycleLength: 3);
        $candidates = $scanner->scan($updated, nowMs: $now);

        $cycle = null;
        foreach ($candidates as $c) {
            if ($c->length() === 3 && $c->startAsset() === 'USDT'
                && abs($c->netRateProduct - 1.20) < 1e-6) {
                $cycle = $c;
                break;
            }
        }
        $this->assertNotNull($cycle);

        // El cap en ETH/BTC ask: 0.5 ETH disponible cuesta 0.5*0.05 = 0.025 BTC.
        // Para entrar 0.025 BTC necesito 0.025 * 100 = 2.5 USDT.
        $calc = new CycleLiquidityCalculator;
        $result = $calc->evaluate($cycle, 1_000.0); // pedir mucho más del cap

        $this->assertTrue($result->partial);
        $this->assertEqualsWithDelta(2.5, $result->startAmount, 1e-6);
        // endAmount = 2.5 * 1.20 = 3.0
        $this->assertEqualsWithDelta(3.0, $result->endAmount, 1e-6);
    }
}
