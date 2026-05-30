<?php

declare(strict_types=1);

namespace App\Arbitrage\Engine;

use App\Arbitrage\Contracts\DashboardPublisherInterface;
use App\Arbitrage\Contracts\DiscardRecorderInterface;
use App\Arbitrage\Contracts\ExecutionSimulatorInterface;
use App\Arbitrage\Contracts\OpportunityRecorderInterface;
use App\Arbitrage\Contracts\OrderBookStoreInterface;
use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Engine\DTO\OpportunityCandidate;
use App\Arbitrage\Engine\DTO\ProcessedOpportunity;
use App\Arbitrage\Execution\DTO\SimulationResult;
use App\Arbitrage\Execution\WalletValidator;
use App\Arbitrage\Risk\Decision;
use App\Arbitrage\Risk\RiskDecision;
use App\Arbitrage\Risk\RiskManager;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Orquesta el pipeline completo de arbitraje disparado por cada update de
 * order book: detección -> liquidez/slippage -> rentabilidad -> validación de
 * wallet -> riesgo -> simulación -> persistencia/publicación.
 *
 * No conoce transporte (Redis/WS); recibe snapshots ya normalizados.
 */
final class ArbitrageEngine
{
    public function __construct(
        private readonly OrderBookStoreInterface $store,
        private readonly ArbitrageScanner $scanner,
        private readonly LiquidityCalculator $liquidity,
        private readonly ProfitabilityCalculator $profitability,
        private readonly WalletValidator $walletValidator,
        private readonly RiskManager $riskManager,
        private readonly ExecutionSimulatorInterface $simulator,
        private readonly FeeSchedule $fees,
        private readonly OpportunityRecorderInterface $recorder,
        private readonly DashboardPublisherInterface $dashboard,
        private readonly float $maxBaseVolume,
        private readonly float $minBaseVolume,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $diagnostics = false,
        private readonly ?DiscardRecorderInterface $discards = null,
    ) {
    }

    /**
     * Procesa un snapshot entrante y devuelve las oportunidades evaluadas.
     *
     * @return array<int, ProcessedOpportunity>
     */
    public function onSnapshot(OrderBookSnapshot $snapshot, ?int $receivedAtMs = null): array
    {
        $updated = $this->store->apply($snapshot, $receivedAtMs);
        $nowMs = $receivedAtMs ?? (int) (microtime(true) * 1000);

        $processed = [];
        foreach ($this->scanner->scan($updated, $nowMs) as $candidate) {
            $outcome = $this->process($candidate, $nowMs);
            if ($outcome !== null) {
                $processed[] = $outcome;
            }
        }

        return $processed;
    }

    private function process(OpportunityCandidate $candidate, int $nowMs): ?ProcessedOpportunity
    {
        // 5) Liquidez / slippage al volumen objetivo (limitado por profundidad).
        $liquidity = $this->liquidity->evaluate($candidate, $this->maxBaseVolume);
        if (! $liquidity->isExecutable()) {
            $this->discards?->recordDiscard('not_executable');
            $this->diag('discard_not_executable', [
                'symbol' => $candidate->symbol,
                'buy_exchange' => $candidate->buyExchange(),
                'sell_exchange' => $candidate->sellExchange(),
                'gross_spread_bps' => round($candidate->grossSpreadBps(), 4),
                'executable_volume' => $liquidity->executableBaseVolume,
                'weighted_buy_price' => $liquidity->weightedBuyPrice,
                'weighted_sell_price' => $liquidity->weightedSellPrice,
                'max_base_volume' => $this->maxBaseVolume,
            ]);

            return null;
        }

        // 6) Rentabilidad al volumen ejecutable por liquidez.
        $combinedAge = $candidate->buyBook->ageMs($nowMs) + $candidate->sellBook->ageMs($nowMs);
        $profit = $this->profitability->evaluate($candidate, $liquidity, $combinedAge);
        $evaluated = new EvaluatedOpportunity($candidate, $liquidity, $profit);

        // 7) Validación de wallet: define volumen final por balances.
        $buyFeeRate = $this->fees->for($candidate->buyExchange());
        $walletMax = $this->walletValidator->maxExecutableVolume(
            $candidate,
            $liquidity->weightedBuyPrice,
            $buyFeeRate,
        );

        if ($walletMax < $this->minBaseVolume) {
            $decision = RiskDecision::reject(sprintf(
                'insufficient_balance: wallet_max=%.8f min=%.8f',
                $walletMax,
                $this->minBaseVolume,
            ));

            return $this->finalize($evaluated, $decision, null);
        }

        // Si el balance limita el volumen, recalcular liquidez/rentabilidad.
        if ($walletMax < $liquidity->executableBaseVolume) {
            $liquidity = $this->liquidity->evaluate($candidate, $walletMax);
            $profit = $this->profitability->evaluate($candidate, $liquidity, $combinedAge);
            $evaluated = new EvaluatedOpportunity($candidate, $liquidity, $profit);
        }

        // 8) Riesgo: decisión final tipada.
        $decision = $this->riskManager->assess($evaluated, $nowMs);

        // 9) Ejecución simulada (single-writer) solo si se aprueba.
        $simulation = null;
        if ($decision->shouldExecute()) {
            $simulation = $this->simulator->simulate($evaluated, $this->idempotencyKey($candidate));
        }

        return $this->finalize($evaluated, $decision, $simulation);
    }

    private function finalize(
        EvaluatedOpportunity $evaluated,
        RiskDecision $decision,
        ?SimulationResult $simulation,
    ): ProcessedOpportunity {
        // 10) Persistencia y publicación desacopladas (no en hot path real).
        if ($decision->decision !== Decision::Ignore) {
            $this->recorder->record($evaluated, $decision, $simulation);
        }
        $this->dashboard->publishDecision($evaluated, $decision, $simulation);

        if ($decision->decision !== Decision::Execute) {
            // Embudo de descartes: razón normalizada del primer motivo de la
            // decisión (p. ej. "risk:low_net_profit", "risk:insufficient_balance").
            $this->discards?->recordDiscard('risk:'.$this->reasonKey($decision));
        }

        $this->diag('decision_'.$decision->decision->value, [
            'symbol' => $evaluated->symbol(),
            'buy_exchange' => $evaluated->buyExchange(),
            'sell_exchange' => $evaluated->sellExchange(),
            'reasons' => $decision->reasons,
            'gross_spread_bps' => round($evaluated->candidate->grossSpreadBps(), 4),
            'net_profit' => round($evaluated->profitability->netProfit, 8),
            'net_margin' => round($evaluated->profitability->netMargin(), 8),
            'total_costs' => round($evaluated->profitability->totalCosts(), 8),
            'volume' => $evaluated->liquidity->executableBaseVolume,
            'final_volume' => $decision->finalVolume,
            'executed' => $simulation !== null && ! $simulation->duplicate,
        ]);

        return new ProcessedOpportunity($evaluated, $decision, $simulation);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function diag(string $event, array $context): void
    {
        if (! $this->diagnostics || $this->logger === null) {
            return;
        }

        $this->logger->debug('[arbitrage][engine] '.$event, $context);
    }

    /**
     * Extrae una clave estable del primer motivo de la decisión, descartando
     * los valores dinámicos tras ":" (p. ej. "low_net_profit: net=..." →
     * "low_net_profit"). Mantiene acotada la cardinalidad del embudo.
     */
    private function reasonKey(RiskDecision $decision): string
    {
        $first = $decision->reasons[0] ?? 'unknown';
        $colon = strpos($first, ':');

        return $colon === false ? $first : substr($first, 0, $colon);
    }

    private function idempotencyKey(OpportunityCandidate $candidate): string
    {
        return sprintf(
            '%s|%s->%s|%d',
            $candidate->symbol,
            $candidate->buyExchange(),
            $candidate->sellExchange(),
            $candidate->detectedAtMs,
        );
    }
}
