<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Publishers;

use App\Domain\MarketData\Contracts\MarketMessagePublisher;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Psr\Log\LoggerInterface;

final class LoggerMarketMessagePublisher implements MarketMessagePublisher
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function publishTick(MarketTick $tick): void
    {
        $this->logger->info('[market-feed][tick]', $tick->toArray());
    }

    public function publishOrderBook(OrderBookSnapshot $snapshot): void
    {
        $this->logger->info('[market-feed][orderbook]', [
            'exchange' => $snapshot->exchange,
            'symbol' => $snapshot->symbol,
            'is_snapshot' => $snapshot->isSnapshot,
            'bids_count' => count($snapshot->bids),
            'asks_count' => count($snapshot->asks),
            'top_bid' => $snapshot->bids[0] ?? null,
            'top_ask' => $snapshot->asks[0] ?? null,
            'timestamp_ms' => $snapshot->timestampMs,
        ]);
    }
}
