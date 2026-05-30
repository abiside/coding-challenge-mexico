<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Okx;

use App\Domain\MarketData\Contracts\ExchangeConnector;
use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;

final class OkxConnector implements ExchangeConnector
{
    public function __construct(
        private readonly string $endpoint = 'wss://ws.okx.com:8443/ws/v5/public',
        private readonly string $orderBookChannel = 'books5',
        private readonly OkxMessageParser $parser = new OkxMessageParser(),
    ) {
    }

    public function name(): string
    {
        return 'okx';
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function buildSubscribeFrames(array $subscriptions): array
    {
        $args = [];
        foreach ($subscriptions as $subscription) {
            $instId = OkxSymbolMapper::toExchange($subscription->symbol);
            $args[] = match ($subscription->type) {
                StreamType::Ticker => ['channel' => 'tickers', 'instId' => $instId],
                StreamType::OrderBook => ['channel' => $this->orderBookChannel, 'instId' => $instId],
            };
        }

        if ($args === []) {
            return [];
        }

        return [json_encode([
            'op' => 'subscribe',
            'args' => array_values($args),
        ], JSON_THROW_ON_ERROR)];
    }

    public function parser(): ExchangeMessageParser
    {
        return $this->parser;
    }
}
