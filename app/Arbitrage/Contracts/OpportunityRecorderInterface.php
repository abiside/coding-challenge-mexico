<?php

declare(strict_types=1);

namespace App\Arbitrage\Contracts;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Execution\DTO\SimulationResult;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Registra eventos relevantes del engine de forma desacoplada del camino
 * crítico (buffer/batch/jobs). No debe bloquear la evaluación.
 */
interface OpportunityRecorderInterface
{
    public function record(
        EvaluatedOpportunity $opportunity,
        RiskDecision $decision,
        ?SimulationResult $simulation = null,
    ): void;
}
