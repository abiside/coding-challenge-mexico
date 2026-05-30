<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Http\Controllers\Controller;
use App\Models\TriangularOpportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Histórico de ciclos de arbitraje triangular para el dashboard. Espejo de
 * OpportunityController pero sobre `triangular_opportunities`, para que el
 * panel de ciclos no dependa solo del feed en vivo (WS) y muestre estado al
 * cargar la página. Filtra por usuario autenticado.
 */
class CycleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['nullable', 'in:execute,reject'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $userId = (int) $request->user()->id;

        $base = TriangularOpportunity::query()->where('user_id', $userId);

        $query = (clone $base)->latest('id');
        if (! empty($validated['decision'])) {
            $query->where('decision', $validated['decision']);
        }

        $cycles = $query->limit((int) ($validated['limit'] ?? 60))->get([
            'id', 'label', 'start_asset', 'start_exchange', 'cycle_length',
            'gross_spread_bps', 'net_profit', 'net_margin', 'realized_pnl',
            'decision', 'reasons', 'legs', 'exchanges', 'detected_at_ms',
            'executed_at_ms', 'created_at',
        ]);

        $sinceHour = now()->subHour();

        return response()->json([
            'data' => $cycles,
            'summary' => [
                'cycles_total' => (clone $base)->count(),
                'executed_total' => (clone $base)->where('decision', 'execute')->count(),
                'executed_last_hour' => (clone $base)->where('decision', 'execute')->where('created_at', '>=', $sinceHour)->count(),
                'realized_pnl' => round((float) (clone $base)->where('decision', 'execute')->sum('realized_pnl'), 8),
                'realized_pnl_last_hour' => round((float) (clone $base)->where('decision', 'execute')->where('created_at', '>=', $sinceHour)->sum('realized_pnl'), 8),
            ],
        ]);
    }
}
