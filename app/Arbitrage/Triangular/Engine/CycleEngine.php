<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Engine;

use App\Arbitrage\Contracts\DiscardRecorderInterface;
use App\Arbitrage\Contracts\OrderBookStoreInterface;
use App\Arbitrage\Risk\Decision;
use App\Arbitrage\Risk\RiskDecision;
use App\Arbitrage\Risk\RiskManager;
use App\Arbitrage\Triangular\Contracts\CycleDashboardPublisherInterface;
use App\Arbitrage\Triangular\Contracts\CycleRecorderInterface;
use App\Arbitrage\Triangular\DTO\CycleCandidate;
use App\Arbitrage\Triangular\DTO\CycleLiquidityResult;
use App\Arbitrage\Triangular\DTO\EvaluatedCycle;
use App\Arbitrage\Triangular\DTO\ProcessedCycle;
use App\Arbitrage\Triangular\Execution\CycleExecutionSimulator;
use App\Arbitrage\Triangular\Execution\CycleWalletValidator;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Orquesta el pipeline completo de ciclos triangulares disparado por cada
 * update de order book: scan -> liquidez/slippage -> rentabilidad ->
 * validación de wallet -> riesgo -> simulación -> persistencia/publicación.
 *
 * Comparte el `OrderBookStore` y el `WalletManager` con el engine de 2 patas
 * (mismo `EngineRuntime`), por lo que opera sobre la misma fuente de verdad
 * de mercado y wallet.
 */
final class CycleEngine
{
    public function __construct(
        private readonly OrderBookStoreInterface $store,
        private readonly CycleScanner $scanner,
        private readonly CycleLiquidityCalculator $liquidity,
        private readonly CycleProfitabilityCalculator $profitability,
        private readonly CycleWalletValidator $walletValidator,
        private readonly RiskManager $riskManager,
        private readonly CycleExecutionSimulator $simulator,
        private readonly CycleRecorderInterface $recorder,
        private readonly CycleDashboardPublisherInterface $dashboard,
        private readonly float $maxStartAmount,
        private readonly float $minStartAmount,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $diagnostics = false,
        private readonly ?DiscardRecorderInterface $discards = null,
    ) {
    }

    /**
     * Procesa un snapshot entrante: aplica el book al store (idempotente con
     * el engine de 2 patas) y dispara el scan de ciclos sobre el book
     * actualizado.
     *
     * @return array<int, ProcessedCycle>
     */
    public function onSnapshot(OrderBookSnapshot $snapshot, ?int $receivedAtMs = null): array
    {
        // Reloj monotónico anclado a la aparición del order book disparador,
        // para medir la latencia de evaluación (aparición -> decisión) del ciclo.
        $arrivalNs = hrtime(true);

        $updated = $this->store->apply($snapshot, $receivedAtMs);
        $nowMs = $receivedAtMs ?? (int) (microtime(true) * 1000);

        $processed = [];
        foreach ($this->scanner->scan($updated, $nowMs) as $candidate) {
            $outcome = $this->process($candidate, $nowMs, $arrivalNs);
            if ($outcome !== null) {
                $processed[] = $outcome;
            }
        }

        return $processed;
    }

    private function process(CycleCandidate $candidate, int $nowMs, int $arrivalNs): ?ProcessedCycle
    {
        // Volumen objetivo: capado por config y por balance del activo inicial.
        $walletMax = $this->walletValidator->maxStartAmount($candidate);
        if ($walletMax < $this->minStartAmount) {
            $evaluated = new EvaluatedCycle(
                candidate: $candidate,
                liquidity: CycleLiquidityResult::empty(),
                profitability: new \App\Arbitrage\Triangular\DTO\CycleProfitabilityResult(
                    startAmount: 0.0,
                    endAmount: 0.0,
                    grossProfit: 0.0,
                    totalFeesInStart: 0.0,
                    latencyPenalty: 0.0,
                    fixedCost: 0.0,
                    netProfit: 0.0,
                ),
            );
            $decision = RiskDecision::reject(sprintf(
                'insufficient_balance: wallet_max=%.8f min=%.8f',
                $walletMax,
                $this->minStartAmount,
            ));

            return $this->finalize($evaluated, $decision, null, $arrivalNs);
        }

        $target = min($this->maxStartAmount, $walletMax);
        $liquidity = $this->liquidity->evaluate($candidate, $target);
        if (! $liquidity->isExecutable()) {
            $this->discards?->recordDiscard('cycle:not_executable');
            $this->diag('discard_not_executable', [
                'label' => $candidate->label(),
                'gross_spread_bps' => round($candidate->grossSpreadBps(), 4),
                'wallet_max' => $walletMax,
            ]);

            return null;
        }

        $combinedAge = 0;
        foreach ($candidate->edges as $edge) {
            if ($edge->book !== null) {
                $combinedAge += $edge->book->ageMs($nowMs);
            }
        }

        $profit = $this->profitability->evaluate($liquidity, $combinedAge);
        $evaluated = new EvaluatedCycle($candidate, $liquidity, $profit);

        // Riesgo: decisión final tipada (mismos guards que opps de 2 patas).
        $decision = $this->riskManager->assess($evaluated, $nowMs);

        $simulation = null;
        if ($decision->shouldExecute()) {
            try {
                $simulation = $this->simulator->simulate($evaluated, $this->idempotencyKey($candidate));
            } catch (\RuntimeException $e) {
                // Balance insuficiente en alguna pata intermedia (raro tras
                // walletValidator, pero posible si la projección de deltas
                // sale negativa por activos intermedios sin saldo previo).
                $decision = RiskDecision::reject('execution_failed: '.$e->getMessage());
            }
        }

        return $this->finalize($evaluated, $decision, $simulation, $arrivalNs);
    }

    private function finalize(
        EvaluatedCycle $evaluated,
        RiskDecision $decision,
        $simulation,
        int $arrivalNs,
    ): ProcessedCycle {
        // Latencia de evaluación: aparición del order book -> decisión, en µs.
        $evaluated->setEvaluationLatencyUs((int) round((hrtime(true) - $arrivalNs) / 1000));

        if ($decision->decision !== Decision::Ignore) {
            $this->recorder->record($evaluated, $decision, $simulation);
        }
        $this->dashboard->publishCycleDecision($evaluated, $decision, $simulation);

        if ($decision->decision !== Decision::Execute) {
            $this->discards?->recordDiscard('cycle:risk:'.$this->reasonKey($decision));
        }

        $this->diag('decision_'.$decision->decision->value, [
            'label' => $evaluated->label(),
            'start_asset' => $evaluated->baseAsset(),
            'reasons' => $decision->reasons,
            'gross_spread_bps' => round($evaluated->candidate->grossSpreadBps(), 4),
            'net_profit' => round($evaluated->profitability->netProfit, 12),
            'net_margin' => round($evaluated->profitability->netMargin(), 10),
            'start_amount' => $evaluated->liquidity->startAmount,
            'end_amount' => $evaluated->liquidity->endAmount,
            'evaluation_latency_us' => $evaluated->evaluationLatencyUs(),
            'executed' => $simulation !== null && ! $simulation->duplicate,
        ]);

        return new ProcessedCycle($evaluated, $decision, $simulation);
    }

    private function reasonKey(RiskDecision $decision): string
    {
        $first = $decision->reasons[0] ?? 'unknown';
        $colon = strpos($first, ':');

        return $colon === false ? $first : substr($first, 0, $colon);
    }

    private function idempotencyKey(CycleCandidate $candidate): string
    {
        return sprintf('%s|%d', $candidate->key(), $candidate->detectedAtMs);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function diag(string $event, array $context): void
    {
        if (! $this->diagnostics || $this->logger === null) {
            return;
        }

        $this->logger->debug('[arbitrage][cycle-engine] '.$event, $context);
    }
}
