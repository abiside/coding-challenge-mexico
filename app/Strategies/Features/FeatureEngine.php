<?php

declare(strict_types=1);

namespace App\Strategies\Features;

use App\Arbitrage\MeanReversion\Stats\RollingPriceWindow;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Strategies\DTO\MarketContext;

/**
 * Calcula features derivadas a partir de datos de mercado (doc sección 4):
 * precio, returns multi-ventana, estadística (z-score, volatilidad), order book
 * (spread, profundidad, imbalance, slippage estimado) y volumen. No decide
 * nada: solo construye el `MarketContext` que consumen estrategias y riesgo.
 */
final class FeatureEngine
{
    public function __construct(
        private readonly string $exchange,
        // Tamaño objetivo (USDT) para estimar slippage caminando el libro.
        private readonly float $targetSizeUsdt = 200.0,
    ) {
    }

    public function build(
        OrderBookSnapshot $snapshot,
        RollingPriceWindow $window,
        VolumeTracker $volume,
        int $nowMs,
    ): ?MarketContext {
        [$bestBid, $bidDepthUsdt] = $this->bidSide($snapshot);
        [$bestAsk, $askDepthUsdt] = $this->askSide($snapshot);

        if ($bestBid <= 0.0 || $bestAsk <= 0.0) {
            return null;
        }

        $mid = ($bestBid + $bestAsk) / 2.0;
        $spreadAbs = $bestAsk - $bestBid;
        $spreadPct = $mid > 0.0 ? ($spreadAbs / $mid) * 100.0 : 0.0;

        $totalDepth = $bidDepthUsdt + $askDepthUsdt;
        $imbalance = $totalDepth > 0.0 ? $bidDepthUsdt / $totalDepth : 0.5;

        $slippage = $this->estimateSlippagePct($snapshot, $bestAsk);

        $high60 = $window->highLow($nowMs, 60_000);
        $highWin = $window->highLow($nowMs, PHP_INT_MAX);
        $symbol = $snapshot->symbol;

        return new MarketContext(
            exchange: $this->exchange,
            symbol: $symbol,
            midPrice: $mid,
            bestBid: $bestBid,
            bestAsk: $bestAsk,
            spreadAbs: $spreadAbs,
            spreadPct: $spreadPct,
            bidDepthUsdt: $bidDepthUsdt,
            askDepthUsdt: $askDepthUsdt,
            imbalance: $imbalance,
            slippageEstPct: $slippage,
            mean: $window->mean(),
            stddev: $window->stddev(),
            zScore: $window->zScore($mid),
            volatilityPct: $window->volatilityPct(),
            return30s: $window->returnPct($nowMs, 30_000),
            return1m: $window->returnPct($nowMs, 60_000),
            return3m: $window->returnPct($nowMs, 180_000),
            return5m: $window->returnPct($nowMs, 300_000),
            return15m: $window->returnPct($nowMs, 900_000),
            high60s: $high60[0] ?? null,
            low60s: $high60[1] ?? null,
            highWindow: $highWin[0] ?? null,
            lowWindow: $highWin[1] ?? null,
            volumeSpike: $volume->volumeSpike($symbol),
            tradesPerMin: $volume->tradesPerMin($symbol),
            bookAgeMs: max(0, $nowMs - $snapshot->timestampMs),
            sampleCount: $window->count(),
            coverageMs: $window->coverageMs(),
            nowMs: $nowMs,
        );
    }

    /**
     * @return array{0: float, 1: float}  [best bid, profundidad bid en USDT]
     */
    private function bidSide(OrderBookSnapshot $snapshot): array
    {
        $bestBid = 0.0;
        $depth = 0.0;
        foreach ($snapshot->bids as $level) {
            $price = (float) $level->price;
            $size = (float) $level->size;
            if ($price <= 0.0 || $size <= 0.0) {
                continue;
            }
            if ($price > $bestBid) {
                $bestBid = $price;
            }
            $depth += $price * $size;
        }

        return [$bestBid, $depth];
    }

    /**
     * @return array{0: float, 1: float}  [best ask, profundidad ask en USDT]
     */
    private function askSide(OrderBookSnapshot $snapshot): array
    {
        $bestAsk = 0.0;
        $depth = 0.0;
        foreach ($snapshot->asks as $level) {
            $price = (float) $level->price;
            $size = (float) $level->size;
            if ($price <= 0.0 || $size <= 0.0) {
                continue;
            }
            if ($bestAsk === 0.0 || $price < $bestAsk) {
                $bestAsk = $price;
            }
            $depth += $price * $size;
        }

        return [$bestAsk, $depth];
    }

    /**
     * Camina el lado ask del libro para llenar `targetSizeUsdt` y devuelve el
     * slippage % entre el precio promedio de llenado y el best ask. Si la
     * liquidez visible no alcanza, devuelve el slippage hasta donde llegó.
     */
    private function estimateSlippagePct(OrderBookSnapshot $snapshot, float $bestAsk): float
    {
        if ($bestAsk <= 0.0) {
            return 0.0;
        }

        $levels = [];
        foreach ($snapshot->asks as $level) {
            $price = (float) $level->price;
            $size = (float) $level->size;
            if ($price > 0.0 && $size > 0.0) {
                $levels[] = [$price, $size];
            }
        }
        usort($levels, static fn ($a, $b) => $a[0] <=> $b[0]);

        $remaining = $this->targetSizeUsdt;
        $spent = 0.0;
        $filledBase = 0.0;
        foreach ($levels as [$price, $size]) {
            $levelUsdt = $price * $size;
            $take = min($remaining, $levelUsdt);
            $spent += $take;
            $filledBase += $take / $price;
            $remaining -= $take;
            if ($remaining <= 0.0) {
                break;
            }
        }

        if ($filledBase <= 0.0) {
            return 0.0;
        }

        $avgFill = $spent / $filledBase;

        return (($avgFill - $bestAsk) / $bestAsk) * 100.0;
    }
}
