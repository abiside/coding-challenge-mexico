<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Publishers;

use App\Domain\MarketData\Contracts\MarketMessagePublisher;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;

/**
 * Compone múltiples publishers para enviar el mismo mensaje a varios destinos.
 */
final class CompositeMarketMessagePublisher implements MarketMessagePublisher
{
    /**
     * @param  array<int, MarketMessagePublisher>  $publishers
     */
    public function __construct(private readonly array $publishers)
    {
    }

    public function publishTick(MarketTick $tick): void
    {
        foreach ($this->publishers as $publisher) {
            $publisher->publishTick($tick);
        }
    }

    public function publishOrderBook(OrderBookSnapshot $snapshot): void
    {
        foreach ($this->publishers as $publisher) {
            $publisher->publishOrderBook($snapshot);
        }
    }
}
