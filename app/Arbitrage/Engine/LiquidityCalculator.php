<?php

declare(strict_types=1);

namespace App\Arbitrage\Engine;

use App\Arbitrage\Engine\DTO\LiquidityResult;
use App\Arbitrage\Engine\DTO\OpportunityCandidate;
use App\Arbitrage\MarketData\PriceLevel;

/**
 * Recorre la profundidad de los books para calcular el volumen realmente
 * ejecutable y los precios promedio ponderados de compra/venta, soportando
 * fills parciales y reflejando el slippage real por profundidad.
 */
final class LiquidityCalculator
{
    /**
     * @param  float  $targetBaseVolume  tope de volumen a evaluar (base asset)
     */
    public function evaluate(OpportunityCandidate $candidate, float $targetBaseVolume): LiquidityResult
    {
        $asks = $candidate->buyBook->asks;
        $bids = $candidate->sellBook->bids;

        // Cuánto podemos comprar/vender por liquidez disponible en cada lado.
        $buyDepth = $this->depthVolume($asks);
        $sellDepth = $this->depthVolume($bids);

        $executable = min($targetBaseVolume, $buyDepth, $sellDepth);
        if ($executable <= 0.0) {
            return new LiquidityResult(0.0, 0.0, 0.0, 0.0, 0.0, false);
        }

        [$buyNotional] = $this->walk($asks, $executable);
        [$sellNotional] = $this->walk($bids, $executable);

        $weightedBuy = $buyNotional / $executable;
        $weightedSell = $sellNotional / $executable;

        $partial = $executable < $targetBaseVolume - 1e-12;

        return new LiquidityResult(
            executableBaseVolume: $executable,
            weightedBuyPrice: $weightedBuy,
            weightedSellPrice: $weightedSell,
            buyNotional: $buyNotional,
            sellNotional: $sellNotional,
            partial: $partial,
        );
    }

    /**
     * @param  array<int, PriceLevel>  $levels
     */
    private function depthVolume(array $levels): float
    {
        $total = 0.0;
        foreach ($levels as $level) {
            $total += $level->size;
        }

        return $total;
    }

    /**
     * Recorre niveles acumulando hasta `targetVolume`, soportando fill parcial
     * del último nivel.
     *
     * @param  array<int, PriceLevel>  $levels
     * @return array{0: float, 1: float}  [notional, filledVolume]
     */
    private function walk(array $levels, float $targetVolume): array
    {
        $remaining = $targetVolume;
        $notional = 0.0;
        $filled = 0.0;

        foreach ($levels as $level) {
            if ($remaining <= 0.0) {
                break;
            }

            $take = min($remaining, $level->size);
            $notional += $take * $level->price;
            $filled += $take;
            $remaining -= $take;
        }

        return [$notional, $filled];
    }
}
