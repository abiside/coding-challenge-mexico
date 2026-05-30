<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\DTO;

use App\Arbitrage\Contracts\ProfitableTrade;
use App\Arbitrage\Risk\CircuitBreaker;

/**
 * Ciclo totalmente evaluado: candidato + liquidez + rentabilidad. Es la
 * entrada del RiskManager para ciclos triangulares.
 *
 * Implementa `ProfitableTrade` para reutilizar los mismos guards y
 * circuit breaker que las oportunidades de 2 patas.
 */
final class EvaluatedCycle implements ProfitableTrade
{
    /**
     * Latencia de evaluación en microsegundos: tiempo desde que el order book
     * disparador llegó al engine hasta que se decidió sobre este ciclo. Se
     * rellena en `finalize()`; null hasta que el ciclo haya sido decidido.
     */
    private ?int $evaluationLatencyUs = null;

    public function __construct(
        public readonly CycleCandidate $candidate,
        public readonly CycleLiquidityResult $liquidity,
        public readonly CycleProfitabilityResult $profitability,
    ) {
    }

    public function setEvaluationLatencyUs(int $microseconds): void
    {
        $this->evaluationLatencyUs = max(0, $microseconds);
    }

    public function evaluationLatencyUs(): ?int
    {
        return $this->evaluationLatencyUs;
    }

    public function label(): string
    {
        return $this->candidate->label();
    }

    public function baseAsset(): string
    {
        return $this->candidate->startAsset();
    }

    public function netProfit(): float
    {
        return $this->profitability->netProfit;
    }

    public function netMargin(): float
    {
        return $this->profitability->netMargin();
    }

    public function executableVolume(): float
    {
        return $this->liquidity->startAmount;
    }

    public function combinedAgeMs(?int $nowMs = null): int
    {
        return array_sum($this->bookAgesMs($nowMs));
    }

    public function bookAgesMs(?int $nowMs = null): array
    {
        $ages = [];
        foreach ($this->candidate->edges as $edge) {
            if ($edge->book === null) {
                continue;
            }
            $ages[] = $edge->book->ageMs($nowMs);
        }

        return $ages;
    }

    public function circuitBreakerKey(): string
    {
        return CircuitBreaker::keyForCycle($this->candidate->key());
    }

    /**
     * @return array<int, string>
     */
    public function exchanges(): array
    {
        $list = [];
        foreach ($this->candidate->edges as $edge) {
            $list[$edge->from->exchange] = true;
            $list[$edge->to->exchange] = true;
        }

        return array_keys($list);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label(),
            'start_asset' => $this->candidate->startAsset(),
            'start_exchange' => $this->candidate->startExchange(),
            'cycle_length' => $this->candidate->length(),
            'gross_spread_bps' => round($this->candidate->grossSpreadBps(), 4),
            'net_rate_product' => round($this->candidate->netRateProduct, 12),
            'start_amount' => round($this->liquidity->startAmount, 12),
            'end_amount' => round($this->liquidity->endAmount, 12),
            'gross_profit' => round($this->profitability->grossProfit, 12),
            'net_profit' => round($this->profitability->netProfit, 12),
            'net_margin' => round($this->profitability->netMargin(), 10),
            'total_costs' => round($this->profitability->totalCosts(), 12),
            'total_fees' => round($this->profitability->totalFeesInStart, 12),
            'latency_penalty' => round($this->profitability->latencyPenalty, 12),
            'fixed_cost' => round($this->profitability->fixedCost, 12),
            'partial_fill' => $this->liquidity->partial,
            'legs' => array_map(static fn ($leg) => $leg->toArray(), $this->liquidity->legs),
            'exchanges' => $this->exchanges(),
            'detected_at_ms' => $this->candidate->detectedAtMs,
            'evaluation_latency_us' => $this->evaluationLatencyUs,
        ];
    }
}
