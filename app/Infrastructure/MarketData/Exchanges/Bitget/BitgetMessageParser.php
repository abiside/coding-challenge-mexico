<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Bitget;

use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Generator;

final class BitgetMessageParser implements ExchangeMessageParser
{
    public function __construct(private readonly string $exchangeName = 'bitget')
    {
    }

    public function parse(string $rawMessage): iterable
    {
        $decoded = json_decode($rawMessage, true);
        if (! is_array($decoded)) {
            return;
        }

        $arg = $decoded['arg'] ?? null;
        $data = $decoded['data'] ?? null;
        if (! is_array($arg) || ! is_array($data)) {
            return;
        }

        $channel = (string) ($arg['channel'] ?? '');
        if ($channel === 'ticker') {
            yield from $this->parseTicker($data);

            return;
        }

        if ($channel === 'books') {
            yield from $this->parseOrderBook($arg, $data, (string) ($decoded['action'] ?? 'snapshot'));
        }
    }

    /**
     * @param array<int, mixed> $items
     * @return Generator<int, MarketTick>
     */
    private function parseTicker(array $items): Generator
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $symbol = (string) ($item['instId'] ?? '');
            if ($symbol === '') {
                continue;
            }

            yield new MarketTick(
                exchange: $this->exchangeName,
                symbol: BitgetSymbolMapper::normalize($symbol),
                price: (string) ($item['lastPr'] ?? '0'),
                bid: isset($item['bidPr']) ? (string) $item['bidPr'] : null,
                ask: isset($item['askPr']) ? (string) $item['askPr'] : null,
                volume24h: isset($item['baseVolume']) ? (string) $item['baseVolume'] : null,
                timestampMs: isset($item['ts']) ? (int) $item['ts'] : (int) (microtime(true) * 1000),
            );
        }
    }

    /**
     * @param array<string, mixed> $arg
     * @param array<int, mixed> $items
     * @return Generator<int, OrderBookSnapshot>
     */
    private function parseOrderBook(array $arg, array $items, string $action): Generator
    {
        $symbol = (string) ($arg['instId'] ?? '');
        if ($symbol === '') {
            return;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $bids = $this->extractLevels($item['bids'] ?? []);
            $asks = $this->extractLevels($item['asks'] ?? []);
            if ($bids === [] && $asks === []) {
                continue;
            }

            yield new OrderBookSnapshot(
                exchange: $this->exchangeName,
                symbol: BitgetSymbolMapper::normalize($symbol),
                bids: $bids,
                asks: $asks,
                timestampMs: isset($item['ts']) ? (int) $item['ts'] : (int) (microtime(true) * 1000),
                isSnapshot: strtolower($action) === 'snapshot',
                sequence: isset($item['seq']) ? (int) $item['seq'] : null,
            );
        }
    }

    /**
     * @param array<int, mixed> $rawLevels
     * @return array<int, OrderBookLevel>
     */
    private function extractLevels(array $rawLevels): array
    {
        $levels = [];
        foreach ($rawLevels as $level) {
            if (! is_array($level) || count($level) < 2) {
                continue;
            }
            $levels[] = new OrderBookLevel((string) $level[0], (string) $level[1]);
        }

        return $levels;
    }
}
