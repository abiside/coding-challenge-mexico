<?php

declare(strict_types=1);

namespace App\Arbitrage\MarketData;

use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;

/**
 * Reconstruye un OrderBookSnapshot a partir del payload JSON publicado por
 * RedisMarketMessagePublisher (formato OrderBookSnapshot::toArray()).
 */
final class SnapshotHydrator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function tryFromPayload(array $payload): ?OrderBookSnapshot
    {
        if (($payload['type'] ?? null) !== 'orderbook') {
            return null;
        }

        $exchange = $payload['exchange'] ?? null;
        $symbol = $payload['symbol'] ?? null;
        if (! is_string($exchange) || ! is_string($symbol)) {
            return null;
        }

        return new OrderBookSnapshot(
            exchange: $exchange,
            symbol: $symbol,
            bids: self::levels($payload['bids'] ?? []),
            asks: self::levels($payload['asks'] ?? []),
            timestampMs: (int) ($payload['timestamp_ms'] ?? 0),
            isSnapshot: (bool) ($payload['is_snapshot'] ?? true),
            sequence: isset($payload['sequence']) ? (int) $payload['sequence'] : null,
        );
    }

    /**
     * @param  mixed  $raw
     * @return array<int, OrderBookLevel>
     */
    private static function levels($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $levels = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || ! isset($entry[0], $entry[1])) {
                continue;
            }
            $levels[] = new OrderBookLevel((string) $entry[0], (string) $entry[1]);
        }

        return $levels;
    }
}
