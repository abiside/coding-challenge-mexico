<?php

declare(strict_types=1);

namespace App\Strategies\Engine\Strategies;

use App\Strategies\DTO\MarketContext;
use App\Strategies\DTO\StrategySignal;
use App\Strategies\Engine\AbstractStrategy;
use App\Strategies\Engine\TradingStrategy;

/**
 * Statistical Opportunity Ranking (doc 5.7): no genera señales propias; ejecuta
 * el resto de estrategias y rankea sus señales con un score compuesto, devuelve
 * la mejor. Permite priorizar cuando varias disparan a la vez.
 *
 *   score = expected_profit + liquidity + volatility + momentum
 *         - spread_penalty - latency_penalty - risk_penalty
 */
final class StatisticalOpportunityRankingStrategy extends AbstractStrategy
{
    /** @var array<int, TradingStrategy> */
    private array $strategies;

    /**
     * @param  array<string, mixed>  $params
     * @param  array<int, TradingStrategy>  $strategies
     */
    public function __construct(array $params, array $strategies)
    {
        parent::__construct($params);
        $this->strategies = $strategies;
    }

    public function name(): string
    {
        return 'Ranking estadístico de oportunidades';
    }

    public function algorithm(): string
    {
        return 'statistical_ranking';
    }

    public function evaluate(MarketContext $context): ?StrategySignal
    {
        $best = null;
        $bestScore = -INF;

        foreach ($this->strategies as $strategy) {
            $signal = $strategy->evaluate($context);
            if ($signal === null) {
                continue;
            }
            $score = $this->score($signal, $context);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $signal;
            }
        }

        return $best;
    }

    private function score(StrategySignal $signal, MarketContext $ctx): float
    {
        $expectedProfit = $signal->confidenceScore * 1.0;
        $liquidity = min(1.0, ($ctx->bidDepthUsdt + $ctx->askDepthUsdt) / ($this->p('min_liquidity_usdt', 2000.0) * 4.0));
        $volatility = min(1.0, $ctx->volatilityPct / 2.0);
        $momentum = $ctx->return1m !== null ? min(1.0, abs($ctx->return1m) / 2.0) : 0.0;

        $spreadPenalty = min(1.0, $ctx->spreadPct / max(0.0001, $this->p('max_spread_pct', 0.15)));
        $latencyPenalty = min(1.0, $ctx->bookAgeMs / max(1, $this->pi('max_book_age_ms', 5000)));
        $riskPenalty = count($signal->riskFlags) * 0.15;

        return $expectedProfit + $liquidity * 0.5 + $volatility * 0.3 + $momentum * 0.3
            - $spreadPenalty * 0.5 - $latencyPenalty * 0.5 - $riskPenalty;
    }
}
