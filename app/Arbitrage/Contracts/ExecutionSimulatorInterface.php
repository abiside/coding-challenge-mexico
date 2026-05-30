<?php

declare(strict_types=1);

namespace App\Arbitrage\Contracts;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Execution\DTO\SimulationResult;

/**
 * Único componente autorizado a modificar balances simulados (single-writer).
 * Recibe una oportunidad ya evaluada y aprobada por riesgo, simula ambas patas
 * y devuelve el resultado con P&L.
 */
interface ExecutionSimulatorInterface
{
    public function simulate(EvaluatedOpportunity $opportunity, string $idempotencyKey): SimulationResult;
}
