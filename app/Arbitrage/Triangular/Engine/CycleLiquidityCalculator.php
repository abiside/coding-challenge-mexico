<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Engine;

use App\Arbitrage\MarketData\PriceLevel;
use App\Arbitrage\Triangular\DTO\ConversionEdge;
use App\Arbitrage\Triangular\DTO\CycleCandidate;
use App\Arbitrage\Triangular\DTO\CycleLeg;
use App\Arbitrage\Triangular\DTO\CycleLiquidityResult;
use App\Arbitrage\Triangular\DTO\EdgeKind;

/**
 * Calcula el volumen ejecutable real de un ciclo multi-pata.
 *
 * Estrategia (dos fases):
 *   1) Estimación lineal con best-price: por cada pata, capacidad máxima de
 *      entrada según la profundidad. Combinando con los factores lineales
 *      (best-price * (1-fee)) se calcula el `startAmount` máximo que NO satura
 *      ninguna pata.
 *   2) Walking real del book para cada pata con ese `startAmount`, obteniendo
 *      precios VWAP y cantidades exactas tras slippage por profundidad. Si el
 *      walking real sobrepasa la capacidad de una pata (improbable, pero
 *      posible por slippage acumulado), recortamos proporcionalmente.
 *
 * Para aristas de transferencia (sin book), la conversión es lineal y no
 * impone restricciones de profundidad.
 */
final class CycleLiquidityCalculator
{
    /**
     * @param  float  $targetStartAmount  cap superior del flujo en activo inicial
     */
    public function evaluate(CycleCandidate $candidate, float $targetStartAmount): CycleLiquidityResult
    {
        if ($targetStartAmount <= 0.0 || $candidate->edges === []) {
            return CycleLiquidityResult::empty();
        }

        $edges = $candidate->edges;

        // Fase 1: factor lineal acumulado por pata (basado en best-price), y
        // capacidad máxima de entrada en cada pata según depth.
        $cumulativeFactor = 1.0;
        $maxStart = $targetStartAmount;
        $factors = [];
        $caps = [];
        foreach ($edges as $i => $edge) {
            $inputFactor = $cumulativeFactor; // multiplica al `startAmount` para obtener input en esta pata
            $factors[$i] = $inputFactor;

            $cap = $this->edgeInputCapacity($edge);
            $caps[$i] = $cap;

            if ($cap !== null && $inputFactor > 0.0) {
                $maxFromCap = $cap / $inputFactor;
                if ($maxFromCap < $maxStart) {
                    $maxStart = $maxFromCap;
                }
            }

            $cumulativeFactor *= $edge->netRate();
        }

        if ($maxStart <= 0.0) {
            return CycleLiquidityResult::empty();
        }

        // Fase 2: walking real con ese `startAmount`.
        $startAmount = $maxStart;
        $partial = $startAmount < $targetStartAmount - 1e-12;
        $currentAmount = $startAmount;
        $legs = [];

        foreach ($edges as $i => $edge) {
            $leg = $this->walkLeg($edge, $currentAmount);
            // Si el walking real consumió más de lo disponible (no debería
            // pasar dado el cap, pero por seguridad), recortar proporcionalmente
            // y reiniciar desde el principio.
            if ($leg === null) {
                // No se puede ejecutar esta pata con el amount actual: reducir
                // start_amount con base en la capacidad y reiterar.
                $cap = $caps[$i];
                $factor = $factors[$i];
                if ($cap === null || $factor <= 0.0) {
                    return CycleLiquidityResult::empty();
                }
                $startAmount = $cap / $factor * 0.9999; // pequeño margen
                if ($startAmount <= 0.0) {
                    return CycleLiquidityResult::empty();
                }
                $partial = true;
                $currentAmount = $startAmount;
                $legs = [];

                // Reiniciar desde la primera pata.
                $restart = true;
                while ($restart) {
                    $restart = false;
                    $currentAmount = $startAmount;
                    $legs = [];
                    foreach ($edges as $j => $e2) {
                        $l = $this->walkLeg($e2, $currentAmount);
                        if ($l === null) {
                            $startAmount *= 0.5;
                            if ($startAmount <= 0.0) {
                                return CycleLiquidityResult::empty();
                            }
                            $restart = true;
                            $partial = true;
                            break;
                        }
                        $legs[] = $l;
                        $currentAmount = $l->amountOut;
                    }
                }
                break;
            }

            $legs[] = $leg;
            $currentAmount = $leg->amountOut;
        }

        return new CycleLiquidityResult(
            legs: $legs,
            startAmount: $startAmount,
            endAmount: $currentAmount,
            partial: $partial,
        );
    }

    /**
     * Devuelve la capacidad máxima de "input" admitida por una pata según la
     * profundidad del book. Para BUY, es la suma de `level.price * level.size`
     * de los asks (cuánto QUOTE se puede gastar). Para SELL, es la suma de
     * `level.size` de los bids (cuánto BASE se puede vender). Para transfers,
     * `null` (sin tope práctico).
     */
    private function edgeInputCapacity(ConversionEdge $edge): ?float
    {
        if ($edge->book === null) {
            return null;
        }

        return match ($edge->kind) {
            EdgeKind::TradeBuy => $this->quoteCapacityOf($edge->book->asks),
            EdgeKind::TradeSell => $this->baseCapacityOf($edge->book->bids),
            EdgeKind::Transfer => null,
        };
    }

    /**
     * @param  array<int, PriceLevel>  $asks
     */
    private function quoteCapacityOf(array $asks): float
    {
        $total = 0.0;
        foreach ($asks as $lvl) {
            $total += $lvl->price * $lvl->size;
        }

        return $total;
    }

    /**
     * @param  array<int, PriceLevel>  $bids
     */
    private function baseCapacityOf(array $bids): float
    {
        $total = 0.0;
        foreach ($bids as $lvl) {
            $total += $lvl->size;
        }

        return $total;
    }

    /**
     * Walking real de una pata: dado `amountIn` del activo de entrada,
     * recorre los niveles del book hasta consumirlo, calculando el `amountOut`
     * efectivamente recibido, el precio promedio ponderado y el fee aplicado.
     */
    private function walkLeg(ConversionEdge $edge, float $amountIn): ?CycleLeg
    {
        if ($amountIn <= 0.0) {
            return null;
        }

        switch ($edge->kind) {
            case EdgeKind::Transfer:
                $rate = $edge->grossRate; // 1 - transfer_cost
                $amountOut = $amountIn * $rate;

                return new CycleLeg(
                    kind: $edge->kind,
                    fromExchange: $edge->from->exchange,
                    fromAsset: $edge->from->asset,
                    toExchange: $edge->to->exchange,
                    toAsset: $edge->to->asset,
                    symbol: null,
                    amountIn: $amountIn,
                    amountOut: $amountOut,
                    weightedPrice: $rate,
                    fee: 0.0,
                    feeRate: 0.0,
                );

            case EdgeKind::TradeBuy:
                // amountIn está en QUOTE; recibimos BASE.
                if ($edge->book === null) {
                    return null;
                }
                $remainingQuote = $amountIn;
                $baseAcquired = 0.0;
                foreach ($edge->book->asks as $lvl) {
                    if ($remainingQuote <= 0.0) {
                        break;
                    }
                    $costAtLevel = $lvl->price * $lvl->size;
                    if ($costAtLevel >= $remainingQuote) {
                        $baseAcquired += $remainingQuote / $lvl->price;
                        $remainingQuote = 0.0;
                        break;
                    }
                    $baseAcquired += $lvl->size;
                    $remainingQuote -= $costAtLevel;
                }
                if ($baseAcquired <= 0.0 || $remainingQuote > 1e-12) {
                    return null;
                }
                $weightedPrice = $amountIn / $baseAcquired;
                $fee = $baseAcquired * $edge->feeRate;
                $amountOut = $baseAcquired - $fee;

                return new CycleLeg(
                    kind: $edge->kind,
                    fromExchange: $edge->from->exchange,
                    fromAsset: $edge->from->asset,
                    toExchange: $edge->to->exchange,
                    toAsset: $edge->to->asset,
                    symbol: $edge->symbol,
                    amountIn: $amountIn,
                    amountOut: $amountOut,
                    weightedPrice: $weightedPrice,
                    fee: $fee,
                    feeRate: $edge->feeRate,
                );

            case EdgeKind::TradeSell:
                // amountIn está en BASE; recibimos QUOTE.
                if ($edge->book === null) {
                    return null;
                }
                $remainingBase = $amountIn;
                $quoteAcquired = 0.0;
                foreach ($edge->book->bids as $lvl) {
                    if ($remainingBase <= 0.0) {
                        break;
                    }
                    if ($lvl->size >= $remainingBase) {
                        $quoteAcquired += $remainingBase * $lvl->price;
                        $remainingBase = 0.0;
                        break;
                    }
                    $quoteAcquired += $lvl->size * $lvl->price;
                    $remainingBase -= $lvl->size;
                }
                if ($quoteAcquired <= 0.0 || $remainingBase > 1e-12) {
                    return null;
                }
                $weightedPrice = $quoteAcquired / $amountIn;
                $fee = $quoteAcquired * $edge->feeRate;
                $amountOut = $quoteAcquired - $fee;

                return new CycleLeg(
                    kind: $edge->kind,
                    fromExchange: $edge->from->exchange,
                    fromAsset: $edge->from->asset,
                    toExchange: $edge->to->exchange,
                    toAsset: $edge->to->asset,
                    symbol: $edge->symbol,
                    amountIn: $amountIn,
                    amountOut: $amountOut,
                    weightedPrice: $weightedPrice,
                    fee: $fee,
                    feeRate: $edge->feeRate,
                );
        }

        return null;
    }
}
