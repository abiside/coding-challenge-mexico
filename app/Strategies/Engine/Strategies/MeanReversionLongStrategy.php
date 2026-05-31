<?php

declare(strict_types=1);

namespace App\Strategies\Engine\Strategies;

use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\Side;
use App\Strategies\DTO\StrategySignal;
use App\Strategies\Engine\AbstractStrategy;

/**
 * Mean Reversion Long (doc 5.2): compra tras una caída excesiva esperando un
 * rebote. Entra cuando el z-score cae por debajo de -entry_z con volatilidad
 * suficiente. Requiere stop-loss (lo aporta la base) por el riesgo de seguir
 * cayendo.
 */
final class MeanReversionLongStrategy extends AbstractStrategy
{
    public function name(): string
    {
        return 'Reversión a la media (long)';
    }

    public function algorithm(): string
    {
        return 'mean_reversion_long';
    }

    public function evaluate(MarketContext $context): ?StrategySignal
    {
        if (! $this->isWarm($context)) {
            return null;
        }

        if ($context->volatilityPct < $this->p('min_volatility_pct', 0.3)) {
            return null;
        }

        $entryZ = $this->p('entry_z', 2.0);
        if ($context->zScore > -$entryZ) {
            return null;
        }

        $reasons = [
            sprintf('z-score %.2f por debajo de -%.2f (sobreventa)', $context->zScore, $entryZ),
            sprintf('volatilidad %.2f%%', $context->volatilityPct),
        ];
        if ($context->return5m !== null) {
            $reasons[] = sprintf('retorno 5m %.2f%%', $context->return5m);
        }

        // Cuanto más negativo el z-score, mayor confianza (saturando en 4σ).
        $confidence = min(1.0, abs($context->zScore) / 4.0);

        return $this->signal($context, Side::Long, $confidence, $reasons);
    }
}
