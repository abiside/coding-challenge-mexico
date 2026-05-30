<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\DTO;

/**
 * Señal cruda detectada para un símbolo: qué lado operar y por qué motivo
 * (reversión por z-score, take-profit o stop-loss), con el contexto estadístico
 * que la originó. El sizing y el riesgo se resuelven después.
 */
final class MeanReversionCandidate
{
    public function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly Side $side,
        public readonly string $reason,
        public readonly float $price,
        public readonly float $mean,
        public readonly float $stddev,
        public readonly float $zScore,
        public readonly float $volatilityPct,
        public readonly int $detectedAtMs,
    ) {
    }

    /** Activo base del par (BTC en BTC/USDT). */
    public function baseAsset(): string
    {
        $parts = explode('/', $this->symbol);

        return $parts[0] ?? $this->symbol;
    }

    public function key(): string
    {
        return $this->exchange.'|'.$this->symbol.'|'.$this->side->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'side' => $this->side->value,
            'reason' => $this->reason,
            'price' => $this->price,
            'mean' => $this->mean,
            'stddev' => $this->stddev,
            'z_score' => round($this->zScore, 4),
            'volatility_pct' => round($this->volatilityPct, 4),
            'detected_at_ms' => $this->detectedAtMs,
        ];
    }
}
