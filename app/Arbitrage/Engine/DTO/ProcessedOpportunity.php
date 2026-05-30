<?php

declare(strict_types=1);

namespace App\Arbitrage\Engine\DTO;

use App\Arbitrage\Execution\DTO\SimulationResult;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Resultado de procesar una oportunidad de principio a fin: evaluación,
 * decisión de riesgo y (si aplica) simulación de ejecución.
 */
final class ProcessedOpportunity
{
    public function __construct(
        public readonly EvaluatedOpportunity $opportunity,
        public readonly RiskDecision $decision,
        public readonly ?SimulationResult $simulation = null,
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
            'opportunity' => $this->opportunity->toArray(),
            'simulation' => $this->simulation?->toArray(),
        ];
    }
}
