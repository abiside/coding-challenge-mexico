<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage\Triangular;

use App\Arbitrage\Engine\FeeSchedule;
use App\Arbitrage\MarketData\OrderBookStore;
use App\Arbitrage\Triangular\DTO\AssetNode;
use App\Arbitrage\Triangular\DTO\EdgeKind;
use App\Arbitrage\Triangular\Graph\GraphBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Arbitrage\ArbitrageTestFactory;

class GraphBuilderTest extends TestCase
{
    use ArbitrageTestFactory;

    public function test_trade_edges_and_rates_for_single_exchange(): void
    {
        $store = new OrderBookStore;
        $now = 1_000;
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[100, 1]], [[101, 1]]), receivedAtMs: $now);

        $fees = new FeeSchedule(['binance' => 0.001], 0.001);
        $builder = new GraphBuilder($store, $fees, freshnessMs: 5_000, crossExchange: false);

        $graph = $builder->build(nowMs: $now);

        $quoteNode = new AssetNode('binance', 'USDT');
        $baseNode = new AssetNode('binance', 'BTC');

        $fromQuote = $graph->edgesFrom($quoteNode);
        $fromBase = $graph->edgesFrom($baseNode);

        $this->assertCount(1, $fromQuote);
        $this->assertCount(1, $fromBase);

        // BUY: 1/101 USDT->BTC
        $buy = $fromQuote[0];
        $this->assertSame(EdgeKind::TradeBuy, $buy->kind);
        $this->assertEqualsWithDelta(1.0 / 101.0, $buy->grossRate, 1e-9);
        $this->assertSame(0.001, $buy->feeRate);

        // SELL: 100 BTC->USDT
        $sell = $fromBase[0];
        $this->assertSame(EdgeKind::TradeSell, $sell->kind);
        $this->assertEqualsWithDelta(100.0, $sell->grossRate, 1e-9);
    }

    public function test_transfer_edges_appear_only_when_cross_exchange_enabled(): void
    {
        $store = new OrderBookStore;
        $now = 1_000;
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[100, 1]], [[101, 1]]), receivedAtMs: $now);
        $store->apply($this->snapshot('kraken', 'BTC/USDT', [[100, 1]], [[101, 1]]), receivedAtMs: $now);

        $fees = new FeeSchedule([], 0.001);

        $withCross = (new GraphBuilder($store, $fees, 5_000, crossExchange: true))->build($now);
        $withoutCross = (new GraphBuilder($store, $fees, 5_000, crossExchange: false))->build($now);

        $btcBinance = new AssetNode('binance', 'BTC');
        $btcKraken = new AssetNode('kraken', 'BTC');

        $crossEdges = array_values(array_filter(
            $withCross->edgesFrom($btcBinance),
            static fn ($e) => $e->kind === EdgeKind::Transfer && $e->to->equals($btcKraken),
        ));
        $this->assertCount(1, $crossEdges);
        $this->assertSame(1.0, $crossEdges[0]->grossRate);

        $noCrossEdges = array_values(array_filter(
            $withoutCross->edgesFrom($btcBinance),
            static fn ($e) => $e->kind === EdgeKind::Transfer,
        ));
        $this->assertCount(0, $noCrossEdges);
    }

    public function test_stale_books_dont_produce_edges(): void
    {
        $store = new OrderBookStore;
        $now = 100_000;
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[100, 1]], [[101, 1]]), receivedAtMs: $now - 60_000);

        $fees = new FeeSchedule([], 0.001);
        $builder = new GraphBuilder($store, $fees, freshnessMs: 2_000, crossExchange: false);

        $graph = $builder->build(nowMs: $now);
        $this->assertSame([], $graph->edgesFrom(new AssetNode('binance', 'USDT')));
        $this->assertSame([], $graph->edgesFrom(new AssetNode('binance', 'BTC')));
    }
}
