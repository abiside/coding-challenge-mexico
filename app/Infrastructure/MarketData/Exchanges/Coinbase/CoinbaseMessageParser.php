<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Coinbase;

use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Generator;

final class CoinbaseMessageParser implements ExchangeMessageParser
{
    public function __construct(private readonly string $exchangeName = 'coinbase')
    {
    }

    public function parse(string $rawMessage): iterable
    {
        $decoded = json_decode($rawMessage, true);
        if (! is_array($decoded)) {
            return;
        }

        $channel = $decoded['channel'] ?? null;
        $events = $decoded['events'] ?? null;

        if (! is_string($channel) || ! is_array($events)) {
            return;
        }

        $timestampMs = $this->extractTimestampMs($decoded);
        $sequence = isset($decoded['sequence_num']) ? (int) $decoded['sequence_num'] : null;

        match ($channel) {
            'ticker', 'ticker_batch' => yield from $this->parseTickerEvents($events, $timestampMs),
            'l2_data' => yield from $this->parseLevel2Events($events, $timestampMs, $sequence),
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $events
     * @return Generator<int, MarketTick>
     */
    private function parseTickerEvents(array $events, int $timestampMs): Generator
    {
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $tickers = $event['tickers'] ?? [];
            if (! is_array($tickers)) {
                continue;
            }
            foreach ($tickers as $ticker) {
                if (! is_array($ticker)) {
                    continue;
                }
                $productId = (string) ($ticker['product_id'] ?? '');
                if ($productId === '') {
                    continue;
                }

                yield new MarketTick(
                    exchange: $this->exchangeName,
                    symbol: CoinbaseSymbolMapper::normalize($productId),
                    price: (string) ($ticker['price'] ?? '0'),
                    bid: isset($ticker['best_bid']) ? (string) $ticker['best_bid'] : null,
                    ask: isset($ticker['best_ask']) ? (string) $ticker['best_ask'] : null,
                    volume24h: isset($ticker['volume_24_h']) ? (string) $ticker['volume_24_h'] : null,
                    timestampMs: $timestampMs,
                );
            }
        }
    }

    /**
     * @param  array<int, mixed>  $events
     * @return Generator<int, OrderBookSnapshot>
     */
    private function parseLevel2Events(array $events, int $timestampMs, ?int $sequence): Generator
    {
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $productId = (string) ($event['product_id'] ?? '');
            $type = (string) ($event['type'] ?? 'update');
            $updates = $event['updates'] ?? [];

            if ($productId === '' || ! is_array($updates)) {
                continue;
            }

            $bids = [];
            $asks = [];
            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $side = (string) ($update['side'] ?? '');
                $level = new OrderBookLevel(
                    price: (string) ($update['price_level'] ?? '0'),
                    size: (string) ($update['new_quantity'] ?? '0'),
                );

                if ($side === 'bid') {
                    $bids[] = $level;
                } elseif ($side === 'offer' || $side === 'ask') {
                    $asks[] = $level;
                }
            }

            if ($bids === [] && $asks === []) {
                continue;
            }

            yield new OrderBookSnapshot(
                exchange: $this->exchangeName,
                symbol: CoinbaseSymbolMapper::normalize($productId),
                bids: $bids,
                asks: $asks,
                timestampMs: $timestampMs,
                isSnapshot: $type === 'snapshot',
                sequence: $sequence,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function extractTimestampMs(array $decoded): int
    {
        $timestamp = $decoded['timestamp'] ?? null;
        if (is_string($timestamp)) {
            $parsed = strtotime($timestamp);
            if ($parsed !== false) {
                return $parsed * 1000;
            }
        }

        return (int) (microtime(true) * 1000);
    }
}
