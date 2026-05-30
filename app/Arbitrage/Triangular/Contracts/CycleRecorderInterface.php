<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Contracts;

use App\Arbitrage\Risk\RiskDecision;
use App\Arbitrage\Triangular\DTO\EvaluatedCycle;
use App\Arbitrage\Triangular\Execution\DTO\CycleSimulationResult;

/**
 * Registra ciclos triangulares procesados (decisión + simulación) de forma
 * desacoplada del camino crítico (buffer/batch).
 */
interface CycleRecorderInterface
{
    public function record(
        EvaluatedCycle $cycle,
        RiskDecision $decision,
        ?CycleSimulationResult $simulation = null,
    ): void;
}
