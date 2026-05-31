<?php

declare(strict_types=1);

namespace App\Strategies\Risk;

use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\Side;
use App\Strategies\DTO\StrategySignal;

/**
 * Toda señal pasa por aquí antes de convertirse en posición simulada (doc
 * sección 7). Aplica filtros deterministas de calidad y riesgo. El circuit
 * breaker se evalúa aparte (a nivel de cartera) y corta nuevas entradas.
 */
final class RiskManager
{
    public function __construct(
        private readonly float $minConfidence = 0.55,
        private readonly float $maxSpreadPct = 0.15,
        private readonly float $minLiquidityUsdt = 2000.0,
        private readonly int $maxBookAgeMs = 5000,
        private readonly int $maxOpenPositions = 10,
        private readonly float $feeRate = 0.001,
    ) {
    }

    public function assess(StrategySignal $signal, MarketContext $ctx, PortfolioState $portfolio): RiskAssessment
    {
        $flags = $signal->riskFlags;

        // Stop-loss obligatorio.
        if ($signal->stopLoss <= 0.0) {
            return RiskAssessment::reject('no_stop_loss', $flags);
        }

        // Confidence score mínimo.
        if ($signal->confidenceScore < $this->minConfidence) {
            return RiskAssessment::reject(sprintf('low_confidence %.2f < %.2f', $signal->confidenceScore, $this->minConfidence), $flags);
        }

        // Spread demasiado alto.
        if ($ctx->spreadPct > $this->maxSpreadPct) {
            return RiskAssessment::reject(sprintf('spread_too_high %.3f%% > %.3f%%', $ctx->spreadPct, $this->maxSpreadPct), $flags);
        }

        // Liquidez insuficiente (profundidad del lado relevante).
        $depth = $signal->side === Side::Long ? $ctx->askDepthUsdt : $ctx->bidDepthUsdt;
        if ($depth < $this->minLiquidityUsdt) {
            return RiskAssessment::reject(sprintf('low_liquidity %.0f < %.0f USDT', $depth, $this->minLiquidityUsdt), $flags);
        }

        // Book stale / latencia.
        if ($ctx->bookAgeMs > $this->maxBookAgeMs) {
            return RiskAssessment::reject(sprintf('stale_book %dms', $ctx->bookAgeMs), $flags);
        }

        // Demasiadas posiciones abiertas (si no hay ya una de este símbolo).
        if (! $portfolio->hasPositionForSymbol && $portfolio->openPositions >= $this->maxOpenPositions) {
            return RiskAssessment::reject('max_open_positions', $flags);
        }

        // Profit esperado debe superar costos (fees ida+vuelta + slippage).
        $tpPct = abs($signal->takeProfit - $signal->entryPrice) / max(1e-12, $signal->entryPrice) * 100.0;
        $costPct = (2.0 * $this->feeRate * 100.0) + $ctx->slippageEstPct;
        if ($tpPct <= $costPct) {
            return RiskAssessment::reject(sprintf('profit_below_costs tp=%.3f%% cost=%.3f%%', $tpPct, $costPct), $flags);
        }

        return RiskAssessment::approve($flags);
    }
}
