<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\DTO;

enum Side: string
{
    case Buy = 'buy';
    case Sell = 'sell';
}
