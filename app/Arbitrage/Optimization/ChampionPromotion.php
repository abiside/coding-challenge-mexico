<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

use App\Models\ArbitrageSetting;
use App\Models\ArbitrageStrategy;
use App\Models\BotEvent;
use Illuminate\Support\Facades\DB;

/**
 * Promoción atómica de un challenger a champion.
 *
 * Una promoción es el único punto donde cambia la identidad del champion. La
 * semántica (acordada con el usuario) es:
 *
 *  1. El challenger ganador OCUPA el lugar del champion: se crea un nuevo
 *     registro champion con la config COMPLETA del ganador (la que ya demostró
 *     operar bien), no una derivada de los settings. Así el nuevo champion
 *     opera desde el primer ciclo en lugar de quedar "sin datos".
 *  2. Todos arrancan en CERO: el nuevo champion es un registro nuevo (sin
 *     trades ni evaluaciones) y la cohorte de challengers se regenera fresca.
 *     La competencia se evalúa de forma directa desde el mismo punto.
 *  3. Se conserva la HISTORIA: el champion anterior y todos los challengers de
 *     la cohorte previa quedan `archived` (no se borran) para análisis.
 *
 * El ArbitrageSetting se actualiza con los thresholds del ganador para que el
 * panel/UI reflejen la config vigente, pero la fuente de verdad de lo que opera
 * el engine es la config del registro champion.
 */
final class ChampionPromotion
{
    public function __construct(
        private readonly StrategyOptimizer $optimizer,
    ) {}

    /**
     * @param  array<string, mixed>  $baseConfig  config('arbitrage')
     */
    public function promote(
        int $userId,
        ArbitrageSetting $setting,
        ArbitrageStrategy $winner,
        array $baseConfig,
        string $source,
        bool $manual = false,
        ?string $rationale = null,
    ): ArbitrageStrategy {
        return DB::transaction(function () use ($userId, $setting, $winner, $baseConfig, $source, $manual, $rationale): ArbitrageStrategy {
            // Champion vigente: nos da generación y parentesco para el linaje.
            $current = ArbitrageStrategy::where('user_id', $userId)
                ->where('status', ArbitrageStrategy::STATUS_CHAMPION)
                ->orderByDesc('id')
                ->first();

            // Settings reflejan los thresholds del ganador (panel/UI + bootstrap).
            $this->applyConfigToSetting($setting, $winner);

            // El nuevo champion hereda la config COMPLETA del ganador.
            $config = (array) $winner->config;
            $hash = ArbitrageStrategy::hashConfig($config);

            // Historia: archiva champion(s) y challengers de la cohorte previa.
            ArbitrageStrategy::where('user_id', $userId)
                ->whereIn('status', [
                    ArbitrageStrategy::STATUS_CHAMPION,
                    ArbitrageStrategy::STATUS_CHALLENGER,
                ])
                ->update([
                    'status' => ArbitrageStrategy::STATUS_ARCHIVED,
                    'archived_at' => now(),
                ]);

            $generation = $current !== null ? ((int) $current->generation + 1) : 0;

            // Nuevo champion: registro nuevo => arranca en cero (sin trades ni
            // evaluaciones), score reseteado.
            $champion = ArbitrageStrategy::create([
                'user_id' => $userId,
                'name' => 'Champion gen'.$generation,
                'status' => ArbitrageStrategy::STATUS_CHAMPION,
                'origin' => $manual ? ArbitrageStrategy::ORIGIN_MANUAL : ArbitrageStrategy::ORIGIN_AGENT,
                'parent_id' => $current?->id,
                'generation' => $generation,
                'config' => $config,
                'config_hash' => $hash,
                'score' => 0,
                'rationale' => $rationale,
                'promoted_at' => now(),
            ]);

            // Regenera challengers frescos alrededor del nuevo champion: también
            // arrancan en cero, para una competencia directa.
            $maxChallengers = max(0, (int) $setting->autopilot_max_challengers);
            if ($maxChallengers > 0 && (bool) $setting->autopilot_enabled) {
                $fresh = $this->optimizer->freshProposals(
                    $userId,
                    $champion,
                    $maxChallengers,
                    [$hash],
                );
                foreach ($fresh as $proposal) {
                    ArbitrageStrategy::create([
                        'user_id' => $userId,
                        'name' => $proposal->name,
                        'status' => ArbitrageStrategy::STATUS_CHALLENGER,
                        'origin' => ArbitrageStrategy::ORIGIN_AGENT,
                        'parent_id' => $proposal->parentId,
                        'generation' => $proposal->generation,
                        'config' => $proposal->config,
                        'config_hash' => $proposal->configHash,
                        'score' => 0,
                        'rationale' => $proposal->rationale,
                    ]);
                }
            }

            BotEvent::create([
                'user_id' => $userId,
                'strategy_id' => $champion->id,
                'type' => $manual ? 'autopilot.promotion.manual' : 'autopilot.promotion',
                'level' => 'info',
                'payload' => [
                    'challenger_id' => (int) $winner->id,
                    'champion_id' => (int) $champion->id,
                    'previous_champion_id' => $current?->id,
                    'generation' => $generation,
                    'source' => $source,
                    'rationale' => $rationale,
                ],
                'created_at' => now(),
            ]);

            return $champion;
        });
    }

    /**
     * Copia los thresholds del ganador al ArbitrageSetting para que el panel y
     * los settings reflejen la config del nuevo champion.
     */
    private function applyConfigToSetting(ArbitrageSetting $setting, ArbitrageStrategy $winner): void
    {
        $config = (array) $winner->config;
        $thresholds = (array) ($config['thresholds'] ?? []);

        $setting->fill([
            'symbols' => (array) ($config['symbols'] ?? $setting->symbols),
            'min_net_profit' => (float) ($thresholds['min_net_profit'] ?? $setting->min_net_profit),
            'min_net_margin' => (float) ($thresholds['min_net_margin'] ?? $setting->min_net_margin),
            'min_base_volume' => (float) ($thresholds['min_base_volume'] ?? $setting->min_base_volume),
            'max_base_volume' => (float) ($thresholds['max_base_volume'] ?? $setting->max_base_volume),
            'freshness_ms' => (int) ($config['freshness_ms'] ?? $setting->freshness_ms),
            'latency_max_ms' => (int) ($config['latency']['max_ms'] ?? $setting->latency_max_ms),
        ]);
        $setting->save();
    }
}
