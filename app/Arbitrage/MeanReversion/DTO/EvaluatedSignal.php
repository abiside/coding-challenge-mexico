<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\DTO;

/**
 * Señal con sizing resuelto, lista para pasar por riesgo y ejecución.
 *
 * - BUY: `quoteAmount` es el USDT a desplegar; `baseQuantity` es la cantidad
 *   estimada de moneda a recibir al `price`.
 * - SELL: `baseQuantity` es la moneda a vender; `quoteAmount` es el USDT bruto
 *   estimado a recibir.
 */
final class EvaluatedSignal
{
    public function __construct(
        public readonly MeanReversionCandidate $candidate,
        public readonly float $quoteAmount,
        public readonly float $baseQuantity,
    ) {
    }

    public function side(): Side
    {
        return $this->candidate->side;
    }
}
