<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage\Triangular;

use App\Arbitrage\Engine\FeeSchedule;
use App\Arbitrage\MarketData\OrderBookStore;
use App\Arbitrage\Triangular\Engine\CycleScanner;
use App\Arbitrage\Triangular\Graph\GraphBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Arbitrage\ArbitrageTestFactory;

class CycleScannerTest extends TestCase
{
    use ArbitrageTestFactory;

    public function test_detects_profitable_intra_exchange_cycle(): void
    {
        $store = new OrderBookStore;
        $now = 1_000;

        // Intra-exchange en binance: USDT -> BTC -> ETH -> USDT.
        // Configurado para que el ciclo sea rentable a tasas brutas:
        //   buy BTC @ 100 USDT (rate = 1/100 BTC/USDT)
        //   sell ETH/BTC @ 0.05 (rate = 0.05 BTC/ETH, ie 1 BTC -> 20 ETH)
        //   sell ETH/USDT @ 6 USDT/ETH (rate = 6)
        // Producto bruto = (1/100) * 20 * 6 = 1.20  (20% rentable)
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[99, 10]], [[100, 10]]), receivedAtMs: $now);
        // ETH/BTC: best ask 0.05 BTC por ETH -> al comprar ETH gastando BTC,
        // tasa = 1/0.05 = 20 ETH/BTC. Para que el ciclo BTC->ETH use SELL en
        // ETH/BTC, gastamos BTC y recibimos ETH... pero eso es BUY de ETH.
        // Para que el flujo sea BTC -> ETH consumiendo el ask de ETH/BTC,
        // la pata es TradeBuy en ETH/BTC: gasta QUOTE=BTC, recibe BASE=ETH.
        $store->apply($this->snapshot('binance', 'ETH/BTC', [[0.049, 100]], [[0.05, 100]]), receivedAtMs: $now);
        // ETH/USDT: best bid 6 USDT por ETH. Para ETH -> USDT, pata SELL:
        // gasta BASE=ETH, recibe QUOTE=USDT.
        $updated = $store->apply($this->snapshot('binance', 'ETH/USDT', [[6, 100]], [[6.1, 100]]), receivedAtMs: $now);

        $fees = new FeeSchedule([], 0.0); // sin fee para razonar más fácil
        $builder = new GraphBuilder($store, $fees, freshnessMs: 5_000, crossExchange: false);
        $scanner = new CycleScanner($builder, startAssets: ['USDT'], maxCycleLength: 3);

        $candidates = $scanner->scan($updated, nowMs: $now);

        $this->assertNotEmpty($candidates, 'esperaba al menos un ciclo rentable');

        // Encontramos el ciclo USDT->BTC->ETH->USDT con producto ~1.20.
        $best = null;
        foreach ($candidates as $cand) {
            if ($cand->startAsset() === 'USDT' && $cand->length() === 3
                && (! $best || $cand->netRateProduct > $best->netRateProduct)) {
                $best = $cand;
            }
        }
        $this->assertNotNull($best);
        $this->assertEqualsWithDelta(1.20, $best->netRateProduct, 1e-6);
        $this->assertSame('binance', $best->startExchange());
    }

    public function test_rejects_unprofitable_cycles(): void
    {
        $store = new OrderBookStore;
        $now = 1_000;

        // Precios consistentes en los tres pares (sin discrepancias entre ellos)
        // → ningún ciclo cierra rentable. Spreads bid-ask de 0.1% por par.
        //   BTC/USDT: 100 USDT por BTC, spread 0.1%
        //   ETH/BTC:  0.02 BTC por ETH (≈ 50 ETH por BTC), spread 0.05%
        //   ETH/USDT: 2 USDT por ETH (consistente con lo anterior)
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[100.0, 10]], [[100.1, 10]]), receivedAtMs: $now);
        $store->apply($this->snapshot('binance', 'ETH/BTC', [[0.02, 100]], [[0.02001, 100]]), receivedAtMs: $now);
        $updated = $store->apply($this->snapshot('binance', 'ETH/USDT', [[2.0, 100]], [[2.001, 100]]), receivedAtMs: $now);

        $fees = new FeeSchedule([], 0.0);
        $builder = new GraphBuilder($store, $fees, freshnessMs: 5_000, crossExchange: false);
        $scanner = new CycleScanner($builder, startAssets: ['USDT'], maxCycleLength: 3);

        $candidates = $scanner->scan($updated, nowMs: $now);

        // No debe haber ningún ciclo con netRateProduct > 1. El scanner emite
        // SOLO los rentables; si los hay, falla el test.
        $this->assertSame([], $candidates, 'no debería emitir ciclos cuando los precios son consistentes');
    }

    public function test_no_candidates_without_anchor(): void
    {
        $store = new OrderBookStore;
        $now = 1_000;

        // Construimos un escenario donde un book NO actualizado podría cerrar
        // un ciclo, pero el book actualizado no tiene activos en común con
        // los ciclos del grafo.
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[99, 10]], [[100, 10]]), receivedAtMs: $now);
        $store->apply($this->snapshot('binance', 'ETH/BTC', [[0.049, 100]], [[0.05, 100]]), receivedAtMs: $now);
        $store->apply($this->snapshot('binance', 'ETH/USDT', [[6, 100]], [[6.1, 100]]), receivedAtMs: $now);

        // Book actualizado: un activo aislado que no participa en ningún ciclo.
        $isolated = $store->apply($this->snapshot('binance', 'DOGE/USDT', [[0.1, 100]], [[0.11, 100]]), receivedAtMs: $now);

        $fees = new FeeSchedule([], 0.0);
        $builder = new GraphBuilder($store, $fees, freshnessMs: 5_000, crossExchange: false);
        $scanner = new CycleScanner($builder, startAssets: ['USDT'], maxCycleLength: 3);

        $candidates = $scanner->scan($isolated, nowMs: $now);

        // No habrá ciclos rentables que pasen por DOGE específicamente (faltan
        // pares para cerrarlos), por lo que la lista debe estar vacía.
        $passingThroughDoge = array_filter($candidates, static function ($c): bool {
            foreach ($c->edges as $edge) {
                if ($edge->from->asset === 'DOGE' || $edge->to->asset === 'DOGE') {
                    return true;
                }
            }

            return false;
        });
        $this->assertSame([], $candidates, 'no debería haber ciclos sin ancla en el book actualizado');
        $this->assertSame([], array_values($passingThroughDoge));
    }
}
