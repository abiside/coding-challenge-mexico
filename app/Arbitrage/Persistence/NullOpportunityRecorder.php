<?php

declare(strict_types=1);

namespace App\Arbitrage\Persistence;

use App\Arbitrage\Contracts\OpportunityRecorderInterface;
use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Execution\DTO\SimulationResult;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Recorder que descarta todo. Útil en tests y cuando la persistencia está
 * deshabilitada.
 */
final class NullOpportunityRecorder implements OpportunityRecorderInterface
{
    public function record(
        EvaluatedOpportunity $opportunity,
        RiskDecision $decision,
        ?SimulationResult $simulation = null,
    ): void {
        // no-op
    }
}
