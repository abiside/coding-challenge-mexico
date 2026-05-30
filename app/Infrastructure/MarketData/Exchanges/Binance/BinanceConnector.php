<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Binance;

use App\Domain\MarketData\Contracts\ExchangeConnector;
use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;

final class BinanceConnector implements ExchangeConnector
{
    public function __construct(
        private readonly string $endpoint = 'wss://stream.binance.com:9443/stream',
        private readonly int $orderBookDepth = 20,
        private readonly string $orderBookUpdateSpeed = '100ms',
        private readonly BinanceMessageParser $parser = new BinanceMessageParser(),
    ) {
    }

    public function name(): string
    {
        return 'binance';
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function buildSubscribeFrames(array $subscriptions): array
    {
        $params = [];
        foreach ($subscriptions as $subscription) {
            $rawSymbol = BinanceSymbolMapper::toExchange($subscription->symbol);
            $params[] = match ($subscription->type) {
                StreamType::Ticker => $rawSymbol.'@ticker',
                StreamType::OrderBook => sprintf(
                    '%s@depth%d@%s',
                    $rawSymbol,
                    $this->orderBookDepth,
                    $this->orderBookUpdateSpeed
                ),
            };
        }

        if ($params === []) {
            return [];
        }

        return [json_encode([
            'method' => 'SUBSCRIBE',
            'params' => $params,
            'id' => 1,
        ], JSON_THROW_ON_ERROR)];
    }

    public function parser(): ExchangeMessageParser
    {
        return $this->parser;
    }
}
