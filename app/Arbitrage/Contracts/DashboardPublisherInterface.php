<?php

declare(strict_types=1);

namespace App\Arbitrage\Contracts;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Execution\DTO\SimulationResult;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Publica estado resumido y ya procesado para el dashboard (REST/Reverb).
 * El frontend solo consume; nunca calcula arbitraje.
 */
interface DashboardPublisherInterface
{
    public function publishDecision(
        EvaluatedOpportunity $opportunity,
        RiskDecision $decision,
        ?SimulationResult $simulation = null,
    ): void;
}
