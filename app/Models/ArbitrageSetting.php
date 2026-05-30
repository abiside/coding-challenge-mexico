<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArbitrageSetting extends Model
{
    public const OBJECTIVE_NET_PNL = 'net_pnl';

    public const OBJECTIVE_VOLUME = 'volume';

    public const OBJECTIVE_RISK_ADJUSTED = 'risk_adjusted';

    protected $fillable = [
        'user_id',
        'symbols',
        'min_net_profit',
        'min_net_margin',
        'min_base_volume',
        'max_base_volume',
        'freshness_ms',
        'latency_max_ms',
        'fees',
        'circuit_breaker_enabled',
        'onboarded',
        'autopilot_enabled',
        'optimization_objective',
        'autopilot_max_challengers',
        'autopilot_auto_promote',
        'autopilot_interval_minutes',
        'simulation_enabled',
        'simulation_max_drift_pct',
        'simulation_max_exec_drift_pct',
    ];

    protected function casts(): array
    {
        return [
            'symbols' => 'array',
            'fees' => 'array',
            'min_net_profit' => 'float',
            'min_net_margin' => 'float',
            'min_base_volume' => 'float',
            'max_base_volume' => 'float',
            'freshness_ms' => 'int',
            'latency_max_ms' => 'int',
            'circuit_breaker_enabled' => 'bool',
            'onboarded' => 'bool',
            'autopilot_enabled' => 'bool',
            'autopilot_max_challengers' => 'int',
            'autopilot_auto_promote' => 'bool',
            'autopilot_interval_minutes' => 'int',
            'simulation_enabled' => 'bool',
            'simulation_max_drift_pct' => 'float',
            'simulation_max_exec_drift_pct' => 'float',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Construye el array de configuración que consume EngineFactory, mezclando
     * los defaults globales de config/arbitrage con los overrides del usuario.
     *
     * @param  array<string, mixed>  $base  config('arbitrage')
     * @return array<string, mixed>
     */
    public function toEngineConfig(array $base): array
    {
        $base['symbols'] = $this->symbols ?: $base['symbols'];
        $base['freshness_ms'] = $this->freshness_ms;
        $base['thresholds'] = [
            'min_net_profit' => $this->min_net_profit,
            'min_net_margin' => $this->min_net_margin,
            'min_base_volume' => $this->min_base_volume,
            'max_base_volume' => $this->max_base_volume,
        ];
        $base['latency']['max_ms'] = $this->latency_max_ms;
        $base['circuit_breaker']['enabled'] = $this->circuit_breaker_enabled;

        if (! empty($this->fees) && is_array($this->fees)) {
            $base['fees'] = array_merge($base['fees'] ?? [], $this->fees);
        }

        // Modo simulación: jitter sintético de precios para generar spreads
        // rentables a demanda. Forma parte de la config (afecta el hash de la
        // estrategia, así un cambio dispara hot-reload del engine).
        $base['simulation'] = [
            'enabled' => (bool) $this->simulation_enabled,
            'max_drift_pct' => (float) $this->simulation_max_drift_pct,
            'max_exec_drift_pct' => (float) $this->simulation_max_exec_drift_pct,
        ];

        return $base;
    }
}
