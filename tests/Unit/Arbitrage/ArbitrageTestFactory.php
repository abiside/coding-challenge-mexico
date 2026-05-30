<?php

declare(strict_types=1);

namespace Tests\Unit\Arbitrage;

use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;

/**
 * Helpers para construir order books sintéticos en los tests del engine.
 */
trait ArbitrageTestFactory
{
    /**
     * @param  array<int, array{0: float|string, 1: float|string}>  $bids
     * @param  array<int, array{0: float|string, 1: float|string}>  $asks
     */
    protected function snapshot(
        string $exchange,
        string $symbol,
        array $bids,
        array $asks,
        int $timestampMs = 1_700_000_000_000,
    ): OrderBookSnapshot {
        return new OrderBookSnapshot(
            exchange: $exchange,
            symbol: $symbol,
            bids: array_map(static fn (array $l): OrderBookLevel => new OrderBookLevel((string) $l[0], (string) $l[1]), $bids),
            asks: array_map(static fn (array $l): OrderBookLevel => new OrderBookLevel((string) $l[0], (string) $l[1]), $asks),
            timestampMs: $timestampMs,
        );
    }
}
