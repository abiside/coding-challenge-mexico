<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Http\Controllers\Controller;
use App\Models\Trade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lista trades simulados con sus fills para el dashboard.
 */
class TradeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['nullable', 'string', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Trade::query()
            ->where('user_id', $request->user()->id)
            ->with('fills')
            ->latest('id');

        if (! empty($validated['symbol'])) {
            $query->where('symbol', strtoupper($validated['symbol']));
        }

        $trades = $query->limit((int) ($validated['limit'] ?? 50))->get();

        return response()->json([
            'data' => $trades,
            'realized_pnl_total' => round((float) $trades->sum('realized_pnl'), 8),
        ]);
    }

    /**
     * Curva de P&L acumulado ESTABLE para la gráfica del dashboard.
     *
     * Se calcula en el servidor sobre TODOS los trades del usuario (no el feed
     * acotado a 200), agrupando por buckets de tiempo fijos. Cada bucket es una
     * función determinista de `executed_at_ms`, así que su valor no cambia entre
     * polls: la historia ya no se reescribe sobre la marcha. Solo se agregan
     * buckets nuevos a la derecha conforme entran trades.
     */
    public function equity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tf' => ['nullable', 'in:h24,day,week'],
        ]);

        $userId = (int) $request->user()->id;
        $windowMs = match ($validated['tf'] ?? 'week') {
            'h24' => 3_600_000,
            'day' => 86_400_000,
            default => 604_800_000,
        };

        $now = (int) (microtime(true) * 1000);
        $windowStart = $now - $windowMs;

        // Offset acumulado de todo lo previo a la ventana: preserva el nivel real
        // de la curva sin necesidad de graficar el histórico completo.
        $base = (float) Trade::where('user_id', $userId)
            ->where('executed_at_ms', '<', $windowStart)
            ->sum('realized_pnl');

        $bounds = Trade::where('user_id', $userId)
            ->where('executed_at_ms', '>=', $windowStart)
            ->selectRaw('MIN(executed_at_ms) AS min_ms, MAX(executed_at_ms) AS max_ms, COUNT(*) AS n')
            ->first();

        if ($bounds === null || (int) $bounds->n === 0) {
            return response()->json([
                'axis' => [$windowStart, $now],
                'values' => [round($base, 4), round($base, 4)],
                'total' => round($base, 4),
                'window_total' => 0.0,
                'since_ms' => $windowStart,
            ]);
        }

        $dataMin = (int) $bounds->min_ms;
        $dataMax = (int) $bounds->max_ms;
        $span = max(1, $dataMax - $dataMin);
        $bucketMs = max(1000, (int) ceil($span / 120));

        $rows = Trade::where('user_id', $userId)
            ->whereBetween('executed_at_ms', [$dataMin, $dataMax])
            ->selectRaw('FLOOR((executed_at_ms - ?) / ?) AS bucket, SUM(realized_pnl) AS pnl', [$dataMin, $bucketMs])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $byBucket = [];
        foreach ($rows as $row) {
            $byBucket[(int) $row->bucket] = (float) $row->pnl;
        }

        $axis = [$dataMin];
        $values = [round($base, 4)];
        $cum = $base;
        $lastBucket = (int) floor($span / $bucketMs);
        for ($i = 0; $i <= $lastBucket; $i++) {
            $cum += $byBucket[$i] ?? 0.0;
            $axis[] = $dataMin + ($i + 1) * $bucketMs;
            $values[] = round($cum, 4);
        }

        return response()->json([
            'axis' => $axis,
            'values' => $values,
            'total' => round($cum, 4),
            'window_total' => round($cum - $base, 4),
            'since_ms' => $dataMin,
        ]);
    }
}
