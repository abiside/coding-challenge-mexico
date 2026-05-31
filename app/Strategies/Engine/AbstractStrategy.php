<?php

declare(strict_types=1);

namespace App\Strategies\Engine;

use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\Side;
use App\Strategies\DTO\StrategySignal;

/**
 * Base de las estrategias: guarda parámetros y centraliza la construcción de
 * señales (cálculo de take-profit / stop-loss a partir de porcentajes según el
 * lado, y armado del DTO con razones y banderas de riesgo).
 */
abstract class AbstractStrategy implements TradingStrategy
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        protected readonly array $params = [],
    ) {
    }

    protected function p(string $key, float $default): float
    {
        return (float) ($this->params[$key] ?? $default);
    }

    protected function pi(string $key, int $default): int
    {
        return (int) ($this->params[$key] ?? $default);
    }

    /**
     * Construye la señal con TP/SL derivados del precio de entrada y el lado.
     * Para long: TP por encima, SL por debajo. Para short: al revés.
     *
     * @param  array<int, string>  $reasons
     * @param  array<int, string>  $riskFlags
     */
    protected function signal(
        MarketContext $ctx,
        Side $side,
        float $confidence,
        array $reasons,
        array $riskFlags = [],
    ): StrategySignal {
        $entry = $ctx->midPrice;
        $tpPct = $this->p('take_profit_pct', 1.5) / 100.0;
        $slPct = $this->p('stop_loss_pct', 2.0) / 100.0;

        if ($side === Side::Long) {
            $takeProfit = $entry * (1.0 + $tpPct);
            $stopLoss = $entry * (1.0 - $slPct);
        } else {
            $takeProfit = $entry * (1.0 - $tpPct);
            $stopLoss = $entry * (1.0 + $slPct);
        }

        return new StrategySignal(
            strategyName: $this->name(),
            algorithm: $this->algorithm(),
            exchange: $ctx->exchange,
            symbol: $ctx->symbol,
            side: $side,
            confidenceScore: max(0.0, min(1.0, $confidence)),
            entryPrice: $entry,
            takeProfit: $takeProfit,
            stopLoss: $stopLoss,
            maxHoldingSeconds: $this->pi('max_holding_seconds', 1800),
            reasons: $reasons,
            riskFlags: $riskFlags,
            createdAtMs: $ctx->nowMs,
        );
    }

    /** ¿La serie tiene warmup suficiente para estadística (z-score, returns)? */
    protected function isWarm(MarketContext $ctx): bool
    {
        return $ctx->isWarm($this->pi('min_samples', 60), $this->pi('min_coverage_ms', 600000));
    }
}
