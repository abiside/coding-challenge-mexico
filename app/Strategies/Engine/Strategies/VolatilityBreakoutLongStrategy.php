<?php

declare(strict_types=1);

namespace App\Strategies\Engine\Strategies;

use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\Side;
use App\Strategies\DTO\StrategySignal;
use App\Strategies\Engine\AbstractStrategy;

/**
 * Volatility Breakout Long (doc 5.1): compra cuando una moneda rompe su máximo
 * reciente con volumen fuerte. Ideal para spot long con USDT.
 */
final class VolatilityBreakoutLongStrategy extends AbstractStrategy
{
    public function name(): string
    {
        return 'Ruptura de volatilidad (long)';
    }

    public function algorithm(): string
    {
        return 'volatility_breakout_long';
    }

    public function evaluate(MarketContext $context): ?StrategySignal
    {
        // Rompe el máximo de los últimos 60s.
        if ($context->high60s === null || $context->midPrice <= $context->high60s) {
            return null;
        }

        $spikeMin = $this->p('volume_spike_ratio', 2.0);
        if ($context->volumeSpike < $spikeMin) {
            return null;
        }

        // Momentum alcista de corto plazo.
        if ($context->return1m === null || $context->return1m <= 0.0) {
            return null;
        }

        $reasons = [
            sprintf('ruptura: precio %.6f > máx 60s %.6f', $context->midPrice, $context->high60s),
            sprintf('volume spike %.2fx (≥ %.1fx)', $context->volumeSpike, $spikeMin),
            sprintf('retorno 1m +%.2f%%', $context->return1m),
        ];

        $confidence = min(1.0, ($context->volumeSpike / ($spikeMin * 2.0)) * 0.6 + min($context->return1m / 2.0, 1.0) * 0.4);

        return $this->signal($context, Side::Long, $confidence, $reasons);
    }
}
