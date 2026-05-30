<?php

declare(strict_types=1);

namespace App\Arbitrage\MarketData;

/**
 * Nivel de precio normalizado a float para cálculo en el camino crítico.
 * Se deriva de App\Domain\MarketData\DTO\OrderBookLevel (que usa strings).
 */
final class PriceLevel
{
    public function __construct(
        public readonly float $price,
        public readonly float $size,
    ) {
    }
}
