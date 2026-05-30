<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Execution\DTO;

/**
 * Pata simulada efectivamente ejecutada (con su deriva de precio aplicada).
 */
final class CycleSimulatedLeg
{
    public function __construct(
        public readonly string $kind,
        public readonly string $fromExchange,
        public readonly string $fromAsset,
        public readonly string $toExchange,
        public readonly string $toAsset,
        public readonly ?string $symbol,
        public readonly float $amountIn,
        public readonly float $amountOut,
        public readonly float $price,
        public readonly float $fee,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'from_exchange' => $this->fromExchange,
            'from_asset' => $this->fromAsset,
            'to_exchange' => $this->toExchange,
            'to_asset' => $this->toAsset,
            'symbol' => $this->symbol,
            'amount_in' => round($this->amountIn, 12),
            'amount_out' => round($this->amountOut, 12),
            'price' => round($this->price, 12),
            'fee' => round($this->fee, 12),
        ];
    }
}
