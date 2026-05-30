<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Coinbase;

use App\Domain\MarketData\Contracts\ExchangeConnector;
use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;

final class CoinbaseConnector implements ExchangeConnector
{
    public function __construct(
        private readonly string $endpoint = 'wss://advanced-trade-ws.coinbase.com',
        private readonly CoinbaseMessageParser $parser = new CoinbaseMessageParser(),
    ) {
    }

    public function name(): string
    {
        return 'coinbase';
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function buildSubscribeFrames(array $subscriptions): array
    {
        $tickerProducts = [];
        $bookProducts = [];

        foreach ($subscriptions as $subscription) {
            $productId = CoinbaseSymbolMapper::toExchange($subscription->symbol);
            match ($subscription->type) {
                StreamType::Ticker => $tickerProducts[] = $productId,
                StreamType::OrderBook => $bookProducts[] = $productId,
            };
        }

        $frames = [];

        if ($tickerProducts !== []) {
            $frames[] = json_encode([
                'type' => 'subscribe',
                'product_ids' => array_values(array_unique($tickerProducts)),
                'channel' => 'ticker',
            ], JSON_THROW_ON_ERROR);
        }

        if ($bookProducts !== []) {
            $frames[] = json_encode([
                'type' => 'subscribe',
                'product_ids' => array_values(array_unique($bookProducts)),
                'channel' => 'level2',
            ], JSON_THROW_ON_ERROR);
        }

        return $frames;
    }

    public function parser(): ExchangeMessageParser
    {
        return $this->parser;
    }
}
