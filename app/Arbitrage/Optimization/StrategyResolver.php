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
     * Garantiza que exista UN champion estable para el usuario.
     *
     * La identidad del champion la posee EXCLUSIVAMENTE el ciclo de promoción
     * (ChampionPromotion). Aquí NO recreamos el champion ante un cambio de hash:
     * hacerlo lo dejaba "fresco" (sin trades) cada vez que la config base de
     * `config('arbitrage')` derivaba (cache/WIP/restart), desincronizándolo de
     * sus challengers y mostrándolo "sin datos".
     *
     * Comportamiento:
     *  - Si hay champion(s): conserva el más reciente como canónico y archiva
     *    cualquier duplicado (defensa ante carreras). Devuelve su identidad y
     *    su histórico intactos.
     *  - En modo MANUAL (autopilot apagado): si el usuario editó sus settings,
     *    sincroniza la config del champion IN-PLACE (mismo id) para que el
     *    cambio surta efecto sin perder el historial de trades.
     *  - Si no existe ninguno: hace bootstrap creando el champion base.
     *
     * @param  array<string, mixed>  $baseConfig
     */
    public function ensureChampion(int $userId, ArbitrageSetting $setting, array $baseConfig): ArbitrageStrategy
    {
        $config = $setting->toEngineConfig($baseConfig);
        $hash = ArbitrageStrategy::hashConfig($config);

        $champions = ArbitrageStrategy::where('user_id', $userId)
            ->where('status', ArbitrageStrategy::STATUS_CHAMPION)
            ->orderByDesc('id')
            ->get();

        if ($champions->isNotEmpty()) {
            $canonical = $champions->first();

            // Unicidad: archiva cualquier otro champion duplicado.
            $staleIds = $champions->where('id', '!=', $canonical->id)->pluck('id');
            if ($staleIds->isNotEmpty()) {
                ArbitrageStrategy::whereIn('id', $staleIds)->update([
                    'status' => ArbitrageStrategy::STATUS_ARCHIVED,
                    'archived_at' => now(),
                ]);
            }

            // Modo manual: refleja ediciones de settings sin cambiar de identidad.
            if (! $setting->autopilot_enabled && (string) $canonical->config_hash !== $hash) {
                $canonical->forceFill([
                    'config' => $config,
                    'config_hash' => $hash,
                ])->save();
            }

            return $canonical;
        }

        // Bootstrap: aún no hay champion para este usuario.
        return ArbitrageStrategy::create([
            'user_id' => $userId,
            'name' => 'Champion '.now()->format('Y-m-d H:i'),
            'status' => ArbitrageStrategy::STATUS_CHAMPION,
            'origin' => ArbitrageStrategy::ORIGIN_BASELINE,
            'parent_id' => null,
            'generation' => 0,
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
