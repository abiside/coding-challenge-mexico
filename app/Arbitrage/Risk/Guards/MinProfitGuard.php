<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk\Guards;

use App\Arbitrage\Contracts\ProfitableTrade;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Rechaza operaciones cuyo profit neto o margen neto no superan los umbrales
 * mínimos configurados. Aplica igual a oportunidades de 2 patas y ciclos.
 */
final class MinProfitGuard implements Guard
{
    public function __construct(
        private readonly float $minNetProfit,
        private readonly float $minNetMargin,
    ) {
    }

    public function evaluate(ProfitableTrade $opportunity, int $nowMs): ?RiskDecision
    {
        $netProfit = $opportunity->netProfit();
        $netMargin = $opportunity->netMargin();

        if ($netProfit < $this->minNetProfit) {
            return RiskDecision::reject(sprintf(
                'low_net_profit: net=%.8f min=%.8f',
                $netProfit,
                $this->minNetProfit,
            ));
        }

        if ($netMargin < $this->minNetMargin) {
            return RiskDecision::reject(sprintf(
                'low_net_margin: margin=%.8f min=%.8f',
                $netMargin,
                $this->minNetMargin,
            ));
        }

        return null;
    }
}
