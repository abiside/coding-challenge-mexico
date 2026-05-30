<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Persistence;

use App\Arbitrage\Persistence\PersistenceBuffer;
use App\Arbitrage\Risk\RiskDecision;
use App\Arbitrage\Triangular\Contracts\CycleRecorderInterface;
use App\Arbitrage\Triangular\DTO\EvaluatedCycle;
use App\Arbitrage\Triangular\Execution\DTO\CycleSimulationResult;

/**
 * Convierte ciclos evaluados en filas para `triangular_opportunities` y las
 * empuja al `PersistenceBuffer` compartido (mismo lote/flush que opps de 2
 * patas). Filtra qué decisiones se persisten para no almacenar ruido.
 */
final class BufferedCycleRecorder implements CycleRecorderInterface
{
    /**
     * @param  array<int, string>  $recordDecisions
     */
    public function __construct(
        private readonly PersistenceBuffer $buffer,
        private readonly array $recordDecisions = ['execute', 'reject'],
        private readonly ?int $userId = null,
        private readonly ?int $strategyId = null,
    ) {
    }

    public function record(
        EvaluatedCycle $cycle,
        RiskDecision $decision,
        ?CycleSimulationResult $simulation = null,
    ): void {
        if (! in_array($decision->decision->value, $this->recordDecisions, true)) {
            return;
        }

        $data = $cycle->toArray();
        $row = [
            'user_id' => $this->userId,
            'strategy_id' => $this->strategyId,
            'label' => $data['label'],
            'start_asset' => $data['start_asset'],
            'start_exchange' => $data['start_exchange'],
            'cycle_length' => $data['cycle_length'],
            'gross_spread_bps' => $data['gross_spread_bps'],
            'net_rate_product' => $data['net_rate_product'],
            'start_amount' => $data['start_amount'],
            'end_amount' => $data['end_amount'],
            'gross_profit' => $data['gross_profit'],
            'net_profit' => $data['net_profit'],
            'net_margin' => $data['net_margin'],
            'total_costs' => $data['total_costs'],
            'total_fees' => $data['total_fees'],
            'latency_penalty' => $data['latency_penalty'],
            'fixed_cost' => $data['fixed_cost'],
            'realized_pnl' => $simulation?->realizedPnl,
            'execution_delta' => $simulation !== null
                ? round($simulation->realizedPnl - (float) $data['net_profit'], 12)
                : null,
            'partial_fill' => $data['partial_fill'],
            'decision' => $decision->decision->value,
            'reasons' => $decision->reasons,
            'legs' => $data['legs'],
            'exchanges' => $data['exchanges'],
            'idempotency_key' => $simulation?->idempotencyKey,
            'detected_at_ms' => $data['detected_at_ms'],
            'executed_at_ms' => $simulation?->executedAtMs,
            'evaluation_latency_us' => $data['evaluation_latency_us'],
        ];

        $this->buffer->pushCycle($row);
    }
}
