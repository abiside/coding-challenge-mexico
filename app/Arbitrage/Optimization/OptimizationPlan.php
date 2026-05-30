<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

/**
 * Decisión completa del optimizador para un ciclo: qué challengers crear,
 * cuál (si alguno) promover, y cuáles archivar para liberar slots.
 */
final class OptimizationPlan
{
    /**
     * @param  array<int, ProposedStrategy>  $proposals
     * @param  array<int, int>  $retirements  ids de estrategias a archivar
     * @param  array<int, float>  $scores  strategy_id => score
     */
    public function __construct(
        public readonly array $proposals,
        public readonly ?PromotionDecision $promotion,
        public readonly array $retirements,
        public readonly string $reason,
        public readonly array $scores = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->proposals === [] && $this->promotion === null && $this->retirements === [];
    }
}
