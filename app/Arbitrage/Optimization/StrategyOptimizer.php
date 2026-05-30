<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

use App\Models\ArbitrageSetting;
use App\Models\ArbitrageStrategy;
use App\Models\StrategyEvaluation;
use Illuminate\Support\Collection;

/**
 * Optimizador in-process tipo "champion-challenger con perturbación".
 *
 * - Brazos (arms) = estrategias vivas. El score de cada brazo es el promedio
 *   ponderado de P&L de sus N evaluaciones más recientes.
 * - Exploración: perturba alrededor del mejor brazo dentro de StrategyBounds.
 * - Pool acotado: el optimizador retira al peor cuando hay que crear espacio.
 * - Warm-start: si una propuesta replica un config ya existente lo deduplica
 *   (por config_hash), heredando su histórico de evaluaciones.
 */
final class StrategyOptimizer
{
    public function __construct(
        // Mínimo de evaluaciones para considerar un brazo "calificado".
        private readonly int $minEvaluations = 3,
        // Mejora mínima del challenger sobre champion (P&L absoluto) para promover.
        private readonly float $promotionEdge = 0.5,
        // Tras una promoción, espera estos segundos para volver a promover.
        private readonly int $promotionCooldownSeconds = 600,
    ) {}

    /**
     * Devuelve la decisión completa de optimización para un usuario.
     *
     * @param  array<string, mixed>  $baseConfig  config('arbitrage')
     */
    public function plan(int $userId, ArbitrageSetting $setting, array $baseConfig): OptimizationPlan
    {
        $strategies = ArbitrageStrategy::where('user_id', $userId)
            ->whereIn('status', [ArbitrageStrategy::STATUS_CHAMPION, ArbitrageStrategy::STATUS_CHALLENGER])
            ->get();

        $champion = $strategies->firstWhere('status', ArbitrageStrategy::STATUS_CHAMPION);
        if ($champion === null) {
            // El runner debe haber creado al champion vía StrategyResolver.
            return new OptimizationPlan([], null, [], 'no_champion');
        }

        $scores = $this->scoreAll($strategies);

        $proposals = $this->proposeChallengers(
            $userId,
            $setting,
            $baseConfig,
            $champion,
            $strategies,
            $scores,
        );

        $promotion = $this->pickPromotion($champion, $strategies, $scores);

        $retirements = $this->retireWorst(
            $strategies,
            $scores,
            (int) $setting->autopilot_max_challengers,
            $proposals,
        );

        return new OptimizationPlan(
            proposals: $proposals,
            promotion: $promotion,
            retirements: $retirements,
            reason: 'planned',
            scores: $scores,
        );
    }

    /**
     * @param  Collection<int, ArbitrageStrategy>  $strategies
     * @return array<int, float> strategy_id => score
     */
    private function scoreAll(Collection $strategies): array
    {
        $scores = [];
        foreach ($strategies as $strategy) {
            $scores[(int) $strategy->id] = $this->scoreFor($strategy);
        }

        return $scores;
    }

    /**
     * Score = promedio ponderado de las últimas N evaluaciones (más recientes
     * pesan más). Si no hay suficientes evaluaciones aún, devolvemos un valor
     * neutro (0.0) que no penaliza pero tampoco premia al brazo.
     */
    private function scoreFor(ArbitrageStrategy $strategy): float
    {
        $evals = StrategyEvaluation::where('strategy_id', $strategy->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($evals->count() === 0) {
            return 0.0;
        }

        $weighted = 0.0;
        $weightSum = 0.0;
        $i = 0;
        foreach ($evals as $eval) {
            $w = 1.0 / (1 + $i);
            $weighted += $w * (float) $eval->realized_pnl;
            $weightSum += $w;
            $i++;
        }

        return $weightSum > 0 ? $weighted / $weightSum : 0.0;
    }

    /**
     * Propone challengers nuevos perturbando los parámetros del mejor brazo
     * actual. Respeta el slot disponible (max_challengers).
     *
     * @param  Collection<int, ArbitrageStrategy>  $strategies
     * @param  array<int, float>  $scores
     * @param  array<string, mixed>  $baseConfig
     * @return array<int, ProposedStrategy>
     */
    private function proposeChallengers(
        int $userId,
        ArbitrageSetting $setting,
        array $baseConfig,
        ArbitrageStrategy $champion,
        Collection $strategies,
        array $scores,
    ): array {
        $existingChallengers = $strategies->where('status', ArbitrageStrategy::STATUS_CHALLENGER);
        $slots = max(0, (int) $setting->autopilot_max_challengers - $existingChallengers->count());
        if ($slots <= 0) {
            return [];
        }

        // El "líder" se elige por score; si nadie tiene historial todavía,
        // perturbamos al champion para arrancar la exploración.
        $best = $this->pickLeader($strategies, $scores) ?? $champion;
        $existingHashes = $strategies->pluck('config_hash')->all();

        return $this->buildProposals(
            $userId,
            $best,
            (array) $champion->config,
            $slots,
            $existingHashes,
            $scores[(int) $best->id] ?? 0.0,
        );
    }

    /**
     * Genera una cohorte fresca de challengers perturbando alrededor de un
     * champion dado. Se usa tras una promoción para "reiniciar" la exploración
     * alrededor del nuevo champion (los challengers anteriores ya fueron
     * archivados).
     *
     * @param  array<int, string>  $existingHashes  hashes a excluir (p. ej. el del nuevo champion)
     * @return array<int, ProposedStrategy>
     */
    public function freshProposals(
        int $userId,
        ArbitrageStrategy $champion,
        int $count,
        array $existingHashes = [],
    ): array {
        return $this->buildProposals(
            $userId,
            $champion,
            (array) $champion->config,
            $count,
            $existingHashes,
            0.0,
        );
    }

    /**
     * Perturba `$count` veces alrededor de `$leader` y devuelve propuestas
     * (clampeadas y deduplicadas por hash). El optimizador clamea SIEMPRE antes
     * de exponer la propuesta: así ningún consumidor (LLM, DB, runner) recibe
     * valores fuera de rango ni con tipos incorrectos.
     *
     * @param  array<string, mixed>  $baseChampionConfig
     * @param  array<int, string>  $existingHashes
     * @return array<int, ProposedStrategy>
     */
    private function buildProposals(
        int $userId,
        ArbitrageStrategy $leader,
        array $baseChampionConfig,
        int $count,
        array $existingHashes,
        float $leaderScore,
    ): array {
        $leaderParams = StrategyBounds::extract((array) $leader->config);
        $proposals = [];

        for ($i = 0; $i < $count; $i++) {
            $raw = $this->perturb($leaderParams, $i);
            $clampedParams = StrategyBounds::clamp($raw);
            $config = StrategyBounds::apply($baseChampionConfig, $clampedParams);
            $hash = ArbitrageStrategy::hashConfig($config);
            if (in_array($hash, $existingHashes, true)) {
                continue;
            }
            $existingHashes[] = $hash;

            $proposals[] = new ProposedStrategy(
                userId: $userId,
                name: sprintf('challenger-g%d-%d', (int) $leader->generation + 1, $i + 1),
                config: $config,
                configHash: $hash,
                parentId: (int) $leader->id,
                generation: (int) $leader->generation + 1,
                rationale: sprintf(
                    'Perturbación local sobre líder=%d (score=%.4f): %s',
                    (int) $leader->id,
                    $leaderScore,
                    self::summarizeParams($clampedParams),
                ),
                params: array_map(static fn ($v): float => (float) $v, $clampedParams),
            );
        }

        return $proposals;
    }

    /**
     * Perturbación gaussiana acotada sobre los params del líder.
     *
     * @param  array<string, float>  $params
     * @return array<string, float>
     */
    private function perturb(array $params, int $seed): array
    {
        $ranges = StrategyBounds::ranges();
        $out = [];
        foreach ($params as $key => $value) {
            if (! isset($ranges[$key])) {
                $out[$key] = $value;

                continue;
            }
            $r = $ranges[$key];
            // Exploración LOCAL alrededor del valor del líder: la sigma es
            // proporcional al valor actual (no al ancho total del rango), con un
            // piso de un "step" para que params cercanos a 0 también puedan
            // moverse. Slots posteriores exploran un poco más lejos.
            //
            // Antes el sigma era 5% del rango completo (p. ej. margen 0–100% =>
            // sigma 5%), lo que disparaba los params a valores extremos respecto
            // al punto de operación real (~0.05%) y dejaba a los challengers sin
            // ejecutar jamás. El esquema relativo los mantiene cerca del champion.
            $relSigma = 0.20 * (1 + $seed * 0.5);
            $sigma = max(abs((float) $value) * $relSigma, (float) $r['step']);
            $out[$key] = (float) $value + self::gauss() * $sigma;
        }

        return $out;
    }

    private static function gauss(): float
    {
        // Box-Muller, evita PHP rand() porque es bajo entropía.
        $u1 = max(1e-12, mt_rand() / mt_getrandmax());
        $u2 = mt_rand() / mt_getrandmax();

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /**
     * @param  Collection<int, ArbitrageStrategy>  $strategies
     * @param  array<int, float>  $scores
     */
    private function pickLeader(Collection $strategies, array $scores): ?ArbitrageStrategy
    {
        $eligible = $strategies->filter(function (ArbitrageStrategy $s) use ($scores): bool {
            return ($scores[(int) $s->id] ?? 0.0) > 0.0;
        });
        if ($eligible->isEmpty()) {
            return null;
        }

        return $eligible->sortByDesc(static fn (ArbitrageStrategy $s) => $scores[(int) $s->id] ?? 0.0)->first();
    }

    /**
     * Selecciona un challenger para promover si supera al champion con
     * suficiente muestra y respeta el cooldown.
     *
     * @param  Collection<int, ArbitrageStrategy>  $strategies
     * @param  array<int, float>  $scores
     */
    private function pickPromotion(
        ArbitrageStrategy $champion,
        Collection $strategies,
        array $scores,
    ): ?PromotionDecision {
        if ($champion->promoted_at !== null
            && $champion->promoted_at->diffInSeconds(now()) < $this->promotionCooldownSeconds) {
            return null;
        }

        $championScore = $scores[(int) $champion->id] ?? 0.0;

        $candidates = $strategies
            ->where('status', ArbitrageStrategy::STATUS_CHALLENGER)
            ->filter(function (ArbitrageStrategy $s): bool {
                return StrategyEvaluation::where('strategy_id', $s->id)->count() >= $this->minEvaluations;
            });

        if ($candidates->isEmpty()) {
            return null;
        }

        /** @var ArbitrageStrategy $best */
        $best = $candidates
            ->sortByDesc(static fn (ArbitrageStrategy $s) => $scores[(int) $s->id] ?? 0.0)
            ->first();

        $bestScore = $scores[(int) $best->id] ?? 0.0;
        if (($bestScore - $championScore) < $this->promotionEdge) {
            return null;
        }

        return new PromotionDecision(
            challenger: $best,
            championScore: $championScore,
            challengerScore: $bestScore,
            edge: $bestScore - $championScore,
        );
    }

    /**
     * @param  Collection<int, ArbitrageStrategy>  $strategies
     * @param  array<int, float>  $scores
     * @param  array<int, ProposedStrategy>  $proposals
     * @return array<int, int> ids a archivar
     */
    private function retireWorst(
        Collection $strategies,
        array $scores,
        int $maxChallengers,
        array $proposals,
    ): array {
        $challengers = $strategies->where('status', ArbitrageStrategy::STATUS_CHALLENGER);
        $totalAfter = $challengers->count() + count($proposals);
        $excess = $totalAfter - $maxChallengers;
        if ($excess <= 0) {
            return [];
        }

        $sorted = $challengers->sortBy(static fn (ArbitrageStrategy $s) => $scores[(int) $s->id] ?? 0.0);

        return $sorted->take($excess)->map(static fn (ArbitrageStrategy $s) => (int) $s->id)->values()->all();
    }

    /**
     * @param  array<string, float>  $params
     */
    private static function summarizeParams(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = sprintf('%s=%.4f', $key, $value);
        }

        return implode(', ', $parts);
    }
}
