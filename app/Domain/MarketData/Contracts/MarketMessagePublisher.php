<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Contracts;

use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;

interface MarketMessagePublisher
{
    public function publishTick(MarketTick $tick): void;

    public function publishOrderBook(OrderBookSnapshot $snapshot): void;
}
