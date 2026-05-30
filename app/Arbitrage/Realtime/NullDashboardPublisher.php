<?php

declare(strict_types=1);

namespace App\Arbitrage\Realtime;

use App\Arbitrage\Contracts\DashboardPublisherInterface;
use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Execution\DTO\SimulationResult;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Publisher que no emite nada. Útil en tests o cuando el dashboard está
 * deshabilitado.
 */
final class NullDashboardPublisher implements DashboardPublisherInterface
{
    public function publishDecision(
        EvaluatedOpportunity $opportunity,
        RiskDecision $decision,
        ?SimulationResult $simulation = null,
    ): void {
        // no-op
    }
}
