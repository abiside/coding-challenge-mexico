<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Arbitrage\MarketData\OrderBookStore;
use PHPUnit\Framework\TestCase;

class OrderBookStoreTest extends TestCase
{
    use ArbitrageTestFactory;

    public function test_computes_best_bid_and_ask_regardless_of_input_order(): void
    {
        $store = new OrderBookStore();

        $state = $store->apply($this->snapshot(
            exchange: 'binance',
            symbol: 'BTC/USDT',
            bids: [[98.0, 1.0], [99.5, 2.0], [99.0, 1.0]],
            asks: [[101.0, 1.0], [100.0, 2.0], [100.5, 1.0]],
        ), receivedAtMs: 1_000);

        $this->assertSame(99.5, $state->bestBid()?->price);
        $this->assertSame(100.0, $state->bestAsk()?->price);
    }

    public function test_fresh_except_excludes_origin_and_stale_books(): void
    {
        $store = new OrderBookStore();
        $now = 10_000;

        $store->apply($this->snapshot('binance', 'BTC/USDT', [[100, 1]], [[101, 1]]), receivedAtMs: $now);
        $store->apply($this->snapshot('kraken', 'BTC/USDT', [[100, 1]], [[101, 1]]), receivedAtMs: $now);
        $store->apply($this->snapshot('coinbase', 'BTC/USDT', [[100, 1]], [[101, 1]]), receivedAtMs: $now - 9_000);

        $fresh = $store->freshExcept('BTC/USDT', 'binance', maxAgeMs: 2_000, nowMs: $now);

        $exchanges = array_map(static fn ($s) => $s->exchange, $fresh);
        $this->assertContains('kraken', $exchanges);
        $this->assertNotContains('binance', $exchanges);
        $this->assertNotContains('coinbase', $exchanges);
    }
}
