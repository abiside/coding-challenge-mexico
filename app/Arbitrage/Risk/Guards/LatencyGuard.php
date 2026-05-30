<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk\Guards;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Rechaza cuando la antigüedad combinada de los books supera la latencia
 * máxima tolerada.
 */
final class LatencyGuard implements Guard
{
    public function __construct(private readonly int $maxCombinedAgeMs)
    {
    }

    public function evaluate(EvaluatedOpportunity $opportunity, int $nowMs): ?RiskDecision
    {
        $combined = $opportunity->combinedAgeMs($nowMs);

        if ($combined > $this->maxCombinedAgeMs) {
            return RiskDecision::reject(sprintf(
                'high_latency: combined_age=%dms max=%dms',
                $combined,
                $this->maxCombinedAgeMs,
            ));
        }

        return null;
    }
}
