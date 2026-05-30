<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Kraken;

use App\Domain\MarketData\Contracts\ExchangeConnector;
use App\Domain\MarketData\Contracts\ExchangeMessageParser;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;

final class KrakenConnector implements ExchangeConnector
{
    public function __construct(
        private readonly string $endpoint = 'wss://ws.kraken.com/v2',
        private readonly int $orderBookDepth = 10,
        private readonly KrakenMessageParser $parser = new KrakenMessageParser(),
    ) {
    }

    public function name(): string
    {
        return 'kraken';
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function buildSubscribeFrames(array $subscriptions): array
    {
        $tickerSymbols = [];
        $bookSymbols = [];

        foreach ($subscriptions as $subscription) {
            $symbol = KrakenSymbolMapper::toExchange($subscription->symbol);
            match ($subscription->type) {
                StreamType::Ticker => $tickerSymbols[] = $symbol,
                StreamType::OrderBook => $bookSymbols[] = $symbol,
            };
        }

        $frames = [];

        if ($tickerSymbols !== []) {
            $frames[] = json_encode([
                'method' => 'subscribe',
                'params' => [
                    'channel' => 'ticker',
                    'symbol' => array_values(array_unique($tickerSymbols)),
                ],
            ], JSON_THROW_ON_ERROR);
        }

        if ($bookSymbols !== []) {
            $frames[] = json_encode([
                'method' => 'subscribe',
                'params' => [
                    'channel' => 'book',
                    'depth' => $this->orderBookDepth,
                    'symbol' => array_values(array_unique($bookSymbols)),
                ],
            ], JSON_THROW_ON_ERROR);
        }

        return $frames;
    }

    public function parser(): ExchangeMessageParser
    {
        return $this->parser;
    }
}
