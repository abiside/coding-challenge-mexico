<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Realtime;

use App\Arbitrage\Risk\RiskDecision;
use App\Arbitrage\Triangular\Contracts\CycleDashboardPublisherInterface;
use App\Arbitrage\Triangular\DTO\EvaluatedCycle;
use App\Arbitrage\Triangular\Execution\DTO\CycleSimulationResult;

/**
 * Publisher que no emite nada. Útil en tests y cuando el dashboard de ciclos
 * está desactivado.
 */
final class NullCycleDashboardPublisher implements CycleDashboardPublisherInterface
{
    public function publishCycleDecision(
        EvaluatedCycle $cycle,
        RiskDecision $decision,
        ?CycleSimulationResult $simulation = null,
    ): void {
        // no-op
    }
}
