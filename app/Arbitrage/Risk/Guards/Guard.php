<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk\Guards;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Una regla de riesgo. Devuelve null si la oportunidad pasa el guard, o una
 * RiskDecision (reject/ignore) si debe detenerse el pipeline.
 */
interface Guard
{
    public function evaluate(EvaluatedOpportunity $opportunity, int $nowMs): ?RiskDecision;
}
