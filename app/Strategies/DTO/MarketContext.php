<?php

declare(strict_types=1);

namespace App\Strategies\DTO;

/**
 * Snapshot de features de mercado para un símbolo en un instante. Lo produce el
 * FeatureEngine y lo consumen las estrategias (que NO acceden a datos crudos) y
 * el Risk Manager. Incluye precio, returns multi-ventana, estadística (z-score,
 * volatilidad), order book (spread, profundidad, imbalance, slippage) y volumen.
 */
final class MarketContext
{
    /**
     * @param  float|null  $return30s  retorno % a 30s (null si sin cobertura)
     * @param  float|null  $return1m   retorno % a 1m
     * @param  float|null  $return3m   retorno % a 3m
     * @param  float|null  $return5m   retorno % a 5m
     * @param  float|null  $return15m  retorno % a 15m
     */
    public function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly float $midPrice,
        public readonly float $bestBid,
        public readonly float $bestAsk,
        public readonly float $spreadAbs,
        public readonly float $spreadPct,
        public readonly float $bidDepthUsdt,
        public readonly float $askDepthUsdt,
        public readonly float $imbalance,
        public readonly float $slippageEstPct,
        public readonly float $mean,
        public readonly float $stddev,
        public readonly float $zScore,
        public readonly float $volatilityPct,
        public readonly ?float $return30s,
        public readonly ?float $return1m,
        public readonly ?float $return3m,
        public readonly ?float $return5m,
        public readonly ?float $return15m,
        public readonly ?float $high60s,
        public readonly ?float $low60s,
        public readonly ?float $highWindow,
        public readonly ?float $lowWindow,
        public readonly float $volumeSpike,
        public readonly float $tradesPerMin,
        public readonly int $bookAgeMs,
        public readonly int $sampleCount,
        public readonly int $coverageMs,
        public readonly int $nowMs,
    ) {
    }

    public function baseAsset(): string
    {
        $parts = explode('/', $this->symbol);

        return $parts[0] ?? $this->symbol;
    }

    /** ¿Hay suficiente serie para estadística confiable (z-score, returns)? */
    public function isWarm(int $minSamples, int $minCoverageMs): bool
    {
        return $this->sampleCount >= $minSamples && $this->coverageMs >= $minCoverageMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'mid_price' => $this->midPrice,
            'spread_pct' => round($this->spreadPct, 6),
            'bid_depth' => round($this->bidDepthUsdt, 2),
            'ask_depth' => round($this->askDepthUsdt, 2),
            'imbalance' => round($this->imbalance, 4),
            'slippage_est_pct' => round($this->slippageEstPct, 6),
            'z_score' => round($this->zScore, 4),
            'volatility_pct' => round($this->volatilityPct, 4),
            'return_1m' => $this->return1m !== null ? round($this->return1m, 4) : null,
            'return_5m' => $this->return5m !== null ? round($this->return5m, 4) : null,
            'volume_spike' => round($this->volumeSpike, 4),
            'trades_per_min' => round($this->tradesPerMin, 2),
            'book_age_ms' => $this->bookAgeMs,
        ];
    }
}
