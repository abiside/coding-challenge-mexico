<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

use App\Models\ArbitrageSetting;
use App\Models\ArbitrageStrategy;
use App\Models\SimulationRun;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resuelve qué estrategias deben tener un engine corriendo en este momento
 * para un usuario, garantizando que siempre exista un champion sincronizado
 * con sus ArbitrageSetting.
 *
 * - Si el autopilot está apagado, solo opera el champion (settings actuales).
 * - Si está prendido, además de champion devuelve los challengers vivos.
 *
 * El champion se materializa "perezosamente": si no hay uno coherente con
 * el ArbitrageSetting actual, lo creamos/archivamos para reflejar el cambio.
 */
final class StrategyResolver
{
    /**
     * @param  array<string, mixed>  $baseConfig  config('arbitrage')
     * @return Collection<int, ArbitrageStrategy>
     */
    public function resolveForUser(int $userId, ArbitrageSetting $setting, array $baseConfig): Collection
    {
        $champion = $this->ensureChampion($userId, $setting, $baseConfig);

        if (! $setting->autopilot_enabled) {
            // Sin autopilot: archivamos challengers vivos para no malgastar CPU.
            ArbitrageStrategy::where('user_id', $userId)
                ->where('status', ArbitrageStrategy::STATUS_CHALLENGER)
                ->update([
                    'status' => ArbitrageStrategy::STATUS_ARCHIVED,
                    'archived_at' => now(),
                ]);

            return Collection::make([$champion]);
        }

        $challengers = ArbitrageStrategy::where('user_id', $userId)
            ->where('status', ArbitrageStrategy::STATUS_CHALLENGER)
            ->orderByDesc('created_at')
            ->limit((int) $setting->autopilot_max_challengers)
            ->get();

        return $challengers->prepend($champion);
    }

    /**
     * @param  array<string, mixed>  $baseConfig
     */
    public function ensureChampion(int $userId, ArbitrageSetting $setting, array $baseConfig): ArbitrageStrategy
    {
        $config = $setting->toEngineConfig($baseConfig);
        $hash = ArbitrageStrategy::hashConfig($config);

        $current = ArbitrageStrategy::where('user_id', $userId)
            ->where('status', ArbitrageStrategy::STATUS_CHAMPION)
            ->latest('id')
            ->first();

        if ($current !== null && $current->config_hash === $hash) {
            return $current;
        }

        if ($current !== null) {
            // Settings cambiaron: archiva el champion anterior para auditoría.
            $current->update([
                'status' => ArbitrageStrategy::STATUS_ARCHIVED,
                'archived_at' => now(),
            ]);
        }

        return ArbitrageStrategy::create([
            'user_id' => $userId,
            'name' => 'Champion '.now()->format('Y-m-d H:i'),
            'status' => ArbitrageStrategy::STATUS_CHAMPION,
            'origin' => ArbitrageStrategy::ORIGIN_BASELINE,
            'parent_id' => $current?->id,
            'generation' => $current ? ((int) $current->generation + 1) : 0,
            'config' => $config,
            'config_hash' => $hash,
            'promoted_at' => now(),
        ]);
    }

    public function hasActiveSimulation(int $userId): bool
    {
        return SimulationRun::where('user_id', $userId)
            ->where('status', SimulationRun::STATUS_ACTIVE)
            ->exists();
    }
}
