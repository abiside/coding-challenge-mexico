<?php

declare(strict_types=1);

namespace App\Arbitrage\Engine\DTO;

/**
 * Resultado de recorrer la profundidad de ambos books para un volumen
 * objetivo: volumen realmente ejecutable y precios promedio ponderados
 * (que ya incorporan el slippage por profundidad).
 */
final class LiquidityResult
{
    public function __construct(
        public readonly float $executableBaseVolume,
        public readonly float $weightedBuyPrice,
        public readonly float $weightedSellPrice,
        public readonly float $buyNotional,
        public readonly float $sellNotional,
        public readonly bool $partial,
    ) {
    }

    public function isExecutable(): bool
    {
        return $this->executableBaseVolume > 0.0
            && $this->weightedBuyPrice > 0.0
            && $this->weightedSellPrice > 0.0;
    }

    /**
     * Slippage de compra en bps respecto al mejor ask.
     */
    public function buySlippageBps(float $bestAsk): float
    {
        if ($bestAsk <= 0.0) {
            return 0.0;
        }

        return (($this->weightedBuyPrice - $bestAsk) / $bestAsk) * 10000.0;
    }

    /**
     * Slippage de venta en bps respecto al mejor bid.
     */
    public function sellSlippageBps(float $bestBid): float
    {
        if ($bestBid <= 0.0) {
            return 0.0;
        }

        return (($bestBid - $this->weightedSellPrice) / $bestBid) * 10000.0;
    }
}
