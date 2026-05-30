<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Arbitrage\Optimization\StrategyBounds;
use App\Http\Controllers\Controller;
use App\Models\ArbitrageSetting;
use App\Models\ArbitrageStrategy;
use App\Models\BotEvent;
use App\Models\StrategyEvaluation;
use App\Models\Trade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visibilidad y control manual sobre el autopilot:
 *  - Listar champion + challengers + scores y P&L acumulado por estrategia.
 *  - Inspeccionar el "log de estrategias" (strategy_evaluations) por ventana.
 *  - Promover manualmente un challenger (override del gating automático).
 *  - Ver últimos bot_events de tipo autopilot.* (razonamientos del agente).
 */
class StrategyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $strategies = ArbitrageStrategy::where('user_id', $userId)
            ->whereIn('status', [
                ArbitrageStrategy::STATUS_CHAMPION,
                ArbitrageStrategy::STATUS_CHALLENGER,
                ArbitrageStrategy::STATUS_ARCHIVED,
            ])
            ->latest('id')
            ->limit(50)
            ->get();

        $strategyIds = $strategies->pluck('id')->all();

        $pnlByStrategy = Trade::query()
            ->where('user_id', $userId)
            ->whereIn('strategy_id', $strategyIds)
            ->selectRaw('strategy_id, SUM(realized_pnl) AS total_pnl, COUNT(*) AS executions')
            ->groupBy('strategy_id')
            ->get()
            ->keyBy('strategy_id');

        $data = $strategies->map(static function (ArbitrageStrategy $s) use ($pnlByStrategy): array {
            $stats = $pnlByStrategy[$s->id] ?? null;

            return [
                'id' => $s->id,
                'name' => $s->name,
                'status' => $s->status,
                'origin' => $s->origin,
                'parent_id' => $s->parent_id,
                'generation' => $s->generation,
                'score' => round((float) $s->score, 6),
                'params' => StrategyBounds::extract((array) $s->config),
                'config_hash' => $s->config_hash,
                'rationale' => $s->rationale,
                'realized_pnl_total' => $stats ? round((float) $stats->total_pnl, 6) : 0.0,
                'executions_total' => $stats ? (int) $stats->executions : 0,
                'promoted_at' => $s->promoted_at,
                'archived_at' => $s->archived_at,
                'created_at' => $s->created_at,
            ];
        })->values();

        $events = BotEvent::query()
            ->where('user_id', $userId)
            ->where('type', 'like', 'autopilot.%')
            ->latest('id')
            ->limit(20)
            ->get(['id', 'type', 'level', 'strategy_id', 'payload', 'created_at']);

        return response()->json([
            'data' => $data,
            'events' => $events,
            'bounds' => StrategyBounds::ranges(),
        ]);
    }

    /**
     * Series de P&L acumulado por estrategia sobre un eje de tiempo compartido,
     * para graficar champion (config base) vs challengers (variantes optimizadas)
     * en una sola gráfica y comparar su comportamiento. La fuente es
     * strategy_evaluations (única tabla con P&L por estrategia, incluidos los
     * challengers shadow que no escriben trades reales).
     */
    public function series(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $strategies = ArbitrageStrategy::where('user_id', $userId)
            ->whereIn('status', [
                ArbitrageStrategy::STATUS_CHAMPION,
                ArbitrageStrategy::STATUS_CHALLENGER,
            ])
            ->get();

        if ($strategies->isEmpty()) {
            return response()->json(['axis' => [], 'series' => []]);
        }

        $ids = $strategies->pluck('id')->all();

        $evals = StrategyEvaluation::whereIn('strategy_id', $ids)
            ->where('user_id', $userId)
            ->orderBy('window_end_ms')
            ->get(['strategy_id', 'window_end_ms', 'realized_pnl']);

        // Comparativa justa: rebasamos el acumulado al instante en que el primer
        // challenger empezó a operar. Antes de ese punto el champion ya llevaba
        // decenas de ventanas, así que arrastrar ese histórico distorsionaba la
        // comparación. Tomamos como origen la ventana más temprana de cualquier
        // challenger y descartamos todo lo previo; todas las series parten de 0.
        $challengerIds = $strategies
            ->where('status', ArbitrageStrategy::STATUS_CHALLENGER)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $startMs = null;
        if ($challengerIds !== []) {
            $startMs = $evals
                ->whereIn('strategy_id', $challengerIds)
                ->min('window_end_ms');
        }
        if ($startMs !== null) {
            $startMs = (int) $startMs;
            $evals = $evals->filter(static fn ($e): bool => (int) $e->window_end_ms >= $startMs)->values();
        }

        // Eje compartido: timestamps únicos de ventana, acotado a los más recientes.
        $maxPoints = 160;
        $axis = $evals->pluck('window_end_ms')->unique()->sort()->values();
        if ($axis->count() > $maxPoints) {
            $axis = $axis->slice($axis->count() - $maxPoints)->values();
        }
        $axisArr = array_map('intval', $axis->all());

        // P&L por (estrategia, ventana).
        $pnl = [];
        foreach ($evals as $e) {
            $pnl[(int) $e->strategy_id][(int) $e->window_end_ms]
                = ($pnl[(int) $e->strategy_id][(int) $e->window_end_ms] ?? 0.0) + (float) $e->realized_pnl;
        }

        // Champion primero para que la leyenda y el color base sean estables.
        $sorted = $strategies->sortBy(static fn (ArbitrageStrategy $s): int => $s->isChampion() ? 0 : 1)->values();

        $series = [];
        foreach ($sorted as $s) {
            $cum = 0.0;
            $points = [];
            foreach ($axisArr as $t) {
                if (isset($pnl[(int) $s->id][$t])) {
                    $cum += $pnl[(int) $s->id][$t];
                }
                $points[] = round($cum, 4);
            }

            $series[] = [
                'id' => (int) $s->id,
                'name' => $s->name,
                'status' => $s->status,
                'generation' => (int) $s->generation,
                'score' => round((float) $s->score, 4),
                'final' => $points === [] ? 0.0 : end($points),
                'points' => $points,
            ];
        }

        // Marcadores de promoción: momento en que un challenger entró en
        // operación como nuevo champion. created_at y window_end_ms comparten
        // base wall-clock (ms), así que el marcador cae sobre el eje. Solo
        // incluimos los que están dentro del rango visible del eje.
        $markers = [];
        if ($axisArr !== []) {
            $axisMin = $axisArr[0];
            $axisMax = $axisArr[count($axisArr) - 1];

            // Margen de gracia: la promoción que ORIGINA la cohorte actual ocurre
            // unos segundos antes de la primera ventana de los challengers nuevos
            // (axisMin). Sin margen quedaría fuera de rango y no se vería; la
            // incluimos y msToX la fija en el borde izquierdo.
            $graceMs = 180_000;

            $promotions = BotEvent::where('user_id', $userId)
                ->where('type', 'autopilot.promotion')
                ->orderBy('created_at')
                ->get(['strategy_id', 'created_at', 'payload']);

            foreach ($promotions as $promotion) {
                $ms = (int) ($promotion->created_at?->getTimestampMs() ?? 0);
                if ($ms < ($axisMin - $graceMs) || $ms > $axisMax) {
                    continue;
                }
                $source = is_array($promotion->payload) ? ($promotion->payload['source'] ?? null) : null;
                $markers[] = [
                    'ms' => $ms,
                    'strategy_id' => $promotion->strategy_id !== null ? (int) $promotion->strategy_id : null,
                    'label' => 'Nuevo champion',
                    'source' => $source,
                ];
            }
        }

        return response()->json([
            'axis' => $axisArr,
            'series' => $series,
            'start_ms' => $startMs,
            'markers' => $markers,
        ]);
    }

    /**
     * Momentos en que un challenger entró en operación como nuevo champion
     * (promoción automática del juez o manual del usuario). Lo consume la
     * gráfica principal del dashboard para dibujar líneas punteadas verticales
     * sobre el eje temporal de P&L acumulado.
     */
    public function promotions(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $events = BotEvent::query()
            ->where('user_id', $userId)
            ->whereIn('type', ['autopilot.promotion', 'autopilot.promotion.manual'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['strategy_id', 'type', 'payload', 'created_at']);

        $data = $events->map(static function (BotEvent $event): array {
            $source = is_array($event->payload) ? ($event->payload['source'] ?? null) : null;

            return [
                'ms' => (int) ($event->created_at?->getTimestampMs() ?? 0),
                'strategy_id' => $event->strategy_id !== null ? (int) $event->strategy_id : null,
                'manual' => $event->type === 'autopilot.promotion.manual',
                'source' => $source,
                'label' => 'Nuevo champion',
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function evaluations(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $strategy = ArbitrageStrategy::where('user_id', $userId)->findOrFail($id);

        $evaluations = StrategyEvaluation::where('strategy_id', $strategy->id)
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $evaluations,
            'strategy' => [
                'id' => $strategy->id,
                'name' => $strategy->name,
                'status' => $strategy->status,
                'score' => round((float) $strategy->score, 6),
            ],
        ]);
    }

    public function promote(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $challenger = ArbitrageStrategy::where('user_id', $userId)
            ->where('status', ArbitrageStrategy::STATUS_CHALLENGER)
            ->findOrFail($id);

        $setting = ArbitrageSetting::where('user_id', $userId)->firstOrFail();
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
            'type' => 'autopilot.promotion.manual',
            'level' => 'info',
            'payload' => ['challenger_id' => $challenger->id, 'origin' => 'user'],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Promoción aplicada. El champion se reconciliará en segundos.']);
    }

    public function autopilot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'objective' => ['sometimes', 'string', 'in:net_pnl,volume,risk_adjusted'],
            'max_challengers' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'auto_promote' => ['sometimes', 'boolean'],
            'interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
        ]);

        $setting = ArbitrageSetting::firstOrCreate(
            ['user_id' => (int) $request->user()->id],
            ['symbols' => config('arbitrage.symbols', ['BTC/USDT'])],
        );

        $setting->autopilot_enabled = (bool) $data['enabled'];
        if (isset($data['objective'])) {
            $setting->optimization_objective = (string) $data['objective'];
        }
        if (isset($data['max_challengers'])) {
            $setting->autopilot_max_challengers = (int) $data['max_challengers'];
        }
        if (array_key_exists('auto_promote', $data)) {
            $setting->autopilot_auto_promote = (bool) $data['auto_promote'];
        }
        if (isset($data['interval_minutes'])) {
            $setting->autopilot_interval_minutes = (int) $data['interval_minutes'];
        }
        $setting->save();

        return response()->json(['data' => $setting->fresh()]);
    }
}
