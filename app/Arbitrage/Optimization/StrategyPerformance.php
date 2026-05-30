<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

use App\Models\StrategyEvaluation;

/**
 * Resumen cuantitativo del desempeño de una estrategia sobre un periodo (el
 * conjunto de ventanas de evaluación más reciente, típicamente desde que la
 * cohorte de challengers actual empezó a operar).
 *
 * Captura no solo el P&L total sino la "promesa de crecimiento":
 *  - slope: pendiente del P&L acumulado por ventana (regresión lineal). Mide si
 *    la estrategia gana cada vez más (tendencia), no solo cuánto lleva.
 *  - maxDrawdown: peor caída pico-valle del acumulado (riesgo de la curva).
 *  - positiveRatio: fracción de ventanas con P&L > 0 (consistencia).
 *
 * Alimenta tanto el payload del StrategyJudge (LLM) como el fallback puramente
 * cuantitativo del optimizador.
 */
final class StrategyPerformance
{
    /**
     * @param  array<int, float>  $pnlWindows  P&L por ventana, en orden temporal
     */
    public function __construct(
        public readonly int $strategyId,
        public readonly int $windows,
        public readonly float $pnlSum,
        public readonly float $cumulativeFinal,
        public readonly float $slope,
        public readonly float $maxDrawdown,
        public readonly float $positiveRatio,
        public readonly int $executions,
        public readonly int $rejects,
        public readonly float $avgMargin,
        public readonly float $executedVolume,
        public readonly array $pnlWindows,
    ) {}

    /**
     * Construye el resumen desde las evaluaciones de una estrategia. Si se pasa
     * `$sinceWindowMs`, solo considera ventanas cuyo cierre sea >= a ese instante
     * (para acotar la comparación al periodo de la cohorte actual).
     */
    public static function forStrategy(int $strategyId, ?int $sinceWindowMs = null): self
    {
        $query = StrategyEvaluation::where('strategy_id', $strategyId)
            ->orderBy('window_end_ms');

        if ($sinceWindowMs !== null) {
            $query->where('window_end_ms', '>=', $sinceWindowMs);
        }

        $evals = $query->get();

        $pnl = $evals->map(static fn (StrategyEvaluation $e): float => (float) $e->realized_pnl)->all();

        return new self(
            strategyId: $strategyId,
            windows: $evals->count(),
            pnlSum: round((float) $evals->sum('realized_pnl'), 6),
            cumulativeFinal: round(array_sum($pnl), 6),
            slope: round(self::linregSlope(self::cumulative($pnl)), 6),
            maxDrawdown: round(self::maxDrawdown(self::cumulative($pnl)), 6),
            positiveRatio: $evals->count() > 0
                ? round(count(array_filter($pnl, static fn (float $v): bool => $v > 0.0)) / $evals->count(), 4)
                : 0.0,
            executions: (int) $evals->sum('executions'),
            rejects: (int) $evals->sum('rejects'),
            avgMargin: round((float) $evals->avg('avg_margin'), 8),
            executedVolume: round((float) $evals->sum('executed_volume'), 8),
            pnlWindows: array_map(static fn (float $v): float => round($v, 4), $pnl),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strategy_id' => $this->strategyId,
            'windows' => $this->windows,
            'pnl_sum' => $this->pnlSum,
            'cumulative_final' => $this->cumulativeFinal,
            'slope_per_window' => $this->slope,
            'max_drawdown' => $this->maxDrawdown,
            'positive_window_ratio' => $this->positiveRatio,
            'executions' => $this->executions,
            'rejects' => $this->rejects,
            'avg_margin' => $this->avgMargin,
            'executed_volume' => $this->executedVolume,
        ];
    }

    /**
     * @param  array<int, float>  $pnl
     * @return array<int, float> serie acumulada
     */
    private static function cumulative(array $pnl): array
    {
        $cum = [];
        $running = 0.0;
        foreach ($pnl as $v) {
            $running += $v;
            $cum[] = $running;
        }

        return $cum;
    }

    /**
     * Pendiente de la regresión lineal de la serie acumulada contra el índice de
     * ventana. Positiva = el acumulado crece (buena promesa); negativa = decrece.
     *
     * @param  array<int, float>  $series
     */
    private static function linregSlope(array $series): float
    {
        $n = count($series);
        if ($n < 2) {
            return 0.0;
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;
        foreach ($series as $i => $y) {
            $sumX += $i;
            $sumY += $y;
            $sumXY += $i * $y;
            $sumX2 += $i * $i;
        }

        $denom = ($n * $sumX2) - ($sumX * $sumX);
        if (abs($denom) < 1e-12) {
            return 0.0;
        }

        return (($n * $sumXY) - ($sumX * $sumY)) / $denom;
    }

    /**
     * Máxima caída pico-valle de la serie acumulada (valor positivo = magnitud
     * de la peor caída). 0 si la curva nunca retrocede.
     *
     * @param  array<int, float>  $series
     */
    private static function maxDrawdown(array $series): float
    {
        $peak = null;
        $maxDd = 0.0;
        foreach ($series as $v) {
            if ($peak === null || $v > $peak) {
                $peak = $v;
            }
            $dd = $peak - $v;
            if ($dd > $maxDd) {
                $maxDd = $dd;
            }
        }

        return $maxDd;
    }
}
