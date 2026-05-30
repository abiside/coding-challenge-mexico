<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\DTO;

use App\Arbitrage\Risk\RiskDecision;
use App\Arbitrage\Triangular\Execution\DTO\CycleSimulationResult;

/**
 * Resultado de procesar un ciclo de principio a fin: evaluación, decisión y
 * (si aplica) simulación de ejecución.
 */
final class ProcessedCycle
{
    public function __construct(
        public readonly EvaluatedCycle $cycle,
        public readonly RiskDecision $decision,
        public readonly ?CycleSimulationResult $simulation = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision->decision->value,
            'reasons' => $this->decision->reasons,
            'cycle' => $this->cycle->toArray(),
            'simulation' => $this->simulation?->toArray(),
        ];
    }
}
