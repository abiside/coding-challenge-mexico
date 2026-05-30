<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\DTO;

/**
 * Resultado de una ejecución simulada (BUY o SELL) sobre la billetera.
 */
final class MeanReversionSimulationResult
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly Side $side,
        public readonly float $price,
        public readonly float $baseQuantity,
        public readonly float $quoteAmount,
        public readonly float $fee,
        // Solo relevante en ventas: P&L realizado contra el costo promedio.
        public readonly float $realizedPnl,
        public readonly int $executedAtMs,
        public readonly bool $duplicate = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'side' => $this->side->value,
            'price' => $this->price,
            'base_quantity' => $this->baseQuantity,
            'quote_amount' => $this->quoteAmount,
            'fee' => $this->fee,
            'realized_pnl' => round($this->realizedPnl, 8),
            'executed_at_ms' => $this->executedAtMs,
            'duplicate' => $this->duplicate,
        ];
    }
}
