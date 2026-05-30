<?php

declare(strict_types=1);

namespace App\Arbitrage\Risk;

use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Risk\Guards\Guard;

/**
 * Orquesta los guards de riesgo y el circuit breaker para emitir una decisión
 * final clara: ejecutar, rechazar o ignorar. No muta balances ni persiste.
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

    public function assess(EvaluatedOpportunity $opportunity, ?int $nowMs = null): RiskDecision
    {
        $nowMs ??= (int) (microtime(true) * 1000);

        $cbKey = CircuitBreaker::keyFor(
            $opportunity->symbol(),
            $opportunity->buyExchange(),
            $opportunity->sellExchange(),
        );

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

        return RiskDecision::execute($opportunity->liquidity->executableBaseVolume);
    }
}
