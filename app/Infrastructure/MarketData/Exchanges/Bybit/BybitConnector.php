<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Bybit;

use App\Domain\MarketData\Contracts\ExchangeConnector;
use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;

final class BybitConnector implements ExchangeConnector
{
    public function __construct(
        private readonly string $endpoint = 'wss://stream.bybit.com/v5/public/spot',
        private readonly int $orderBookDepth = 50,
        private readonly BybitMessageParser $parser = new BybitMessageParser(),
    ) {
    }

    public function name(): string
    {
        return 'bybit';
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function buildSubscribeFrames(array $subscriptions): array
    {
        $args = [];
        foreach ($subscriptions as $subscription) {
            $symbol = BybitSymbolMapper::toExchange($subscription->symbol);
            $args[] = match ($subscription->type) {
                StreamType::Ticker => 'tickers.'.$symbol,
                StreamType::OrderBook => sprintf('orderbook.%d.%s', $this->orderBookDepth, $symbol),
            };
        }

        if ($args === []) {
            return [];
        }

        return [json_encode([
            'op' => 'subscribe',
            'args' => array_values(array_unique($args)),
        ], JSON_THROW_ON_ERROR)];
    }

    public function parser(): ExchangeMessageParser
    {
        return $this->parser;
    }
}
