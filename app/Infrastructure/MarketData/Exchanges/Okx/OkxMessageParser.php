<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Okx;

use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Generator;

final class OkxMessageParser implements ExchangeMessageParser
{
    public function __construct(private readonly string $exchangeName = 'okx')
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
        if ($channel === '') {
            return;
        }

        if ($channel === 'tickers') {
            yield from $this->parseTickers($data);

            return;
        }

        if (str_starts_with($channel, 'books')) {
            yield from $this->parseOrderBooks($data, (string) ($decoded['action'] ?? 'update'));
        }
    }

    /**
     * @param array<int, mixed> $data
     * @return Generator<int, MarketTick>
     */
    private function parseTickers(array $data): Generator
    {
        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }
            $instId = (string) ($item['instId'] ?? '');
            if ($instId === '') {
                continue;
            }

            yield new MarketTick(
                exchange: $this->exchangeName,
                symbol: OkxSymbolMapper::normalize($instId),
                price: (string) ($item['last'] ?? '0'),
                bid: isset($item['bidPx']) ? (string) $item['bidPx'] : null,
                ask: isset($item['askPx']) ? (string) $item['askPx'] : null,
                volume24h: isset($item['vol24h']) ? (string) $item['vol24h'] : null,
                timestampMs: isset($item['ts']) ? (int) $item['ts'] : (int) (microtime(true) * 1000),
            );
        }
    }

    /**
     * @param array<int, mixed> $data
     * @return Generator<int, OrderBookSnapshot>
     */
    private function parseOrderBooks(array $data, string $action): Generator
    {
        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }
            $instId = (string) ($item['instId'] ?? '');
            if ($instId === '') {
                continue;
            }

            $bids = $this->extractLevels($item['bids'] ?? []);
            $asks = $this->extractLevels($item['asks'] ?? []);
            if ($bids === [] && $asks === []) {
                continue;
            }

            yield new OrderBookSnapshot(
                exchange: $this->exchangeName,
                symbol: OkxSymbolMapper::normalize($instId),
                bids: $bids,
                asks: $asks,
                timestampMs: isset($item['ts']) ? (int) $item['ts'] : (int) (microtime(true) * 1000),
                isSnapshot: strtolower($action) === 'snapshot',
                sequence: isset($item['seqId']) ? (int) $item['seqId'] : null,
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
