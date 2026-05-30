<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Http\Controllers\Controller;
use App\Support\ArbitrageCacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Devuelve el último estado procesado por el engine para el usuario.
 * Lee desde cache (lo escribe el ReverbDashboardPublisher); no calcula nada.
 */
class ArbitrageSnapshotController extends Controller
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $symbols = $this->userSymbols($request);
        $snapshots = [];

        foreach ($symbols as $symbol) {
            $snapshot = $this->cache->get(ArbitrageCacheKeys::snapshot($userId, $symbol));
            if ($snapshot !== null) {
                $snapshots[$symbol] = $snapshot;
            }
        }

        return response()->json([
            'symbols' => $symbols,
            'snapshots' => $snapshots,
        ]);
    }

    public function show(Request $request, string $symbol): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $symbol = strtoupper(str_replace('-', '/', $symbol));
        $snapshot = $this->cache->get(ArbitrageCacheKeys::snapshot($userId, $symbol));

        if ($snapshot === null) {
            return response()->json([
                'symbol' => $symbol,
                'snapshot' => null,
                'message' => 'Sin datos procesados todavía.',
            ], 404);
        }

        return response()->json([
            'symbol' => $symbol,
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function userSymbols(Request $request): array
    {
        $setting = $request->user()->arbitrageSetting;
        if ($setting !== null && ! empty($setting->symbols)) {
            return $setting->symbols;
        }

        return array_values((array) config('arbitrage.symbols', []));
    }
}
