<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

/**
 * Veredicto del StrategyJudge tras evaluar champion + challengers en paralelo.
 *
 * Es la decisión de alto nivel del autopilot: a quién promover (si a alguien),
 * quién rinde mejor, quién tiene mejor promesa de crecimiento, y el rationale
 * que lo justifica. La fuente puede ser el LLM o el fallback cuantitativo.
 */
final class JudgeVerdict
{
    /**
     * @param  array<int, array<string, mixed>>  $ranking  detalle por estrategia
     */
    public function __construct(
        public readonly ?int $promoteStrategyId,
        public readonly ?int $bestPerformanceId,
        public readonly ?int $bestGrowthId,
        public readonly string $rationale,
        public readonly string $source,
        public readonly array $ranking = [],
    ) {}

    public static function noChange(string $source, string $rationale): self
    {
        return new self(
            promoteStrategyId: null,
            bestPerformanceId: null,
            bestGrowthId: null,
            rationale: $rationale,
            source: $source,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'promote_strategy_id' => $this->promoteStrategyId,
            'best_performance_id' => $this->bestPerformanceId,
            'best_growth_id' => $this->bestGrowthId,
            'rationale' => $this->rationale,
            'source' => $this->source,
            'ranking' => $this->ranking,
        ];
    }
}
