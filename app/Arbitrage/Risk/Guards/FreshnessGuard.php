<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk\Guards;

use App\Arbitrage\Contracts\ProfitableTrade;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Rechaza si alguno de los books usados está stale respecto al umbral de
 * frescura. Funciona tanto para opps de 2 patas (2 books) como ciclos
 * triangulares (N books).
 */
final class FreshnessGuard implements Guard
{
    public function __construct(private readonly int $freshnessMs)
    {
    }

    public function evaluate(ProfitableTrade $opportunity, int $nowMs): ?RiskDecision
    {
        $ages = $opportunity->bookAgesMs($nowMs);
        if ($ages === []) {
            return null;
        }
        $maxAge = max($ages);

        if ($maxAge > $this->freshnessMs) {
            return RiskDecision::reject(sprintf(
                'book_stale: max_age=%dms max=%dms',
                $maxAge,
                $this->freshnessMs,
            ));
        }

        return null;
    }
}
