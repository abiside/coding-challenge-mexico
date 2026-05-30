<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\DTO;

/**
 * Pata simulada de un ciclo, con cantidades, precio promedio y fee reales
 * tras recorrer la profundidad del book. Para aristas de transferencia (sin
 * book) el `weightedPrice` es 1 (o `1 - transferCost`) y no hay fee.
 */
final class CycleLeg
{
    public function __construct(
        public readonly EdgeKind $kind,
        public readonly string $fromExchange,
        public readonly string $fromAsset,
        public readonly string $toExchange,
        public readonly string $toAsset,
        public readonly ?string $symbol,
        public readonly float $amountIn,
        public readonly float $amountOut,
        public readonly float $weightedPrice,
        public readonly float $fee,
        public readonly float $feeRate,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'from_exchange' => $this->fromExchange,
            'from_asset' => $this->fromAsset,
            'to_exchange' => $this->toExchange,
            'to_asset' => $this->toAsset,
            'symbol' => $this->symbol,
            'amount_in' => round($this->amountIn, 12),
            'amount_out' => round($this->amountOut, 12),
            'weighted_price' => round($this->weightedPrice, 12),
            'fee' => round($this->fee, 12),
            'fee_rate' => $this->feeRate,
        ];
    }
}
