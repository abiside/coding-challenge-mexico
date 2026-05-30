<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk\Guards;

use App\Arbitrage\Contracts\ProfitableTrade;
use App\Arbitrage\Risk\RiskDecision;

/**
 * Una regla de riesgo. Devuelve null si la operación pasa el guard, o una
 * RiskDecision (reject/ignore) si debe detenerse el pipeline.
 *
 * El guard opera sobre `ProfitableTrade` para soportar tanto oportunidades de
 * 2 patas como ciclos triangulares con la misma implementación.
 */
interface Guard
{
    public function evaluate(ProfitableTrade $opportunity, int $nowMs): ?RiskDecision;
}
