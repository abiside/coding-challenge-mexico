<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Kraken;

use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Generator;

final class KrakenMessageParser implements ExchangeMessageParser
{
    public function __construct(private readonly string $exchangeName = 'kraken')
    {
    }

    public function parse(string $rawMessage): iterable
    {
        $decoded = json_decode($rawMessage, true);
        if (! is_array($decoded)) {
            return;
        }

        $channel = $decoded['channel'] ?? null;
        $data = $decoded['data'] ?? null;

        if (! is_string($channel) || ! is_array($data)) {
            return;
        }

        $type = (string) ($decoded['type'] ?? 'update');

        match ($channel) {
            'ticker' => yield from $this->parseTicker($data),
            'book' => yield from $this->parseOrderBook($data, $type),
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $data
     * @return Generator<int, MarketTick>
     */
    private function parseTicker(array $data): Generator
    {
        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $symbol = (string) ($entry['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            yield new MarketTick(
                exchange: $this->exchangeName,
                symbol: KrakenSymbolMapper::normalize($symbol),
                price: (string) ($entry['last'] ?? '0'),
                bid: isset($entry['bid']) ? (string) $entry['bid'] : null,
                ask: isset($entry['ask']) ? (string) $entry['ask'] : null,
                volume24h: isset($entry['volume']) ? (string) $entry['volume'] : null,
                timestampMs: (int) (microtime(true) * 1000),
            );
        }
    }

    /**
     * @param  array<int, mixed>  $data
     * @return Generator<int, OrderBookSnapshot>
     */
    private function parseOrderBook(array $data, string $type): Generator
    {
        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $symbol = (string) ($entry['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $bids = $this->extractLevels($entry['bids'] ?? []);
            $asks = $this->extractLevels($entry['asks'] ?? []);

            $timestampMs = (int) (microtime(true) * 1000);
            if (isset($entry['timestamp']) && is_string($entry['timestamp'])) {
                $parsed = strtotime($entry['timestamp']);
                if ($parsed !== false) {
                    $timestampMs = $parsed * 1000;
                }
            }

            yield new OrderBookSnapshot(
                exchange: $this->exchangeName,
                symbol: KrakenSymbolMapper::normalize($symbol),
                bids: $bids,
                asks: $asks,
                timestampMs: $timestampMs,
                isSnapshot: $type === 'snapshot',
                sequence: isset($entry['checksum']) ? (int) $entry['checksum'] : null,
            );
        }
    }

    /**
     * @param  array<int, mixed>  $rawLevels
     * @return array<int, OrderBookLevel>
     */
    private function extractLevels(array $rawLevels): array
    {
        $levels = [];
        foreach ($rawLevels as $level) {
            if (! is_array($level)) {
                continue;
            }

            $price = $level['price'] ?? null;
            $qty = $level['qty'] ?? null;
            if ($price === null || $qty === null) {
                continue;
            }

            $levels[] = new OrderBookLevel((string) $price, (string) $qty);
        }

        return $levels;
    }
}
