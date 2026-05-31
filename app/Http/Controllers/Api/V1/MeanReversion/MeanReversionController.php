<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MeanReversion;

use App\Http\Controllers\Controller;
use App\Models\MeanReversionSession;
use App\Models\MeanReversionTrade;
use App\Support\MeanReversionCacheKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel de la estrategia de reversión a la media, scoped POR USUARIO: cada
 * quien prueba el modo con su propia billetera/posiciones aisladas. El worker
 * global `meanrev:run` reconcilia las sesiones (start/stop) en caliente.
 */
class MeanReversionController extends Controller
{
    /**
     * Estado de la sesión del usuario: métricas en vivo (cache del heartbeat),
     * posiciones abiertas, billetera y últimas señales para el feed inicial.
     */
    public function overview(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $session = $this->session($userId);

        $metrics = cache()->get(MeanReversionCacheKeys::metrics($userId));
        $recent = cache()->get(MeanReversionCacheKeys::recentSignals($userId));

        return response()->json([
            'enabled' => (bool) config('meanreversion.enabled', false),
            'active' => $session !== null && $session->isActive(),
            'session' => $session,
            'running' => is_array($metrics),
            'metrics' => is_array($metrics) ? $metrics : null,
            'recent_signals' => is_array($recent) ? array_values($recent) : [],
            'server_time_ms' => (int) (microtime(true) * 1000),
        ]);
    }

    /** Histórico de operaciones del usuario (más recientes primero). */
    public function trades(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $limit = max(1, min(500, (int) $request->query('limit', 100)));

        $base = MeanReversionTrade::query()->where('user_id', $userId);

        $trades = (clone $base)
            ->latest('id')
            ->limit($limit)
            ->get([
                'id', 'exchange', 'symbol', 'side', 'reason', 'price',
                'base_quantity', 'quote_amount', 'fee', 'realized_pnl',
                'z_score', 'executed_at_ms', 'created_at',
            ]);

        return response()->json([
            'data' => $trades,
            'summary' => [
                'trades_total' => (clone $base)->count(),
                'sells_total' => (clone $base)->where('side', 'sell')->count(),
                'realized_pnl' => round((float) (clone $base)->sum('realized_pnl'), 8),
            ],
        ]);
    }

    /** Inicia (o reactiva) la sesión del usuario con los parámetros por defecto. */
    public function start(Request $request): JsonResponse
    {
        if (! (bool) config('meanreversion.enabled', false)) {
            return response()->json([
                'message' => 'El modo de reversión a la media está deshabilitado en el servidor.',
            ], 422);
        }

        $userId = (int) $request->user()->id;
        $strategy = (array) config('meanreversion.strategy', []);
        $initialUsdt = (float) (config('meanreversion.initial_balances.USDT') ?? 10000.0);

        $session = MeanReversionSession::firstOrNew(['user_id' => $userId]);

        // Si arranca de cero (nueva o previamente detenida sin posiciones), copia
        // los parámetros por defecto y resetea la billetera; si se reanuda con
        // inventario, conserva el snapshot para no perder posiciones abiertas.
        $session->status = MeanReversionSession::STATUS_ACTIVE;
        $session->started_at = now();
        $session->stopped_at = null;
        if (! $session->exists) {
            $session->initial_usdt = $initialUsdt;
            $session->params = $strategy;
            $session->wallet_snapshot = null;
            $session->position_snapshot = null;
            $session->realized_pnl = 0;
        }
        $session->save();

        return response()->json(['active' => true, 'session' => $session], 201);
    }

    /**
     * Reinicia el "ejercicio" del usuario: borra TODAS sus transacciones
     * (mean_reversion_trades) y restaura billetera + posiciones + P&L a cero,
     * SIN tocar el histórico de precios que el worker usa para evaluar monedas.
     *
     * Si la sesión está activa, deja una bandera en cache para que el worker
     * reinicie el engine en memoria (la billetera/posiciones viven ahí, no en
     * DB); si está detenida, basta con limpiar el snapshot persistido.
     */
    public function reset(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $deletedTrades = MeanReversionTrade::where('user_id', $userId)->delete();

        $session = MeanReversionSession::where('user_id', $userId)->first();
        if ($session !== null) {
            $session->update([
                'wallet_snapshot' => null,
                'position_snapshot' => null,
                'realized_pnl' => 0,
            ]);
        }

        // Limpia el panel (heartbeat + feed) para un render fresco inmediato.
        cache()->forget(MeanReversionCacheKeys::metrics($userId));
        cache()->forget(MeanReversionCacheKeys::recentSignals($userId));

        // Señal para que el worker reinicie el engine en vivo (si está activo).
        cache()->put(MeanReversionCacheKeys::resetRequest($userId), (int) (microtime(true) * 1000), 300);

        return response()->json([
            'reset' => true,
            'active' => $session !== null && $session->isActive(),
            'deleted' => ['mean_reversion_trades' => $deletedTrades],
        ]);
    }

    /** Detiene la sesión del usuario (el worker derribará su engine). */
    public function stop(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        MeanReversionSession::where('user_id', $userId)
            ->where('status', MeanReversionSession::STATUS_ACTIVE)
            ->update([
                'status' => MeanReversionSession::STATUS_STOPPED,
                'stopped_at' => now(),
            ]);

        return response()->json(['active' => false]);
    }

    private function session(int $userId): ?MeanReversionSession
    {
        return MeanReversionSession::where('user_id', $userId)->first();
    }
}
