<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Arbitrage\Engine\ArbitrageScanner;
use App\Arbitrage\MarketData\OrderBookStore;
use PHPUnit\Framework\TestCase;

class ArbitrageScannerTest extends TestCase
{
    use ArbitrageTestFactory;

    public function test_detects_candidate_when_buy_ask_below_sell_bid(): void
    {
        $store = new OrderBookStore();
        $now = 1_000;

        // Binance: ask barato (100). Kraken: bid alto (105).
        $store->apply($this->snapshot('binance', 'BTC/USDT', [[99, 1]], [[100, 1]]), receivedAtMs: $now);
        $kraken = $store->apply($this->snapshot('kraken', 'BTC/USDT', [[105, 1]], [[106, 1]]), receivedAtMs: $now);

        $scanner = new ArbitrageScanner($store, freshnessMs: 5_000);
        $candidates = $scanner->scan($kraken, nowMs: $now);

        $this->assertCount(1, $candidates);
        $this->assertSame('binance', $candidates[0]->buyExchange());
        $this->assertSame('kraken', $candidates[0]->sellExchange());
        $this->assertSame(100.0, $candidates[0]->buyAsk);
        $this->assertSame(105.0, $candidates[0]->sellBid);
    }

    public function test_no_candidate_when_no_cross(): void
    {
        $store = new OrderBookStore();
        $now = 1_000;

        $store->apply($this->snapshot('binance', 'BTC/USDT', [[99, 1]], [[100, 1]]), receivedAtMs: $now);
        $kraken = $store->apply($this->snapshot('kraken', 'BTC/USDT', [[99.5, 1]], [[100.5, 1]]), receivedAtMs: $now);

        $scanner = new ArbitrageScanner($store, freshnessMs: 5_000);

        $this->assertSame([], $scanner->scan($kraken, nowMs: $now));
    }

    public function test_ignores_stale_books(): void
    {
        $store = new OrderBookStore();
        $now = 100_000;

        $store->apply($this->snapshot('binance', 'BTC/USDT', [[99, 1]], [[100, 1]]), receivedAtMs: $now - 50_000);
        $kraken = $store->apply($this->snapshot('kraken', 'BTC/USDT', [[105, 1]], [[106, 1]]), receivedAtMs: $now);

        $scanner = new ArbitrageScanner($store, freshnessMs: 2_000);

        $this->assertSame([], $scanner->scan($kraken, nowMs: $now));
    }
}
