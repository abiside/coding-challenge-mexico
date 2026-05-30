<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Http\Controllers\Controller;
use App\Models\ArbitrageSetting;
use App\Models\SimulationRun;
use App\Models\Trade;
use App\Models\WalletBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Control de la sesión de simulación del usuario. El engine persistente
 * (arbitrage:run) reconcilia estas sesiones en caliente.
 */
class SimulationController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $run = $this->activeRun($userId);

        return response()->json([
            'active' => $run !== null,
            'run' => $run,
            'stats' => $this->stats($userId),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $setting = ArbitrageSetting::where('user_id', $userId)->first();

        if ($setting === null || ! $setting->onboarded) {
            return response()->json([
                'message' => 'Completa el onboarding (configuración) antes de iniciar.',
            ], 422);
        }

        $hasFunds = WalletBalance::where('user_id', $userId)->where('available', '>', 0)->exists();
        if (! $hasFunds) {
            return response()->json([
                'message' => 'Fondea al menos una wallet antes de iniciar la simulación.',
            ], 422);
        }

        $run = $this->activeRun($userId);
        if ($run === null) {
            $run = SimulationRun::create([
                'user_id' => $userId,
                'status' => SimulationRun::STATUS_ACTIVE,
                'config_snapshot' => $setting->toEngineConfig((array) config('arbitrage')),
                'started_at' => now(),
            ]);
        }

        return response()->json(['active' => true, 'run' => $run], 201);
    }

    public function stop(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        SimulationRun::where('user_id', $userId)
            ->where('status', SimulationRun::STATUS_ACTIVE)
            ->update([
                'status' => SimulationRun::STATUS_STOPPED,
                'stopped_at' => now(),
            ]);

        return response()->json(['active' => false]);
    }

    private function activeRun(int $userId): ?SimulationRun
    {
        return SimulationRun::where('user_id', $userId)
            ->where('status', SimulationRun::STATUS_ACTIVE)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(int $userId): array
    {
        return [
            'trades' => Trade::where('user_id', $userId)->count(),
            'realized_pnl' => (float) Trade::where('user_id', $userId)->sum('realized_pnl'),
        ];
    }
}
