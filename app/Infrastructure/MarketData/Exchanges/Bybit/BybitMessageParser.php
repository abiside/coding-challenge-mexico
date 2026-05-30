<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Bybit;

use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Generator;

final class BybitMessageParser implements ExchangeMessageParser
{
    public function __construct(private readonly string $exchangeName = 'bybit')
    {
    }

    public function parse(string $rawMessage): iterable
    {
        $decoded = json_decode($rawMessage, true);
        if (! is_array($decoded)) {
            return;
        }

        $topic = (string) ($decoded['topic'] ?? '');
        $data = $decoded['data'] ?? null;
        if ($topic === '' || ! is_array($data)) {
            return;
        }

        if (str_starts_with($topic, 'tickers.')) {
            yield from $this->parseTicker($data, (int) ($decoded['ts'] ?? (int) (microtime(true) * 1000)));

            return;
        }

        if (str_starts_with($topic, 'orderbook.')) {
            yield from $this->parseOrderBook(
                $data,
                (string) ($decoded['type'] ?? 'snapshot'),
                (int) ($decoded['ts'] ?? (int) (microtime(true) * 1000))
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return Generator<int, MarketTick>
     */
    private function parseTicker(array $data, int $timestampMs): Generator
    {
        $symbol = (string) ($data['symbol'] ?? '');
        if ($symbol === '') {
            return;
        }

        yield new MarketTick(
            exchange: $this->exchangeName,
            symbol: BybitSymbolMapper::normalize($symbol),
            price: (string) ($data['lastPrice'] ?? '0'),
            bid: isset($data['bid1Price']) ? (string) $data['bid1Price'] : null,
            ask: isset($data['ask1Price']) ? (string) $data['ask1Price'] : null,
            volume24h: isset($data['volume24h']) ? (string) $data['volume24h'] : null,
            timestampMs: $timestampMs,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return Generator<int, OrderBookSnapshot>
     */
    private function parseOrderBook(array $data, string $type, int $timestampMs): Generator
    {
        $symbol = (string) ($data['s'] ?? '');
        if ($symbol === '') {
            return;
        }

        $bids = $this->extractLevels($data['b'] ?? []);
        $asks = $this->extractLevels($data['a'] ?? []);

        if ($bids === [] && $asks === []) {
            return;
        }

        yield new OrderBookSnapshot(
            exchange: $this->exchangeName,
            symbol: BybitSymbolMapper::normalize($symbol),
            bids: $bids,
            asks: $asks,
            timestampMs: $timestampMs,
            isSnapshot: strtolower($type) === 'snapshot',
            sequence: isset($data['u']) ? (int) $data['u'] : null,
        );
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
