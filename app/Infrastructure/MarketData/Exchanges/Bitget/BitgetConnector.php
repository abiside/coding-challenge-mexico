<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Bitget;

use App\Domain\MarketData\Contracts\ExchangeConnector;
use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;

final class BitgetConnector implements ExchangeConnector
{
    public function __construct(
        private readonly string $endpoint = 'wss://ws.bitget.com/v2/ws/public',
        private readonly string $instType = 'SPOT',
        private readonly BitgetMessageParser $parser = new BitgetMessageParser(),
    ) {
    }

    public function name(): string
    {
        return 'bitget';
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function buildSubscribeFrames(array $subscriptions): array
    {
        $args = [];
        foreach ($subscriptions as $subscription) {
            $args[] = [
                'instType' => $this->instType,
                'channel' => $subscription->type === StreamType::Ticker ? 'ticker' : 'books',
                'instId' => BitgetSymbolMapper::toExchange($subscription->symbol),
            ];
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
