<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk\Guards;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Rechaza si alguno de los books usados está stale respecto al umbral de
 * frescura.
 */
final class FreshnessGuard implements Guard
{
    public function __construct(private readonly int $freshnessMs)
    {
    }

    public function evaluate(EvaluatedOpportunity $opportunity, int $nowMs): ?RiskDecision
    {
        $buyAge = $opportunity->candidate->buyBook->ageMs($nowMs);
        $sellAge = $opportunity->candidate->sellBook->ageMs($nowMs);

        if ($buyAge > $this->freshnessMs || $sellAge > $this->freshnessMs) {
            return RiskDecision::reject(sprintf(
                'book_stale: buy_age=%dms sell_age=%dms max=%dms',
                $buyAge,
                $sellAge,
                $this->freshnessMs,
            ));
        }

        return null;
    }
}
