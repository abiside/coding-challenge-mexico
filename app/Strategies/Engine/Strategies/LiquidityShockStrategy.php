<?php

declare(strict_types=1);

namespace App\Strategies\Engine\Strategies;

use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\Side;
use App\Strategies\DTO\StrategySignal;
use App\Strategies\Engine\AbstractStrategy;

/**
 * Liquidity Shock (doc 5.6): detecta cambios bruscos en la profundidad del
 * order book vía imbalance = bid_depth / (bid_depth + ask_depth). Imbalance
 * alto con precio rompiendo arriba => long; imbalance bajo con precio fallando
 * => short.
 */
final class LiquidityShockStrategy extends AbstractStrategy
{
    public function name(): string
    {
        return 'Choque de liquidez';
    }

    public function algorithm(): string
    {
        return 'liquidity_shock';
    }

    public function evaluate(MarketContext $context): ?StrategySignal
    {
        $longTh = $this->p('imbalance_long', 0.65);
        $shortTh = $this->p('imbalance_short', 0.35);

        // Long: presión compradora dominante + precio rompiendo arriba.
        if ($context->imbalance >= $longTh
            && $context->return1m !== null && $context->return1m > 0.0
            && $context->high60s !== null && $context->midPrice >= $context->high60s) {
            $reasons = [
                sprintf('imbalance %.2f ≥ %.2f (bid domina)', $context->imbalance, $longTh),
                sprintf('precio rompiendo arriba (retorno 1m +%.2f%%)', $context->return1m),
            ];
            $confidence = min(1.0, ($context->imbalance - 0.5) * 2.0);

            return $this->signal($context, Side::Long, $confidence, $reasons, ['liquidity_shift']);
        }

        // Short: presión vendedora dominante + precio fallando máximos.
        if ($context->imbalance <= $shortTh
            && $context->high60s !== null && $context->midPrice < $context->high60s) {
            $reasons = [
                sprintf('imbalance %.2f ≤ %.2f (ask domina)', $context->imbalance, $shortTh),
                'precio fallando máximos recientes',
            ];
            $confidence = min(1.0, (0.5 - $context->imbalance) * 2.0);

            return $this->signal($context, Side::Short, $confidence, $reasons, ['liquidity_shift']);
        }

        return null;
    }
}
