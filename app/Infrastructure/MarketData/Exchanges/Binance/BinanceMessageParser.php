<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Binance;

use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Generator;

final class BinanceMessageParser implements ExchangeMessageParser
{
    public function __construct(private readonly string $exchangeName = 'binance')
    {
    }

    public function parse(string $rawMessage): iterable
    {
        $decoded = json_decode($rawMessage, true);
        if (! is_array($decoded)) {
            return;
        }

        if (isset($decoded['result']) || isset($decoded['error']) || isset($decoded['id'])) {
            return;
        }

        $stream = $decoded['stream'] ?? null;
        $data = $decoded['data'] ?? null;

        if (! is_string($stream) || ! is_array($data)) {
            return;
        }

        if (str_ends_with($stream, '@ticker')) {
            yield from $this->parseTicker($data);

            return;
        }

        if (str_contains($stream, '@depth')) {
            yield from $this->parseOrderBook($data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<int, MarketTick>
     */
    private function parseTicker(array $data): Generator
    {
        $rawSymbol = (string) ($data['s'] ?? '');
        if ($rawSymbol === '') {
            return;
        }

        yield new MarketTick(
            exchange: $this->exchangeName,
            symbol: BinanceSymbolMapper::normalize($rawSymbol),
            price: (string) ($data['c'] ?? '0'),
            bid: isset($data['b']) ? (string) $data['b'] : null,
            ask: isset($data['a']) ? (string) $data['a'] : null,
            volume24h: isset($data['v']) ? (string) $data['v'] : null,
            timestampMs: (int) ($data['E'] ?? (int) (microtime(true) * 1000)),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<int, OrderBookSnapshot>
     */
    private function parseOrderBook(array $data): Generator
    {
        $rawSymbol = (string) ($data['s'] ?? '');
        if ($rawSymbol === '' && isset($data['lastUpdateId']) === false) {
            return;
        }

        $bids = $this->extractLevels($data['bids'] ?? []);
        $asks = $this->extractLevels($data['asks'] ?? []);
        if ($bids === [] && $asks === []) {
            return;
        }

        yield new OrderBookSnapshot(
            exchange: $this->exchangeName,
            symbol: $rawSymbol !== '' ? BinanceSymbolMapper::normalize($rawSymbol) : '',
            bids: $bids,
            asks: $asks,
            timestampMs: isset($data['E']) ? (int) $data['E'] : (int) (microtime(true) * 1000),
            isSnapshot: true,
            sequence: isset($data['lastUpdateId']) ? (int) $data['lastUpdateId'] : null,
        );
    }

    /**
     * @param  array<int, mixed>  $rawLevels
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
