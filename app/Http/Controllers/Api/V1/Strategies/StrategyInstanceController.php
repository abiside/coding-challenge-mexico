<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Strategies;

use App\Http\Controllers\Controller;
use App\Models\SimulatedPosition;
use App\Models\Strategy;
use App\Models\StrategySignal;
use App\Strategies\Engine\StrategyFactory;
use App\Support\StrategyCacheKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hub del módulo unificado de Estrategias. Gestiona instancias (CRUD + start/
 * stop/reset/config) de tipo trading (long/short simulado) y cross_exchange
 * (envuelve el arbitraje existente), y expone su panel (overview/señales/
 * posiciones/historial) + un consolidado de rendimiento.
 */
class StrategyInstanceController extends Controller
{
    /** Catálogo de algoritmos de trading para el wizard de creación. */
    public function catalog(): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) config('strategies.enabled', false),
            'types' => [
                ['type' => 'trading', 'name' => 'Trading (long / short simulado)', 'description' => 'Estrategias de corto plazo sobre monedas volátiles con USDT.'],
                ['type' => 'cross_exchange', 'name' => 'Arbitraje cross-exchange', 'description' => 'Detecta diferencias de precio entre exchanges (módulo existente).'],
            ],
            'algorithms' => StrategyFactory::catalog(),
            'defaults' => (array) config('strategies.defaults', []),
            'initial_usdt' => (float) (config('strategies.initial_balances.USDT') ?? 10000.0),
        ]);
    }

    /** Lista de instancias del usuario + métricas en vivo + consolidado. */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        // Garantiza una instancia cross-exchange que envuelve el arbitraje, para
        // que sus pantallas siempre estén accesibles como tabs de su dashboard.
        Strategy::firstOrCreate(
            ['user_id' => $userId, 'type' => Strategy::TYPE_CROSS_EXCHANGE],
            ['name' => 'Arbitraje cross-exchange', 'algorithm' => null, 'status' => Strategy::STATUS_STOPPED, 'enabled' => true, 'realized_pnl' => 0],
        );

        // El arbitraje cross-exchange es la estrategia principal sobre la que se
        // evalúa la app: siempre va primero; el resto, las más recientes arriba.
        $strategies = Strategy::where('user_id', $userId)
            ->orderByRaw("CASE WHEN type = ? THEN 0 ELSE 1 END", [Strategy::TYPE_CROSS_EXCHANGE])
            ->orderByDesc('id')
            ->get();

        $items = $strategies->map(function (Strategy $s) {
            $metrics = $s->isTrading() ? cache()->get(StrategyCacheKeys::metrics((int) $s->id)) : null;

            return [
                'id' => (int) $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'algorithm' => $s->algorithm,
                'status' => $s->status,
                'active' => $s->isActive(),
                'initial_usdt' => (float) $s->initial_usdt,
                'realized_pnl' => (float) $s->realized_pnl,
                'config' => $s->config,
                'running' => is_array($metrics),
                'metrics' => is_array($metrics) ? $metrics : null,
                'created_at' => $s->created_at,
            ];
        })->values();

        return response()->json([
            'enabled' => (bool) config('strategies.enabled', false),
            'data' => $items,
            'consolidated' => $this->consolidated($strategies),
            'server_time_ms' => (int) (microtime(true) * 1000),
        ]);
    }

    /** Crea una instancia de estrategia (queda detenida hasta start). */
    public function store(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in([Strategy::TYPE_TRADING, Strategy::TYPE_CROSS_EXCHANGE])],
            'algorithm' => ['nullable', 'string', 'max:48'],
            'initial_usdt' => ['nullable', 'numeric', 'min:100'],
            'config' => ['nullable', 'array'],
        ]);

        if ($data['type'] === Strategy::TYPE_TRADING) {
            $algorithm = (string) ($data['algorithm'] ?? '');
            if (! StrategyFactory::isValid($algorithm)) {
                return response()->json(['message' => 'Algoritmo de trading inválido.'], 422);
            }
        } else {
            // Una sola instancia cross-exchange por usuario (envuelve el arbitraje).
            $exists = Strategy::where('user_id', $userId)
                ->where('type', Strategy::TYPE_CROSS_EXCHANGE)
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'Ya existe una estrategia cross-exchange.'], 422);
            }
            $data['algorithm'] = null;
        }

        $strategy = Strategy::create([
            'user_id' => $userId,
            'name' => $data['name'],
            'type' => $data['type'],
            'algorithm' => $data['algorithm'] ?? null,
            'status' => Strategy::STATUS_STOPPED,
            'enabled' => true,
            'initial_usdt' => (float) ($data['initial_usdt'] ?? config('strategies.initial_balances.USDT', 10000.0)),
            'config' => $data['config'] ?? null,
            'realized_pnl' => 0,
        ]);

        return response()->json(['data' => $strategy], 201);
    }

    /** Panel de una instancia: métricas, posiciones, señales recientes y config. */
    public function overview(Request $request, int $id): JsonResponse
    {
        $strategy = $this->find($request, $id);

        $metrics = cache()->get(StrategyCacheKeys::metrics($id));
        $recent = cache()->get(StrategyCacheKeys::recentSignals($id));

        return response()->json([
            'strategy' => $strategy,
            'active' => $strategy->isActive(),
            'running' => is_array($metrics),
            'metrics' => is_array($metrics) ? $metrics : null,
            'recent_signals' => is_array($recent) ? array_values($recent) : [],
            'server_time_ms' => (int) (microtime(true) * 1000),
        ]);
    }

    /** Señales persistidas de la instancia (más recientes primero). */
    public function signals(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id);
        $limit = max(1, min(500, (int) $request->query('limit', 100)));

        $signals = StrategySignal::where('strategy_id', $id)
            ->latest('id')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $signals]);
    }

    /** Posiciones (abiertas + cerradas) de la instancia. */
    public function positions(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id);
        $limit = max(1, min(500, (int) $request->query('limit', 100)));

        $open = SimulatedPosition::where('strategy_id', $id)
            ->where('status', SimulatedPosition::STATUS_OPEN)
            ->latest('id')
            ->get();

        $closed = SimulatedPosition::where('strategy_id', $id)
            ->where('status', '!=', SimulatedPosition::STATUS_OPEN)
            ->latest('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'open' => $open,
            'closed' => $closed,
            'summary' => [
                'open_count' => $open->count(),
                'closed_count' => SimulatedPosition::where('strategy_id', $id)->where('status', '!=', SimulatedPosition::STATUS_OPEN)->count(),
                'realized_pnl' => round((float) SimulatedPosition::where('strategy_id', $id)->sum('net_pnl'), 8),
            ],
        ]);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $strategy = $this->find($request, $id);

        if (! (bool) config('strategies.enabled', false)) {
            return response()->json(['message' => 'El módulo de estrategias está deshabilitado en el servidor.'], 422);
        }

        $strategy->update([
            'status' => Strategy::STATUS_ACTIVE,
            'started_at' => now(),
            'stopped_at' => null,
        ]);

        return response()->json(['active' => true, 'data' => $strategy]);
    }

    public function stop(Request $request, int $id): JsonResponse
    {
        $strategy = $this->find($request, $id);

        $strategy->update([
            'status' => Strategy::STATUS_STOPPED,
            'stopped_at' => now(),
        ]);

        return response()->json(['active' => false, 'data' => $strategy]);
    }

    /**
     * Reinicia el ejercicio: borra posiciones y señales, restaura billetera y
     * P&L, y deja bandera para que el worker reinicie el engine en vivo.
     */
    public function reset(Request $request, int $id): JsonResponse
    {
        $strategy = $this->find($request, $id);

        $deletedPositions = SimulatedPosition::where('strategy_id', $id)->delete();
        $deletedSignals = StrategySignal::where('strategy_id', $id)->delete();

        $strategy->update([
            'wallet_snapshot' => null,
            'position_snapshot' => null,
            'realized_pnl' => 0,
        ]);

        cache()->forget(StrategyCacheKeys::metrics($id));
        cache()->forget(StrategyCacheKeys::recentSignals($id));
        cache()->put(StrategyCacheKeys::resetRequest($id), (int) (microtime(true) * 1000), 300);

        return response()->json([
            'reset' => true,
            'active' => $strategy->isActive(),
            'deleted' => ['positions' => $deletedPositions, 'signals' => $deletedSignals],
        ]);
    }

    /** Actualiza parámetros de la instancia (aplican al (re)iniciarla). */
    public function updateConfig(Request $request, int $id): JsonResponse
    {
        $strategy = $this->find($request, $id);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'initial_usdt' => ['nullable', 'numeric', 'min:100'],
            'config' => ['nullable', 'array'],
        ]);

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $strategy->name = $data['name'];
        }
        if (array_key_exists('initial_usdt', $data) && $data['initial_usdt'] !== null) {
            $strategy->initial_usdt = (float) $data['initial_usdt'];
        }
        if (array_key_exists('config', $data) && $data['config'] !== null) {
            $strategy->config = array_merge((array) $strategy->config, $data['config']);
        }
        $strategy->save();

        // Si está activa, reinicia el engine para tomar los nuevos parámetros.
        if ($strategy->isActive()) {
            cache()->put(StrategyCacheKeys::resetRequest($id), (int) (microtime(true) * 1000), 300);
        }

        return response()->json(['data' => $strategy, 'applies_live' => $strategy->isActive()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $strategy = $this->find($request, $id);
        $strategy->delete();

        return response()->json(['deleted' => true]);
    }

    private function find(Request $request, int $id): Strategy
    {
        return Strategy::where('user_id', (int) $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Consolidado de rendimiento: P&L realizado por estrategia + total y conteos.
     *
     * @param  \Illuminate\Support\Collection<int, Strategy>  $strategies
     * @return array<string, mixed>
     */
    private function consolidated($strategies): array
    {
        $byStrategy = [];
        $totalRealized = 0.0;
        $totalUnrealized = 0.0;
        $totalEquity = 0.0;
        $openPositions = 0;

        foreach ($strategies as $s) {
            $metrics = $s->isTrading() ? cache()->get(StrategyCacheKeys::metrics((int) $s->id)) : null;
            $realized = is_array($metrics) ? (float) ($metrics['realized_pnl'] ?? $s->realized_pnl) : (float) $s->realized_pnl;
            $unrealized = is_array($metrics) ? (float) ($metrics['unrealized_pnl'] ?? 0.0) : 0.0;
            $equity = is_array($metrics) ? (float) ($metrics['equity_value'] ?? 0.0) : 0.0;
            $open = is_array($metrics) ? (int) ($metrics['open_positions'] ?? 0) : 0;

            $byStrategy[] = [
                'id' => (int) $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'realized_pnl' => round($realized, 4),
                'unrealized_pnl' => round($unrealized, 4),
                'equity_value' => round($equity, 4),
                'win_rate' => is_array($metrics) ? ($metrics['win_rate'] ?? null) : null,
                'open_positions' => $open,
            ];

            $totalRealized += $realized;
            $totalUnrealized += $unrealized;
            $totalEquity += $equity;
            $openPositions += $open;
        }

        return [
            'total_realized_pnl' => round($totalRealized, 4),
            'total_unrealized_pnl' => round($totalUnrealized, 4),
            'total_equity' => round($totalEquity, 4),
            'open_positions' => $openPositions,
            'by_strategy' => $byStrategy,
        ];
    }
}
