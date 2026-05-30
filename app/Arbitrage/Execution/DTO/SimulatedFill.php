<?php

declare(strict_types=1);

namespace App\Arbitrage\Execution\DTO;

/**
 * Fill simulado de una de las dos patas (buy o sell) de la operación.
 */
final class SimulatedFill
{
    public function __construct(
        public readonly string $side,
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly float $baseVolume,
        public readonly float $price,
        public readonly float $notional,
        public readonly float $fee,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'side' => $this->side,
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'base_volume' => $this->baseVolume,
            'price' => $this->price,
            'notional' => $this->notional,
            'fee' => $this->fee,
        ];
    }
}
