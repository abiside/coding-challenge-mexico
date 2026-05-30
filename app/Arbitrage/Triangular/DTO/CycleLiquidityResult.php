<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\DTO;

/**
 * Resultado de evaluar la liquidez de un ciclo: cantidad de partida que
 * efectivamente se puede ejecutar respetando la profundidad de cada pata,
 * cantidad final del mismo activo tras todas las conversiones, y el detalle
 * por pata (cantidades, VWAP, fees aplicados).
 */
final class CycleLiquidityResult
{
    /**
     * @param  array<int, CycleLeg>  $legs
     */
    public function __construct(
        public readonly array $legs,
        public readonly float $startAmount,
        public readonly float $endAmount,
        public readonly bool $partial,
    ) {
    }

    public function isExecutable(): bool
    {
        return $this->startAmount > 0.0 && $this->endAmount > 0.0;
    }

    /**
     * Ganancia bruta en unidades del activo inicial: cuanto MAS recibimos del
     * mismo activo después de cerrar el ciclo, sin descontar fees (las fees
     * ya están dentro del endAmount, pero las exponemos también separadas
     * para el desglose).
     *
     * Nota: aquí el "bruto" se entiende como cantidad neta tras fees del
     * último step, ya que el book walking incluye fees implícitas vía las
     * tasas netas. Para el desglose explícito se usa `CycleProfitabilityResult`.
     */
    public function deltaInStartAsset(): float
    {
        return $this->endAmount - $this->startAmount;
    }

    public static function empty(): self
    {
        return new self([], 0.0, 0.0, false);
    }
}
