<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Engine;

use App\Arbitrage\MeanReversion\DTO\MeanReversionCandidate;
use App\Arbitrage\MeanReversion\DTO\Side;
use App\Arbitrage\MeanReversion\Stats\RollingPriceWindow;

/**
 * Lógica pura de señal: dado el precio actual, la ventana de 1h y la posición
 * abierta, decide si hay que comprar (bajo la media), vender (sobre la media,
 * take-profit) o cortar pérdidas (stop-loss). No hace sizing ni toca riesgo de
 * cartera; eso lo resuelve el engine.
 */
final class SignalEvaluator
{
    public function __construct(
        private readonly float $entryZ = 1.5,
        private readonly float $exitZ = 1.0,
        private readonly float $minVolatilityPct = 0.3,
        private readonly float $takeProfitPct = 1.5,
        private readonly float $stopLossPct = 3.0,
        private readonly int $minSamples = 60,
        private readonly int $minCoverageMs = 600000,
    ) {
    }

    public function evaluate(
        string $exchange,
        string $symbol,
        float $price,
        RollingPriceWindow $window,
        float $positionQty,
        float $avgCost,
        int $nowMs,
    ): ?MeanReversionCandidate {
        if ($price <= 0.0) {
            return null;
        }

        $hasPosition = $positionQty > 0.0 && $avgCost > 0.0;

        // Salidas que dependen solo del costo promedio (no requieren warmup ni
        // filtro de volatilidad): cortar pérdidas y tomar ganancias.
        if ($hasPosition) {
            if ($this->stopLossPct > 0.0 && $price <= $avgCost * (1.0 - $this->stopLossPct / 100.0)) {
                return $this->make($exchange, $symbol, Side::Sell, 'stop_loss', $price, $window, $nowMs);
            }

            if ($this->takeProfitPct > 0.0 && $price >= $avgCost * (1.0 + $this->takeProfitPct / 100.0)) {
                return $this->make($exchange, $symbol, Side::Sell, 'take_profit', $price, $window, $nowMs);
            }
        }

        // Señales por z-score: requieren ventana con warmup y volatilidad.
        if ($window->count() < $this->minSamples || $window->coverageMs() < $this->minCoverageMs) {
            return null;
        }

        if ($window->volatilityPct() < $this->minVolatilityPct) {
            return null;
        }

        $z = $window->zScore($price);

        if ($hasPosition && $z >= $this->exitZ) {
            return $this->make($exchange, $symbol, Side::Sell, 'exit_z', $price, $window, $nowMs);
        }

        if ($z <= -$this->entryZ) {
            return $this->make($exchange, $symbol, Side::Buy, 'entry_z', $price, $window, $nowMs);
        }

        return null;
    }

    private function make(
        string $exchange,
        string $symbol,
        Side $side,
        string $reason,
        float $price,
        RollingPriceWindow $window,
        int $nowMs,
    ): MeanReversionCandidate {
        return new MeanReversionCandidate(
            exchange: $exchange,
            symbol: $symbol,
            side: $side,
            reason: $reason,
            price: $price,
            mean: $window->mean(),
            stddev: $window->stddev(),
            zScore: $window->zScore($price),
            volatilityPct: $window->volatilityPct(),
            detectedAtMs: $nowMs,
        );
    }
}
