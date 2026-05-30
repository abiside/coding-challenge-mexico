<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Enums;

enum StreamType: string
{
    case Ticker = 'ticker';
    case OrderBook = 'orderbook';
}
