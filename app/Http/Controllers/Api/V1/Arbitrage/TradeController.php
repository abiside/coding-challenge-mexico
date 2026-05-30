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
}
