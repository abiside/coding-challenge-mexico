<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lista oportunidades persistidas (histórico) para el dashboard.
 */
class OpportunityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['nullable', 'string', 'max:30'],
            'decision' => ['nullable', 'in:execute,reject'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Opportunity::query()
            ->where('user_id', $request->user()->id)
            ->latest('id');

        if (! empty($validated['symbol'])) {
            $query->where('symbol', strtoupper($validated['symbol']));
        }

        if (! empty($validated['decision'])) {
            $query->where('decision', $validated['decision']);
        }

        $opportunities = $query->limit((int) ($validated['limit'] ?? 50))->get();

        return response()->json([
            'data' => $opportunities,
        ]);
    }
}
