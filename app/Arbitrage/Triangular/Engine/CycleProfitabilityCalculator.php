<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Engine;

use App\Arbitrage\Triangular\DTO\CycleLiquidityResult;
use App\Arbitrage\Triangular\DTO\CycleProfitabilityResult;

/**
 * Calcula el profit neto de un ciclo en unidades del activo de partida.
 *
 * El `endAmount` que entrega el calculador de liquidez ya descuenta las fees
 * aplicadas dentro de cada pata (las fees se restan del activo recibido).
 * Aquí calculamos:
 *   - grossProfit  = endAmount - startAmount   (en activo inicial)
 *   - totalFees    = suma de fees por pata, traducidas al activo inicial
 *                    mediante una aproximación útil para el desglose.
 *   - netProfit    = grossProfit - latencyPenalty - fixedCost
 *
 * Importante: dado que las fees ya están incorporadas en `endAmount`, NO se
 * restan otra vez para obtener `netProfit`. El campo `totalFeesInStart` es
 * informativo (desglose), no se descuenta dos veces.
 */
final class CycleProfitabilityCalculator
{
    public function __construct(
        private readonly float $latencyPenaltyPerMs = 0.0,
        private readonly float $fixedCost = 0.0,
    ) {
    }

    public function evaluate(
        CycleLiquidityResult $liquidity,
        int $combinedAgeMs,
    ): CycleProfitabilityResult {
        $startAmount = $liquidity->startAmount;
        $endAmount = $liquidity->endAmount;
        $grossProfit = $endAmount - $startAmount;

        $latencyPenalty = max(0, $combinedAgeMs) * $this->latencyPenaltyPerMs;
        $fixedCost = $startAmount > 0.0 ? $this->fixedCost : 0.0;

        $netProfit = $grossProfit - $latencyPenalty - $fixedCost;

        // Desglose informativo de fees: aproximamos cada fee al "activo inicial"
        // usando los ratios de las patas siguientes. Para un desglose útil al
        // usuario no necesitamos exactitud absoluta aquí; el endAmount ya
        // refleja la pérdida real por fees.
        $totalFeesInStart = $this->aggregateFeesInStartUnits($liquidity);

        return new CycleProfitabilityResult(
            startAmount: $startAmount,
            endAmount: $endAmount,
            grossProfit: $grossProfit,
            totalFeesInStart: $totalFeesInStart,
            latencyPenalty: $latencyPenalty,
            fixedCost: $fixedCost,
            netProfit: $netProfit,
        );
    }

    /**
     * Aproxima la suma de fees por pata trasladadas a unidades del activo
     * de partida usando los `weightedPrice` reales de las patas posteriores.
     */
    private function aggregateFeesInStartUnits(CycleLiquidityResult $liquidity): float
    {
        $legs = $liquidity->legs;
        if ($legs === []) {
            return 0.0;
        }

        // Para cada pata `i`, computamos el factor que convierte una unidad
        // de su `toAsset` (en el que se cobra el fee) a unidades del activo
        // de partida tras aplicar las conversiones de las patas restantes
        // (i+1..N-1) en reversa, usando los precios reales del walking.
        $count = count($legs);
        $totalInStart = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $feeInToAsset = $legs[$i]->fee;
            if ($feeInToAsset <= 0.0) {
                continue;
            }

            // Convertir feeInToAsset (en `toAsset` de la pata i) a activo de
            // partida = avance hacia delante hasta cerrar el ciclo.
            $value = $feeInToAsset;
            for ($j = $i + 1; $j < $count; $j++) {
                // Ratio output/input observado en la pata j.
                $in = $legs[$j]->amountIn;
                $out = $legs[$j]->amountOut;
                if ($in <= 0.0) {
                    $value = 0.0;
                    break;
                }
                $value *= $out / $in;
            }
            $totalInStart += $value;
        }

        return $totalInStart;
    }
}
