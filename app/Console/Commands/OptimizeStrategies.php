<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Arbitrage\Optimization\JudgeVerdict;
use App\Arbitrage\Optimization\OptimizationPlan;
use App\Arbitrage\Optimization\ProposedStrategy;
use App\Arbitrage\Optimization\StrategyAdvisor;
use App\Arbitrage\Optimization\StrategyJudge;
use App\Arbitrage\Optimization\StrategyOptimizer;
use App\Arbitrage\Optimization\StrategyPerformance;
use App\Models\ArbitrageSetting;
use App\Models\ArbitrageStrategy;
use App\Models\BotEvent;
use App\Models\SimulationRun;
use App\Models\StrategyEvaluation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Comando del autopilot. Se ejecuta periódicamente (ver routes/console.php) y
 * por cada usuario con `autopilot_enabled` + simulación activa:
 *
 * 1. El StrategyOptimizer propone qué challengers explorar (perturbación local).
 * 2. El StrategyJudge (LLM) evalúa al champion y a TODOS los challengers vivos
 *    EN PARALELO con sus métricas reales del periodo (P&L, tendencia/slope,
 *    consistencia, drawdown) y decide a quién promover. Sin LLM, degrada al
 *    veredicto cuantitativo del optimizador.
 * 3a. Si hay promoción: copia el config del ganador al ArbitrageSetting (dispara
 *     hot-reload del champion), REINICIA la cohorte (archiva todos los
 *     challengers) y genera una generación nueva alrededor del nuevo champion.
 * 3b. Si no hay promoción: pasa las propuestas por el StrategyAdvisor (LLM) y
 *     crea los challengers nuevos; RunArbitrageBot los levanta en su próximo
 *     reconcile y empieza otra ronda del mismo análisis.
 */
class OptimizeStrategies extends Command
{
    protected $signature = 'arbitrage:optimize
        {--user= : Solo este usuario (id)}
        {--dry-run : No persiste cambios, solo imprime el plan}';

    protected $description = 'Ciclo del autopilot: evalúa champion vs challengers (LLM), promueve y reinicia la cohorte.';

    public function handle(
        StrategyOptimizer $optimizer,
        StrategyAdvisor $advisor,
        StrategyJudge $judge,
        LoggerInterface $logger,
    ): int {
        $userIds = $this->resolveUserIds();
        if ($userIds === []) {
            $this->info('No hay usuarios con autopilot activo + simulación corriendo.');

            return self::SUCCESS;
        }

        $baseConfig = (array) config('arbitrage');
        $isDryRun = (bool) $this->option('dry-run');

        foreach ($userIds as $userId) {
            $setting = ArbitrageSetting::where('user_id', $userId)->first();
            if ($setting === null) {
                continue;
            }

            try {
                $plan = $optimizer->plan($userId, $setting, $baseConfig);
            } catch (Throwable $e) {
                $logger->error('[autopilot] optimizer falló', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $this->renderPlan($userId, $plan);

            $champion = ArbitrageStrategy::where('user_id', $userId)
                ->where('status', ArbitrageStrategy::STATUS_CHAMPION)
                ->latest('id')
                ->first();
            if ($champion === null) {
                continue;
            }

            /** @var Collection<int, ArbitrageStrategy> $challengers */
            $challengers = ArbitrageStrategy::where('user_id', $userId)
                ->where('status', ArbitrageStrategy::STATUS_CHALLENGER)
                ->get();

            // Juez LLM: evalúa champion + challengers en paralelo sobre el periodo
            // de la cohorte actual (mismas ventanas) y decide la promoción.
            $metrics = $this->collectMetrics($champion, $challengers);
            $verdict = $judge->decide($champion, $challengers, $metrics, $plan->promotion);
            $this->renderVerdict($userId, $verdict);

            if ($isDryRun) {
                continue;
            }

            // Gating de promoción por usuario: ¿auto-promoción habilitada y ha
            // pasado el periodo mínimo desde la última promoción? Si no, el juez
            // solo deja la recomendación (visible en el panel) y seguimos
            // explorando, sin lanzar al nuevo champion.
            [$canPromote, $gateReason] = $this->promotionGate($setting, $champion);
            $applied = false;

            if ($verdict->promoteStrategyId !== null && $canPromote) {
                $promoted = $challengers->firstWhere('id', $verdict->promoteStrategyId);
                if ($promoted instanceof ArbitrageStrategy) {
                    $this->applyPromotion($userId, $setting, $promoted, $verdict, $baseConfig, $optimizer, $logger);
                    $applied = true;
                }
            } elseif (! $plan->isEmpty()) {
                if ($verdict->promoteStrategyId !== null) {
                    $this->line(sprintf('[user %d] promoción recomendada (#%d) pero no aplicada: %s',
                        $userId, $verdict->promoteStrategyId, $gateReason));
                }
                $approvedProposals = $plan->proposals;
                if ($approvedProposals !== []) {
                    try {
                        $approvedProposals = $advisor->review($champion, $approvedProposals);
                    } catch (Throwable $e) {
                        $logger->warning('[autopilot] advisor falló (continuamos con plan puro)', [
                            'user_id' => $userId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                $this->applyExploration($userId, $plan, $approvedProposals);
            }

            $this->recordVerdict($userId, $verdict, $metrics, $applied, $applied ? null : $gateReason);
        }

        return self::SUCCESS;
    }

    /**
     * ¿Se puede promover automáticamente para este usuario ahora mismo?
     * Respeta el toggle de auto-promoción y el periodo mínimo configurado entre
     * promociones (medido desde la última promoción del champion vigente).
     *
     * @return array{0: bool, 1: string} [puede, motivo]
     */
    private function promotionGate(ArbitrageSetting $setting, ArbitrageStrategy $champion): array
    {
        if (! (bool) $setting->autopilot_auto_promote) {
            return [false, 'auto-promoción deshabilitada'];
        }

        $intervalMinutes = max(1, (int) $setting->autopilot_interval_minutes);
        $lastPromotedAt = $champion->promoted_at;

        if ($lastPromotedAt !== null) {
            $elapsed = $lastPromotedAt->diffInMinutes(now());
            if ($elapsed < $intervalMinutes) {
                return [false, sprintf(
                    'dentro del periodo (%d/%d min desde la última promoción)',
                    $elapsed,
                    $intervalMinutes,
                )];
            }
        }

        return [true, 'ok'];
    }

    /**
     * Métricas de periodo para el champion y cada challenger, acotadas al inicio
     * de la cohorte actual (la ventana más temprana de cualquier challenger) para
     * que la comparación sea justa: todos sobre el mismo tramo de mercado.
     *
     * @param  Collection<int, ArbitrageStrategy>  $challengers
     * @return array<int, StrategyPerformance>
     */
    private function collectMetrics(ArbitrageStrategy $champion, Collection $challengers): array
    {
        $sinceMs = null;
        if ($challengers->isNotEmpty()) {
            $min = StrategyEvaluation::whereIn('strategy_id', $challengers->pluck('id'))->min('window_end_ms');
            $sinceMs = $min !== null ? (int) $min : null;
        }

        $metrics = [];
        $metrics[(int) $champion->id] = StrategyPerformance::forStrategy((int) $champion->id, $sinceMs);
        foreach ($challengers as $challenger) {
            $metrics[(int) $challenger->id] = StrategyPerformance::forStrategy((int) $challenger->id, $sinceMs);
        }

        return $metrics;
    }

    /**
     * @return array<int, int>
     */
    private function resolveUserIds(): array
    {
        $option = $this->option('user');
        if ($option !== null && $option !== '') {
            return [(int) $option];
        }

        return ArbitrageSetting::query()
            ->where('autopilot_enabled', true)
            ->whereIn('user_id', SimulationRun::query()
                ->where('status', SimulationRun::STATUS_ACTIVE)
                ->select('user_id'))
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function renderPlan(int $userId, OptimizationPlan $plan): void
    {
        $this->line(sprintf(
            '[user %d] plan: proposals=%d promotion=%s retirements=%d (%s)',
            $userId,
            count($plan->proposals),
            $plan->promotion !== null ? (string) $plan->promotion->challenger->id : 'none',
            count($plan->retirements),
            $plan->reason,
        ));
    }

    private function renderVerdict(int $userId, JudgeVerdict $verdict): void
    {
        $this->line(sprintf(
            '[user %d] juez(%s): promover=%s mejor_rendimiento=%s mejor_promesa=%s',
            $userId,
            $verdict->source,
            $verdict->promoteStrategyId !== null ? '#'.$verdict->promoteStrategyId : 'ninguno',
            $verdict->bestPerformanceId !== null ? '#'.$verdict->bestPerformanceId : '-',
            $verdict->bestGrowthId !== null ? '#'.$verdict->bestGrowthId : '-',
        ));
        if ($verdict->rationale !== '') {
            $this->line('           '.$verdict->rationale);
        }
    }

    /**
     * Promueve al challenger ganador y REINICIA la cohorte: archiva todos los
     * challengers (incluido el promovido, cuyo config pasa a vivir en el
     * champion vía setting) y genera una generación nueva alrededor del nuevo
     * champion para repetir el análisis.
     *
     * @param  array<string, mixed>  $baseConfig
     */
    private function applyPromotion(
        int $userId,
        ArbitrageSetting $setting,
        ArbitrageStrategy $promoted,
        JudgeVerdict $verdict,
        array $baseConfig,
        StrategyOptimizer $optimizer,
        LoggerInterface $logger,
    ): void {
        $created = [];

        DB::transaction(function () use (
            $userId, $setting, $promoted, $verdict, $baseConfig, $optimizer, &$created
        ): void {
            // 1) Promueve: copia config del ganador al setting (hot-reload champion).
            $this->promoteConfig($userId, $setting, $promoted, $verdict);

            // 2) Reinicia la cohorte: archiva TODOS los challengers vivos.
            ArbitrageStrategy::where('user_id', $userId)
                ->where('status', ArbitrageStrategy::STATUS_CHALLENGER)
                ->update([
                    'status' => ArbitrageStrategy::STATUS_ARCHIVED,
                    'archived_at' => now(),
                ]);

            // 3) Regenera challengers frescos alrededor del nuevo champion.
            $newChampionHash = ArbitrageStrategy::hashConfig($setting->toEngineConfig($baseConfig));
            $fresh = $optimizer->freshProposals(
                $userId,
                $promoted,
                (int) $setting->autopilot_max_challengers,
                [$newChampionHash, (string) $promoted->config_hash],
            );

            foreach ($fresh as $proposal) {
                ArbitrageStrategy::create([
                    'user_id' => $userId,
                    'name' => $proposal->name,
                    'status' => ArbitrageStrategy::STATUS_CHALLENGER,
                    'origin' => ArbitrageStrategy::ORIGIN_AGENT,
                    'parent_id' => $proposal->parentId,
                    'generation' => $proposal->generation,
                    'config' => $proposal->config,
                    'config_hash' => $proposal->configHash,
                    'rationale' => $proposal->rationale,
                ]);
                $created[] = $proposal;
            }
        });

        $this->info(sprintf('★ promoción aplicada: challenger #%d → champion (%s)', (int) $promoted->id, $verdict->source));
        $this->info(sprintf('↻ cohorte reiniciada: %d challengers nuevos alrededor del nuevo champion', count($created)));
        foreach ($created as $proposal) {
            $this->line(sprintf('  + %s (hash=%s)', $proposal->name, substr($proposal->configHash, 0, 8)));
        }

        $logger->info('[autopilot] promoción + reinicio', [
            'user_id' => $userId,
            'promoted_id' => (int) $promoted->id,
            'source' => $verdict->source,
            'fresh_challengers' => count($created),
        ]);
    }

    /**
     * Exploración normal (sin promoción): crea los challengers propuestos y
     * archiva retirements para respetar el cupo.
     *
     * @param  array<int, ProposedStrategy>  $approvedProposals
     */
    private function applyExploration(
        int $userId,
        OptimizationPlan $plan,
        array $approvedProposals,
    ): void {
        DB::transaction(function () use ($userId, $plan, $approvedProposals): void {
            foreach ($approvedProposals as $proposal) {
                $existing = ArbitrageStrategy::where('user_id', $userId)
                    ->where('config_hash', $proposal->configHash)
                    ->first();
                if ($existing !== null) {
                    if ($existing->status === ArbitrageStrategy::STATUS_ARCHIVED) {
                        $existing->update([
                            'status' => ArbitrageStrategy::STATUS_CHALLENGER,
                            'archived_at' => null,
                            'rationale' => $proposal->rationale,
                        ]);
                    }

                    continue;
                }

                ArbitrageStrategy::create([
                    'user_id' => $userId,
                    'name' => $proposal->name,
                    'status' => ArbitrageStrategy::STATUS_CHALLENGER,
                    'origin' => ArbitrageStrategy::ORIGIN_AGENT,
                    'parent_id' => $proposal->parentId,
                    'generation' => $proposal->generation,
                    'config' => $proposal->config,
                    'config_hash' => $proposal->configHash,
                    'rationale' => $proposal->rationale,
                ]);
            }

            if ($plan->retirements !== []) {
                ArbitrageStrategy::whereIn('id', $plan->retirements)
                    ->where('user_id', $userId)
                    ->update([
                        'status' => ArbitrageStrategy::STATUS_ARCHIVED,
                        'archived_at' => now(),
                    ]);
            }
        });

        foreach ($approvedProposals as $proposal) {
            $this->info(sprintf('+ challenger creado: %s (hash=%s)', $proposal->name, substr($proposal->configHash, 0, 8)));
        }
        foreach ($plan->retirements as $id) {
            $this->info('- challenger archivado: '.$id);
        }
    }

    /**
     * Copia los thresholds del challenger ganador al ArbitrageSetting (esto
     * dispara el hot-reload del champion en RunArbitrageBot).
     */
    private function promoteConfig(
        int $userId,
        ArbitrageSetting $setting,
        ArbitrageStrategy $challenger,
        JudgeVerdict $verdict,
    ): void {
        $config = (array) $challenger->config;
        $thresholds = (array) ($config['thresholds'] ?? []);

        $setting->fill([
            'symbols' => (array) ($config['symbols'] ?? $setting->symbols),
            'min_net_profit' => (float) ($thresholds['min_net_profit'] ?? $setting->min_net_profit),
            'min_net_margin' => (float) ($thresholds['min_net_margin'] ?? $setting->min_net_margin),
            'min_base_volume' => (float) ($thresholds['min_base_volume'] ?? $setting->min_base_volume),
            'max_base_volume' => (float) ($thresholds['max_base_volume'] ?? $setting->max_base_volume),
            'freshness_ms' => (int) ($config['freshness_ms'] ?? $setting->freshness_ms),
            'latency_max_ms' => (int) ($config['latency']['max_ms'] ?? $setting->latency_max_ms),
        ]);
        $setting->save();

        BotEvent::create([
            'user_id' => $userId,
            'strategy_id' => $challenger->id,
            'type' => 'autopilot.promotion',
            'level' => 'info',
            'payload' => [
                'challenger_id' => (int) $challenger->id,
                'source' => $verdict->source,
                'rationale' => $verdict->rationale,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Persiste el veredicto del juez para trazabilidad / panel de Autopilot.
     *
     * @param  array<int, StrategyPerformance>  $metrics
     */
    private function recordVerdict(
        int $userId,
        JudgeVerdict $verdict,
        array $metrics,
        bool $applied,
        ?string $gateReason,
    ): void {
        BotEvent::create([
            'user_id' => $userId,
            'strategy_id' => $verdict->promoteStrategyId,
            'type' => 'autopilot.judge',
            'level' => 'info',
            'payload' => array_merge($verdict->toArray(), [
                'promotion_applied' => $applied,
                'gate_reason' => $gateReason,
                'metrics' => array_map(
                    static fn (StrategyPerformance $p): array => $p->toArray(),
                    $metrics,
                ),
            ]),
            'created_at' => now(),
        ]);
    }
}
