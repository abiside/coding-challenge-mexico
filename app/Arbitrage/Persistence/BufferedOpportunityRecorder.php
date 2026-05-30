<?php

declare(strict_types=1);

namespace App\Arbitrage\Persistence;

use App\Arbitrage\Contracts\OpportunityRecorderInterface;
use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Execution\DTO\SimulationResult;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Mapea las oportunidades evaluadas a filas y las empuja al PersistenceBuffer.
 * Filtra qué decisiones se persisten para no guardar ruido.
 */
final class BufferedOpportunityRecorder implements OpportunityRecorderInterface
{
    /**
     * @param  array<int, string>  $recordDecisions  decisiones a persistir (execute/reject)
     */
    public function __construct(
        private readonly PersistenceBuffer $buffer,
        private readonly array $recordDecisions = ['execute', 'reject'],
        private readonly ?int $userId = null,
        private readonly ?int $strategyId = null,
    ) {}

    public function record(
        EvaluatedOpportunity $opportunity,
        RiskDecision $decision,
        ?SimulationResult $simulation = null,
    ): void {
        if (! in_array($decision->decision->value, $this->recordDecisions, true)) {
            return;
        }

        $data = $opportunity->toArray();

        $opportunityRow = [
            'user_id' => $this->userId,
            'strategy_id' => $this->strategyId,
            'symbol' => $data['symbol'],
            'buy_exchange' => $data['buy_exchange'],
            'sell_exchange' => $data['sell_exchange'],
            'buy_ask' => $data['buy_ask'],
            'sell_bid' => $data['sell_bid'],
            'gross_spread_bps' => $data['gross_spread_bps'],
            'base_volume' => $data['base_volume'],
            'weighted_buy_price' => $data['weighted_buy_price'],
            'weighted_sell_price' => $data['weighted_sell_price'],
            'gross_profit' => $data['gross_profit'],
            'net_profit' => $data['net_profit'],
            // Resultado realmente ejecutado vs. lo evaluado: en modo simulación
            // con slippage de ejecución estos divergen del net_profit.
            'realized_pnl' => $simulation?->realizedPnl,
            'execution_delta' => $simulation !== null
                ? round($simulation->realizedPnl - (float) $data['net_profit'], 8)
                : null,
            'net_margin' => $data['net_margin'],
            'total_costs' => $data['total_costs'],
            'buy_fee' => $data['buy_fee'],
            'sell_fee' => $data['sell_fee'],
            'slippage_cost' => $data['slippage_cost'],
            'latency_penalty' => $data['latency_penalty'],
            'fixed_cost' => $data['fixed_cost'],
            'partial_fill' => $data['partial_fill'],
            'decision' => $decision->decision->value,
            'reasons' => $decision->reasons,
            'detected_at_ms' => $data['detected_at_ms'],
        ];

        $tradeRow = null;
        $fillRows = [];

        if ($simulation !== null) {
            $tradeRow = [
                'user_id' => $this->userId,
                'strategy_id' => $this->strategyId,
                'symbol' => $simulation->symbol,
                'buy_exchange' => $simulation->buyFill->exchange,
                'sell_exchange' => $simulation->sellFill->exchange,
                'base_volume' => $simulation->buyFill->baseVolume,
                'realized_pnl' => $simulation->realizedPnl,
                'status' => 'simulated',
                'idempotency_key' => $simulation->idempotencyKey,
                'executed_at_ms' => $simulation->executedAtMs,
            ];

            foreach ([$simulation->buyFill, $simulation->sellFill] as $fill) {
                $fillRows[] = [
                    'side' => $fill->side,
                    'exchange' => $fill->exchange,
                    'symbol' => $fill->symbol,
                    'base_volume' => $fill->baseVolume,
                    'price' => $fill->price,
                    'notional' => $fill->notional,
                    'fee' => $fill->fee,
                ];
            }
        }

        $this->buffer->push($opportunityRow, $tradeRow, $fillRows);
    }
}
