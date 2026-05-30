<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Persistence;

use App\Arbitrage\Risk\RiskDecision;
use App\Arbitrage\Triangular\Contracts\CycleRecorderInterface;
use App\Arbitrage\Triangular\DTO\EvaluatedCycle;
use App\Arbitrage\Triangular\Execution\DTO\CycleSimulationResult;

/**
 * Recorder que descarta todo. Útil en tests y cuando persistencia desactivada.
 */
final class NullCycleRecorder implements CycleRecorderInterface
{
    public function record(
        EvaluatedCycle $cycle,
        RiskDecision $decision,
        ?CycleSimulationResult $simulation = null,
    ): void {
        // no-op
    }
}
