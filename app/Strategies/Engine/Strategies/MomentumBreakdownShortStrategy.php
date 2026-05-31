<?php

declare(strict_types=1);

namespace App\Strategies\Engine\Strategies;

use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\Side;
use App\Strategies\DTO\StrategySignal;
use App\Strategies\Engine\AbstractStrategy;

/**
 * Momentum Breakdown Short (doc 5.5): abre short cuando una moneda pierde
 * estructura tras un impulso alcista (rompe el mínimo de 60s con presión
 * vendedora). Evita adivinar el techo: espera confirmación bajista.
 */
final class MomentumBreakdownShortStrategy extends AbstractStrategy
{
    public function name(): string
    {
        return 'Quiebre de momentum (short)';
    }

    public function algorithm(): string
    {
        return 'momentum_breakdown_short';
    }

    public function evaluate(MarketContext $context): ?StrategySignal
    {
        // Hubo impulso alcista previo.
        $breakout = $this->p('breakout_return_pct', 1.5);
        if ($context->return5m === null || $context->return5m < $breakout) {
            return null;
        }

        // Rompe el mínimo de los últimos 60s (estructura rota).
        if ($context->low60s === null || $context->midPrice >= $context->low60s) {
            return null;
        }

        // Presión vendedora: imbalance del lado ask.
        if ($context->imbalance >= 0.5) {
            return null;
        }

        $reasons = [
            sprintf('impulso previo: retorno 5m +%.2f%%', $context->return5m),
            sprintf('quiebre: precio %.6f < mín 60s %.6f', $context->midPrice, $context->low60s),
            sprintf('imbalance %.2f (presión vendedora)', $context->imbalance),
        ];

        $confidence = min(1.0, (0.5 - $context->imbalance) * 1.2 + 0.3);

        return $this->signal($context, Side::Short, $confidence, $reasons, ['breakdown']);
    }
}
