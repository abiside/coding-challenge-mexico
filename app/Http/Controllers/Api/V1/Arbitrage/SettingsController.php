<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Arbitrage;

use App\Http\Controllers\Controller;
use App\Models\ArbitrageSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración personal del engine por usuario.
 */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $setting = $this->resolveSetting($request);

        return response()->json([
            'data' => $setting,
            'options' => $this->options(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbols' => ['sometimes', 'array', 'min:1'],
            'symbols.*' => ['string', 'max:30'],
            'min_net_profit' => ['sometimes', 'numeric', 'min:0'],
            'min_net_margin' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'min_base_volume' => ['sometimes', 'numeric', 'min:0'],
            'max_base_volume' => ['sometimes', 'numeric', 'gt:0'],
            'freshness_ms' => ['sometimes', 'integer', 'min:100', 'max:60000'],
            'latency_max_ms' => ['sometimes', 'integer', 'min:100', 'max:60000'],
            'fees' => ['sometimes', 'nullable', 'array'],
            'fees.*' => ['numeric', 'min:0', 'max:1'],
            'circuit_breaker_enabled' => ['sometimes', 'boolean'],
            'onboarded' => ['sometimes', 'boolean'],
            'autopilot_enabled' => ['sometimes', 'boolean'],
            'optimization_objective' => ['sometimes', 'string', 'in:net_pnl,volume,risk_adjusted'],
            'autopilot_max_challengers' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'autopilot_auto_promote' => ['sometimes', 'boolean'],
            'autopilot_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'simulation_enabled' => ['sometimes', 'boolean'],
            'simulation_max_drift_pct' => ['sometimes', 'numeric', 'min:0', 'max:10'],
            'simulation_max_exec_drift_pct' => ['sometimes', 'numeric', 'min:0', 'max:10'],
        ]);

        $setting = $this->resolveSetting($request);
        $setting->fill($data);
        $setting->save();

        return response()->json(['data' => $setting->fresh()]);
    }

    private function resolveSetting(Request $request): ArbitrageSetting
    {
        $defaults = (array) config('arbitrage');

        return ArbitrageSetting::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'symbols' => $defaults['symbols'] ?? ['BTC/USDT'],
                'min_net_profit' => $defaults['thresholds']['min_net_profit'] ?? 1,
                'min_net_margin' => $defaults['thresholds']['min_net_margin'] ?? 0.0005,
                'min_base_volume' => $defaults['thresholds']['min_base_volume'] ?? 0.0001,
                'max_base_volume' => $defaults['thresholds']['max_base_volume'] ?? 1,
                'freshness_ms' => $defaults['freshness_ms'] ?? 2000,
                'latency_max_ms' => $defaults['latency']['max_ms'] ?? 1500,
                'circuit_breaker_enabled' => $defaults['circuit_breaker']['enabled'] ?? true,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'exchanges' => array_values((array) config('marketdata.exchanges', [])),
            'symbols' => array_values((array) config('arbitrage.symbols', [])),
            'assets' => ['USDT', 'USD', 'BTC', 'ETH'],
        ];
    }
}
