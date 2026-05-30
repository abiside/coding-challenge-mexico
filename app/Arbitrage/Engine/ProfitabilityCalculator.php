<?php

declare(strict_types=1);

namespace App\Arbitrage\Engine;

use App\Arbitrage\Engine\DTO\LiquidityResult;
use App\Arbitrage\Engine\DTO\OpportunityCandidate;
use App\Arbitrage\Engine\DTO\ProfitabilityResult;

/**
 * Calcula profit bruto y neto descontando trading fees, penalización por
 * latencia y costos fijos configurables. El slippage ya está incorporado en
 * los precios promedio ponderados que entrega LiquidityCalculator.
 */
final class ProfitabilityCalculator
{
    public function __construct(
        private readonly FeeSchedule $fees,
        private readonly float $latencyPenaltyPerMs = 0.0,
        private readonly float $fixedCost = 0.0,
    ) {
    }

    public function evaluate(
        OpportunityCandidate $candidate,
        LiquidityResult $liquidity,
        int $combinedAgeMs,
    ): ProfitabilityResult {
        $volume = $liquidity->executableBaseVolume;
        $buyNotional = $liquidity->buyNotional;
        $sellNotional = $liquidity->sellNotional;

        $grossProfit = $sellNotional - $buyNotional;

        $buyFee = $buyNotional * $this->fees->for($candidate->buyExchange());
        $sellFee = $sellNotional * $this->fees->for($candidate->sellExchange());
        $latencyPenalty = max(0, $combinedAgeMs) * $this->latencyPenaltyPerMs;
        $fixedCost = $volume > 0.0 ? $this->fixedCost : 0.0;

        $netProfit = $grossProfit - $buyFee - $sellFee - $latencyPenalty - $fixedCost;

        return new ProfitabilityResult(
            baseVolume: $volume,
            grossProfit: $grossProfit,
            buyFee: $buyFee,
            sellFee: $sellFee,
            latencyPenalty: $latencyPenalty,
            fixedCost: $fixedCost,
            netProfit: $netProfit,
            buyNotional: $buyNotional,
        );
    }
}
