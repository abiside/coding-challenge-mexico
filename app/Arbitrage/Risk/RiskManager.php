<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk;

use App\Arbitrage\Contracts\ProfitableTrade;
use App\Arbitrage\Risk\Guards\Guard;

/**
 * Orquesta los guards de riesgo y el circuit breaker para emitir una decisión
 * final clara: ejecutar, rechazar o ignorar. No muta balances ni persiste.
 *
 * Es agnóstico al tipo de operación: opera sobre `ProfitableTrade`, por lo
 * que sirve para oportunidades de 2 patas y ciclos triangulares por igual.
 */
final class RiskManager
{
    /**
     * @param  array<int, Guard>  $guards
     */
    public function __construct(
        private readonly array $guards,
        private readonly CircuitBreaker $circuitBreaker,
    ) {
    }

    public function assess(ProfitableTrade $opportunity, ?int $nowMs = null): RiskDecision
    {
        $nowMs ??= (int) (microtime(true) * 1000);

        $cbKey = $opportunity->circuitBreakerKey();

        if ($this->circuitBreaker->isOpen($cbKey, $nowMs)) {
            return RiskDecision::ignore('circuit_breaker_open: '.$cbKey);
        }

        foreach ($this->guards as $guard) {
            $decision = $guard->evaluate($opportunity, $nowMs);
            if ($decision !== null) {
                if ($decision->decision === Decision::Reject) {
                    $this->circuitBreaker->recordFailure($cbKey, $nowMs);
                }

                return $decision;
            }
        }

        $this->circuitBreaker->recordSuccess($cbKey);

        return RiskDecision::execute($opportunity->executableVolume());
    }
}
