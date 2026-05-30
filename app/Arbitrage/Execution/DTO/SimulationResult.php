<?php

declare(strict_types=1);

namespace App\Arbitrage\Execution\DTO;

/**
 * Resultado de la simulación de ejecución de una oportunidad: las dos patas
 * (buy/sell), el P&L neto y la clave de idempotencia usada.
 */
final class SimulationResult
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $symbol,
        public readonly SimulatedFill $buyFill,
        public readonly SimulatedFill $sellFill,
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
            'symbol' => $this->symbol,
            'buy_fill' => $this->buyFill->toArray(),
            'sell_fill' => $this->sellFill->toArray(),
            'realized_pnl' => round($this->realizedPnl, 8),
            'executed_at_ms' => $this->executedAtMs,
            'duplicate' => $this->duplicate,
        ];
    }
}
