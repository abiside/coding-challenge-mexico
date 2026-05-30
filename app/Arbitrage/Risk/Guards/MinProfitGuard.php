<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk\Guards;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Rechaza oportunidades cuyo profit neto o margen neto no superan los umbrales
 * mínimos configurados.
 */
final class MinProfitGuard implements Guard
{
    public function __construct(
        private readonly float $minNetProfit,
        private readonly float $minNetMargin,
    ) {
    }

    public function evaluate(EvaluatedOpportunity $opportunity, int $nowMs): ?RiskDecision
    {
        $profit = $opportunity->profitability;

        if ($profit->netProfit < $this->minNetProfit) {
            return RiskDecision::reject(sprintf(
                'low_net_profit: net=%.8f min=%.8f',
                $profit->netProfit,
                $this->minNetProfit,
            ));
        }

        if ($profit->netMargin() < $this->minNetMargin) {
            return RiskDecision::reject(sprintf(
                'low_net_margin: margin=%.8f min=%.8f',
                $profit->netMargin(),
                $this->minNetMargin,
            ));
        }

        return null;
    }
}
