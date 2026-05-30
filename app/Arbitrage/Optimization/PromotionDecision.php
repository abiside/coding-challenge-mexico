<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

use App\Models\ArbitrageStrategy;

/**
 * Recomendación de promover un challenger a champion, junto con la evidencia
 * cuantitativa que la justifica (para que termine en bot_events y la UI).
 */
final class PromotionDecision
{
    public function __construct(
        public readonly ArbitrageStrategy $challenger,
        public readonly float $championScore,
        public readonly float $challengerScore,
        public readonly float $edge,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'challenger_id' => (int) $this->challenger->id,
            'champion_score' => round($this->championScore, 6),
            'challenger_score' => round($this->challengerScore, 6),
            'edge' => round($this->edge, 6),
        ];
    }
}
