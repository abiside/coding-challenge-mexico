<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Execution\DTO;

/**
 * Resultado de simular la ejecución completa de un ciclo: todas las patas,
 * el P&L realizado en el activo de partida y la clave de idempotencia.
 */
final class CycleSimulationResult
{
    /**
     * @param  array<int, CycleSimulatedLeg>  $legs
     */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $startAsset,
        public readonly string $startExchange,
        public readonly array $legs,
        public readonly float $startAmount,
        public readonly float $endAmount,
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
            'start_asset' => $this->startAsset,
            'start_exchange' => $this->startExchange,
            'legs' => array_map(static fn ($l) => $l->toArray(), $this->legs),
            'start_amount' => round($this->startAmount, 12),
            'end_amount' => round($this->endAmount, 12),
            'realized_pnl' => round($this->realizedPnl, 12),
            'executed_at_ms' => $this->executedAtMs,
            'duplicate' => $this->duplicate,
        ];
    }
}
