<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

/**
 * Cota dura para cada parámetro mutable de una estrategia. Es la fuente única
 * de verdad de los guardrails: el optimizador propone candidatos perturbando
 * dentro de estos rangos y el LLM advisor pasa por estos mismos clamps, así
 * ningún path puede saltarse los límites validados por SettingsController.
 */
final class StrategyBounds
{
    /**
     * @return array<string, array{min: float, max: float, step: float, type: string}>
     */
    public static function ranges(): array
    {
        return [
            'min_net_profit' => ['min' => 0.0, 'max' => 1000.0, 'step' => 0.25, 'type' => 'float'],
            'min_net_margin' => ['min' => 0.0, 'max' => 1.0, 'step' => 0.0001, 'type' => 'float'],
            'min_base_volume' => ['min' => 0.000001, 'max' => 100.0, 'step' => 0.0001, 'type' => 'float'],
            'max_base_volume' => ['min' => 0.0001, 'max' => 1000.0, 'step' => 0.01, 'type' => 'float'],
            'freshness_ms' => ['min' => 100.0, 'max' => 60000.0, 'step' => 100.0, 'type' => 'int'],
            'latency_max_ms' => ['min' => 100.0, 'max' => 60000.0, 'step' => 100.0, 'type' => 'int'],
        ];
    }

    /**
     * Aplica clamp y tipado a un set de parámetros propuestos.
     * También garantiza min_base_volume < max_base_volume.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, float|int>
     */
    public static function clamp(array $params): array
    {
        $out = [];
        foreach (self::ranges() as $key => $r) {
            if (! array_key_exists($key, $params)) {
                continue;
            }
            $value = (float) $params[$key];
            $value = max($r['min'], min($r['max'], $value));
            $out[$key] = $r['type'] === 'int' ? (int) round($value) : $value;
        }

        if (isset($out['min_base_volume'], $out['max_base_volume']) && $out['min_base_volume'] >= $out['max_base_volume']) {
            // Garantiza orden estricto sin caer fuera de rango.
            $out['max_base_volume'] = min(
                self::ranges()['max_base_volume']['max'],
                (float) $out['min_base_volume'] * 2.0,
            );
        }

        return $out;
    }

    /**
     * Toma un config completo (toEngineConfig) y reescribe solo los campos
     * mutables propuestos, clampeados y con coherencia interna.
     *
     * @param  array<string, mixed>  $baseConfig
     * @param  array<string, float|int>  $params
     * @return array<string, mixed>
     */
    public static function apply(array $baseConfig, array $params): array
    {
        $clamped = self::clamp($params);

        $thresholds = (array) ($baseConfig['thresholds'] ?? []);
        if (isset($clamped['min_net_profit'])) {
            $thresholds['min_net_profit'] = $clamped['min_net_profit'];
        }
        if (isset($clamped['min_net_margin'])) {
            $thresholds['min_net_margin'] = $clamped['min_net_margin'];
        }
        if (isset($clamped['min_base_volume'])) {
            $thresholds['min_base_volume'] = $clamped['min_base_volume'];
        }
        if (isset($clamped['max_base_volume'])) {
            $thresholds['max_base_volume'] = $clamped['max_base_volume'];
        }
        $baseConfig['thresholds'] = $thresholds;

        if (isset($clamped['freshness_ms'])) {
            $baseConfig['freshness_ms'] = (int) $clamped['freshness_ms'];
        }
        if (isset($clamped['latency_max_ms'])) {
            $baseConfig['latency'] = array_merge((array) ($baseConfig['latency'] ?? []), [
                'max_ms' => (int) $clamped['latency_max_ms'],
            ]);
        }

        return $baseConfig;
    }

    /**
     * Extrae los parámetros mutables de un config para alimentar al optimizador.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, float>
     */
    public static function extract(array $config): array
    {
        $thresholds = (array) ($config['thresholds'] ?? []);

        return [
            'min_net_profit' => (float) ($thresholds['min_net_profit'] ?? 0.0),
            'min_net_margin' => (float) ($thresholds['min_net_margin'] ?? 0.0),
            'min_base_volume' => (float) ($thresholds['min_base_volume'] ?? 0.0001),
            'max_base_volume' => (float) ($thresholds['max_base_volume'] ?? 1.0),
            'freshness_ms' => (float) ($config['freshness_ms'] ?? 2000),
            'latency_max_ms' => (float) ($config['latency']['max_ms'] ?? 1500),
        ];
    }
}
