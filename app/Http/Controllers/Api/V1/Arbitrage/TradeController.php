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
     * Se calcula sobre TODOS los trades del usuario (no el feed acotado a 200),
     * agrupando por buckets de tiempo de tamaño FIJO por timeframe y ALINEADOS A
     * ÉPOCA ABSOLUTA: el bucket de un trade es `floor(executed_at_ms / bucketMs)`,
     * un valor que NO depende de cuándo se consulta ni de los demás trades. Por
     * eso un punto histórico siempre conserva su mismo (tiempo, valor) entre
     * polls; jamás se re-agrupa ni se "deforma". Conforme avanza el tiempo solo
     * se agrega un bucket nuevo a la derecha y el más viejo sale por la izquierda
     * (su P&L se pliega al offset `base`, así los puntos retenidos no cambian).
     */
    public function equity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tf' => ['nullable', 'in:m15,h1,h4,h24,day,week'],
        ]);

        $tf = $validated['tf'] ?? 'm15';
        $userId = (int) $request->user()->id;

        // Ventana y tamaño de bucket FIJOS por timeframe (≈120-180 puntos).
        [$windowMs, $bucketMs] = match ($tf) {
            'm15' => [900_000, 5_000],           // 15min en buckets de 5s → 180 pts
            'h1', 'h24' => [3_600_000, 30_000],  // 1h en buckets de 30s → 120 pts
            'h4' => [14_400_000, 120_000],       // 4h en buckets de 2min → 120 pts
            'day' => [86_400_000, 600_000],      // 24h en buckets de 10min → 144 pts
            default => [604_800_000, 3_600_000], // 7d en buckets de 1h → 168 pts
        };

        $now = (int) (microtime(true) * 1000);
        $firstIdx = intdiv($now - $windowMs, $bucketMs);
        $lastIdx = intdiv($now, $bucketMs);
        $firstBoundary = $firstIdx * $bucketMs;
        $endBoundary = ($lastIdx + 1) * $bucketMs;

        // Offset acumulado de todo lo previo al primer bucket mostrado. Como el
        // límite es un múltiplo absoluto de bucketMs, `base` solo cambia cuando un
        // bucket entero sale de la ventana (su P&L se suma aquí), preservando el
        // valor absoluto de cada punto retenido.
        $base = (float) Trade::where('user_id', $userId)
            ->where('executed_at_ms', '<', $firstBoundary)
            ->sum('realized_pnl');

        $rows = Trade::where('user_id', $userId)
            ->where('executed_at_ms', '>=', $firstBoundary)
            ->where('executed_at_ms', '<', $endBoundary)
            ->selectRaw('FLOOR(executed_at_ms / ?) AS bucket, SUM(realized_pnl) AS pnl', [$bucketMs])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $byBucket = [];
        foreach ($rows as $row) {
            $byBucket[(int) $row->bucket] = (float) $row->pnl;
        }

        // Punto ancla al inicio del primer bucket (= base) y un punto por el fin
        // de cada bucket con el acumulado absoluto hasta ese instante.
        $axis = [$firstBoundary];
        $values = [round($base, 4)];
        $cum = $base;
        for ($idx = $firstIdx; $idx <= $lastIdx; $idx++) {
            $cum += $byBucket[$idx] ?? 0.0;
            $axis[] = ($idx + 1) * $bucketMs;
            $values[] = round($cum, 4);
        }

        return response()->json([
            'axis' => $axis,
            'values' => $values,
            'total' => round($cum, 4),
            'window_total' => round($cum - $base, 4),
            'since_ms' => $firstBoundary,
        ]);
    }
}
