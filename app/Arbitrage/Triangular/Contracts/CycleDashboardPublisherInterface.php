<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Contracts;

use App\Arbitrage\Risk\RiskDecision;
use App\Arbitrage\Triangular\DTO\EvaluatedCycle;
use App\Arbitrage\Triangular\Execution\DTO\CycleSimulationResult;

/**
 * Publica decisiones de ciclos triangulares al dashboard.
 */
interface CycleDashboardPublisherInterface
{
    public function publishCycleDecision(
        EvaluatedCycle $cycle,
        RiskDecision $decision,
        ?CycleSimulationResult $simulation = null,
    ): void;
}
