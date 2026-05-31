<?php

declare(strict_types=1);

namespace App\Strategies\Engine\Strategies;

use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\Side;
use App\Strategies\DTO\StrategySignal;
use App\Strategies\Engine\AbstractStrategy;

/**
 * Mean Reversion Short (doc 5.4): abre short simulado cuando el precio se aleja
 * demasiado por encima de su media reciente (z-score > entry_z). Medible,
 * compacta y fácil de explicar.
 */
final class MeanReversionShortStrategy extends AbstractStrategy
{
    public function name(): string
    {
        return 'Reversión a la media (short)';
    }

    public function algorithm(): string
    {
        return 'mean_reversion_short';
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
        if ($context->zScore < $entryZ) {
            return null;
        }

        // Filtro de volumen opcional: confirma con actividad.
        $spikeMin = $this->p('volume_spike_ratio', 2.0);
        $hasVolume = $context->volumeSpike <= 0.0 || $context->volumeSpike >= $spikeMin;
        $riskFlags = [];
        if (! $hasVolume) {
            $riskFlags[] = 'low_volume_confirmation';
        }

        $reasons = [
            sprintf('z-score %.2f por encima de %.2f (sobrecompra)', $context->zScore, $entryZ),
            sprintf('volatilidad %.2f%%', $context->volatilityPct),
        ];
        if ($context->volumeSpike > 0.0) {
            $reasons[] = sprintf('volume spike %.2fx', $context->volumeSpike);
        }

        $confidence = min(1.0, abs($context->zScore) / 4.0);
        if (! $hasVolume) {
            $confidence *= 0.7;
        }

        return $this->signal($context, Side::Short, $confidence, $reasons, $riskFlags);
    }
}
