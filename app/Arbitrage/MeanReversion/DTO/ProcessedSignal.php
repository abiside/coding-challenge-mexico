<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\DTO;

use App\Arbitrage\Risk\RiskDecision;

/**
 * Resultado completo de procesar una señal: candidato + decisión de riesgo +
 * (opcional) la ejecución simulada.
 */
final class ProcessedSignal
{
    public function __construct(
        public readonly MeanReversionCandidate $candidate,
        public readonly RiskDecision $decision,
        public readonly ?MeanReversionSimulationResult $simulation = null,
    ) {
    }
}
