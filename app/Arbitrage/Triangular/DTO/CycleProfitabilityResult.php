<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\DTO;

/**
 * Desglose de rentabilidad de un ciclo: profit neto en unidades del activo
 * de partida y suma de fees/penalizaciones por pata. Las fees ya están
 * reflejadas en `endAmount` del CycleLiquidityResult; aquí se hacen explícitas
 * en términos de la unidad del activo de partida (start_asset).
 */
final class CycleProfitabilityResult
{
    public function __construct(
        public readonly float $startAmount,
        public readonly float $endAmount,
        public readonly float $grossProfit,
        public readonly float $totalFeesInStart,
        public readonly float $latencyPenalty,
        public readonly float $fixedCost,
        public readonly float $netProfit,
    ) {
    }

    public function totalCosts(): float
    {
        return $this->totalFeesInStart + $this->latencyPenalty + $this->fixedCost;
    }

    /**
     * Margen neto como fracción del capital de partida.
     */
    public function netMargin(): float
    {
        if ($this->startAmount <= 0.0) {
            return 0.0;
        }

        return $this->netProfit / $this->startAmount;
    }

    public function isProfitable(): bool
    {
        return $this->netProfit > 0.0;
    }
}
