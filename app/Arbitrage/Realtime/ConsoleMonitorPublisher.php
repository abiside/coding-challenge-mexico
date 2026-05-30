<?php

declare(strict_types=1);

namespace App\Arbitrage\Realtime;

use App\Arbitrage\Contracts\DashboardPublisherInterface;
use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Execution\DTO\SimulationResult;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Publisher de dashboard que, en lugar de Reverb/DB, acumula las últimas
 * decisiones y trades del engine en buffers en memoria para que un monitor de
 * consola los renderice en vivo. Puramente de observación.
 */
final class ConsoleMonitorPublisher implements DashboardPublisherInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $decisions = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $trades = [];

    private int $totalEvents = 0;

    public function __construct(
        private readonly int $maxDecisions = 12,
        private readonly int $maxTrades = 10,
    ) {
    }

    public function publishDecision(
        EvaluatedOpportunity $opportunity,
        RiskDecision $decision,
        ?SimulationResult $simulation = null,
    ): void {
        $data = $opportunity->toArray();
        $this->totalEvents++;

        array_unshift($this->decisions, [
            'at' => microtime(true),
            'symbol' => $data['symbol'],
            'buy_exchange' => $data['buy_exchange'],
            'sell_exchange' => $data['sell_exchange'],
            'gross_spread_bps' => (float) $data['gross_spread_bps'],
            'base_volume' => (float) $data['base_volume'],
            'net_profit' => (float) $data['net_profit'],
            'decision' => $decision->decision->value,
            'reasons' => $decision->reasons,
        ]);
        $this->decisions = array_slice($this->decisions, 0, $this->maxDecisions);

        if ($simulation !== null && ! $simulation->duplicate) {
            array_unshift($this->trades, [
                'at' => microtime(true),
                'symbol' => $simulation->symbol,
                'buy_exchange' => $simulation->buyFill->exchange,
                'sell_exchange' => $simulation->sellFill->exchange,
                'base_volume' => $simulation->buyFill->baseVolume,
                'realized_pnl' => $simulation->realizedPnl,
            ]);
            $this->trades = array_slice($this->trades, 0, $this->maxTrades);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function trades(): array
    {
        return $this->trades;
    }

    public function totalEvents(): int
    {
        return $this->totalEvents;
    }
}
