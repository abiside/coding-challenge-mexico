<?php

declare(strict_types=1);

namespace App\Strategies\Engine\Strategies;

use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\Side;
use App\Strategies\DTO\StrategySignal;
use App\Strategies\Engine\AbstractStrategy;

/**
 * Pump Exhaustion Short (doc 5.3): abre short simulado cuando una moneda sube
 * demasiado rápido y muestra señales de agotamiento (z-score alto + profundidad
 * bid cayendo). Alto riesgo: el pump puede continuar; stop-loss obligatorio.
 */
final class PumpExhaustionShortStrategy extends AbstractStrategy
{
    public function name(): string
    {
        return 'Agotamiento de pump (short)';
    }

    public function algorithm(): string
    {
        return 'pump_exhaustion_short';
    }

    public function evaluate(MarketContext $context): ?StrategySignal
    {
        $pumpReturn = $this->p('pump_return_pct', 4.0);
        if ($context->return5m === null || $context->return5m < $pumpReturn) {
            return null;
        }

        $spikeMin = $this->p('volume_spike_ratio', 2.0);
        if ($context->volumeSpike < $spikeMin) {
            return null;
        }

        if (! $this->isWarm($context) || $context->zScore < 2.5) {
            return null;
        }

        // Agotamiento: la presión compradora se debilita (imbalance < 0.5).
        $imbalanceShort = $this->p('imbalance_short', 0.35);
        $exhaustion = $context->imbalance < 0.5;
        $riskFlags = ['high_volatility'];
        if ($context->imbalance < $imbalanceShort) {
            $riskFlags[] = 'bid_depth_collapsing';
        }

        $reasons = [
            sprintf('pump: retorno 5m +%.2f%% (≥ %.1f%%)', $context->return5m, $pumpReturn),
            sprintf('z-score %.2f (sobrecompra extrema)', $context->zScore),
            sprintf('volume spike %.2fx', $context->volumeSpike),
            sprintf('imbalance %.2f (presión bid %s)', $context->imbalance, $exhaustion ? 'cediendo' : 'firme'),
        ];

        if (! $exhaustion) {
            // Sin agotamiento todavía: no anticipar el techo.
            return null;
        }

        $confidence = min(1.0, min($context->return5m / ($pumpReturn * 2.0), 1.0) * 0.5 + (0.5 - $context->imbalance) * 1.0);

        return $this->signal($context, Side::Short, $confidence, $reasons, $riskFlags);
    }
}
