<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Strategies;

use App\Http\Controllers\Controller;
use App\Models\AiRecommendation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recomendaciones del AI Supervisor para el usuario. Solo lectura + cambiar
 * estado (descartar / aplicar); la IA nunca ejecuta operaciones.
 */
class AiRecommendationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $limit = max(1, min(100, (int) $request->query('limit', 20)));

        $recs = AiRecommendation::where('user_id', $userId)
            ->latest('id')
            ->limit($limit)
            ->get();

        $latestSummary = AiRecommendation::where('user_id', $userId)
            ->where('type', 'market_summary')
            ->latest('id')
            ->first();

        return response()->json([
            'latest_summary' => $latestSummary,
            'data' => $recs,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $data = $request->validate([
            'status' => ['required', 'in:active,dismissed,applied'],
        ]);

        $rec = AiRecommendation::where('user_id', $userId)->where('id', $id)->firstOrFail();
        $rec->update(['status' => $data['status']]);

        return response()->json(['data' => $rec]);
    }
}
