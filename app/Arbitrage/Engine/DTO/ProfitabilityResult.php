<?php

declare(strict_types=1);

namespace App\Arbitrage\Engine\DTO;

/**
 * Desglose de rentabilidad de una oportunidad para un volumen dado.
 * Todos los montos están en moneda quote (p. ej. USDT).
 */
final class ProfitabilityResult
{
    public function __construct(
        public readonly float $baseVolume,
        public readonly float $grossProfit,
        public readonly float $buyFee,
        public readonly float $sellFee,
        public readonly float $latencyPenalty,
        public readonly float $fixedCost,
        public readonly float $netProfit,
        public readonly float $buyNotional,
    ) {
    }

    public function totalCosts(): float
    {
        return $this->buyFee + $this->sellFee + $this->latencyPenalty + $this->fixedCost;
    }

    /**
     * Margen neto como fracción del notional de compra.
     */
    public function netMargin(): float
    {
        if ($this->buyNotional <= 0.0) {
            return 0.0;
        }

        return $this->netProfit / $this->buyNotional;
    }

    public function isProfitable(): bool
    {
        return $this->netProfit > 0.0;
    }
}
