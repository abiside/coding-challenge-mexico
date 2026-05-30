<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Execution;

use App\Arbitrage\Execution\WalletManager;
use App\Arbitrage\Triangular\DTO\CycleLeg;
use App\Arbitrage\Triangular\DTO\EdgeKind;
use App\Arbitrage\Triangular\DTO\EvaluatedCycle;
use App\Arbitrage\Triangular\Execution\DTO\CycleSimulatedLeg;
use App\Arbitrage\Triangular\Execution\DTO\CycleSimulationResult;

/**
 * Simula la ejecución de un ciclo aprobado por el RiskManager.
 *
 * Es el ÚNICO escritor de balances para ciclos: arma los deltas multi-pata
 * (uno por pata: salida del activo de entrada, entrada del activo de salida)
 * y los aplica con `WalletManager::applyDeltas`, validando todo o nada y
 * notificando al ledger por cada movimiento. Garantiza idempotencia por
 * `idempotencyKey` para que reintentos no dupliquen ejecuciones.
 */
final class CycleExecutionSimulator
{
    /**
     * @var array<string, CycleSimulationResult>
     */
    private array $executed = [];

    public function __construct(
        private readonly WalletManager $wallets,
        // Slippage de ejecución (modo simulación): deriva máx (%) del precio
        // de fill por pata respecto al precio evaluado.
        private readonly float $execDriftPct = 0.0,
    ) {
    }

    public function simulate(EvaluatedCycle $opportunity, string $idempotencyKey): CycleSimulationResult
    {
        if (isset($this->executed[$idempotencyKey])) {
            $prior = $this->executed[$idempotencyKey];

            return new CycleSimulationResult(
                idempotencyKey: $prior->idempotencyKey,
                startAsset: $prior->startAsset,
                startExchange: $prior->startExchange,
                legs: $prior->legs,
                startAmount: $prior->startAmount,
                endAmount: $prior->endAmount,
                realizedPnl: $prior->realizedPnl,
                executedAtMs: $prior->executedAtMs,
                duplicate: true,
            );
        }

        $candidate = $opportunity->candidate;
        $liquidity = $opportunity->liquidity;
        $profitability = $opportunity->profitability;

        $startAmount = $liquidity->startAmount;
        $startNode = $candidate->start();

        $deltas = [];
        $simulatedLegs = [];
        $currentAmount = $startAmount;

        foreach ($liquidity->legs as $index => $leg) {
            $applied = $this->applyDriftIfNeeded($candidate, $leg, $currentAmount, $index);

            $deltas[] = [
                'exchange' => $applied->fromExchange,
                'asset' => $applied->fromAsset,
                'delta' => -$applied->amountIn,
                'reason' => 'triangular_'.$applied->kind,
                'ref' => $idempotencyKey,
            ];
            $deltas[] = [
                'exchange' => $applied->toExchange,
                'asset' => $applied->toAsset,
                'delta' => $applied->amountOut,
                'reason' => 'triangular_'.$applied->kind,
                'ref' => $idempotencyKey,
            ];

            $simulatedLegs[] = $applied;
            $currentAmount = $applied->amountOut;
        }

        // Single-writer: mutación atómica del ciclo entero.
        $this->wallets->applyDeltas($deltas);

        $endAmount = $currentAmount;
        $grossProfit = $endAmount - $startAmount;
        $realizedPnl = $grossProfit - $profitability->latencyPenalty - $profitability->fixedCost;

        $result = new CycleSimulationResult(
            idempotencyKey: $idempotencyKey,
            startAsset: $startNode->asset,
            startExchange: $startNode->exchange,
            legs: $simulatedLegs,
            startAmount: $startAmount,
            endAmount: $endAmount,
            realizedPnl: $realizedPnl,
            executedAtMs: (int) (microtime(true) * 1000),
        );

        $this->executed[$idempotencyKey] = $result;

        return $result;
    }

    /**
     * Aplica deriva de ejecución determinista a la pata (solo modo simulación).
     * Para mantener el ciclo consistente, la deriva afecta al precio (y por
     * tanto al output), pero el input es el output de la pata anterior.
     */
    private function applyDriftIfNeeded(
        \App\Arbitrage\Triangular\DTO\CycleCandidate $candidate,
        CycleLeg $leg,
        float $amountIn,
        int $legIndex,
    ): CycleSimulatedLeg {
        $kind = $leg->kind->value;

        if ($this->execDriftPct <= 0.0 || $leg->kind === EdgeKind::Transfer) {
            return new CycleSimulatedLeg(
                kind: $kind,
                fromExchange: $leg->fromExchange,
                fromAsset: $leg->fromAsset,
                toExchange: $leg->toExchange,
                toAsset: $leg->toAsset,
                symbol: $leg->symbol,
                amountIn: $amountIn,
                amountOut: $this->recomputeAmountOut($leg, $amountIn),
                price: $leg->weightedPrice,
                fee: $leg->fee,
            );
        }

        $drift = $this->driftFor($candidate, $legIndex);
        $factor = 1.0 + $drift;
        $driftedPrice = $leg->weightedPrice * $factor;

        $feeRate = $leg->feeRate;
        if ($leg->kind === EdgeKind::TradeBuy) {
            $baseAcquired = $driftedPrice > 0.0 ? $amountIn / $driftedPrice : 0.0;
            $fee = $baseAcquired * $feeRate;
            $amountOut = $baseAcquired - $fee;
        } else {
            $quoteAcquired = $amountIn * $driftedPrice;
            $fee = $quoteAcquired * $feeRate;
            $amountOut = $quoteAcquired - $fee;
        }

        return new CycleSimulatedLeg(
            kind: $kind,
            fromExchange: $leg->fromExchange,
            fromAsset: $leg->fromAsset,
            toExchange: $leg->toExchange,
            toAsset: $leg->toAsset,
            symbol: $leg->symbol,
            amountIn: $amountIn,
            amountOut: $amountOut,
            price: $driftedPrice,
            fee: $fee,
        );
    }

    /**
     * Recomputa amountOut a partir del amountIn real (que puede haber cambiado
     * por la pata anterior con deriva), preservando la tasa neta evaluada.
     */
    private function recomputeAmountOut(CycleLeg $leg, float $amountIn): float
    {
        if ($leg->amountIn <= 0.0) {
            return 0.0;
        }
        // Mantiene la proporción amountOut/amountIn evaluada.
        $ratio = $leg->amountOut / $leg->amountIn;

        return $amountIn * $ratio;
    }

    /**
     * Deriva determinista en [-execDriftPct, +execDriftPct] como fracción,
     * sembrada con la identidad del ciclo + índice de pata. Garantiza que dos
     * estrategias evaluando el MISMO ciclo en el MISMO instante sufran la
     * misma desviación de precio.
     */
    private function driftFor(\App\Arbitrage\Triangular\DTO\CycleCandidate $candidate, int $legIndex): float
    {
        $seed = $candidate->key().'|leg='.$legIndex.'|'.$candidate->detectedAtMs.'|'.$this->execDriftPct;
        $unit = (crc32($seed) / 4294967295.0) * 2.0 - 1.0;

        return $unit * ($this->execDriftPct / 100.0);
    }
}
