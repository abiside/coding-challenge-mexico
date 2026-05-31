<?php

declare(strict_types=1);

namespace App\Strategies\Engine;

use App\Strategies\Engine\Strategies\LiquidityShockStrategy;
use App\Strategies\Engine\Strategies\MeanReversionLongStrategy;
use App\Strategies\Engine\Strategies\MeanReversionShortStrategy;
use App\Strategies\Engine\Strategies\MomentumBreakdownShortStrategy;
use App\Strategies\Engine\Strategies\PumpExhaustionShortStrategy;
use App\Strategies\Engine\Strategies\StatisticalOpportunityRankingStrategy;
use App\Strategies\Engine\Strategies\VolatilityBreakoutLongStrategy;

/**
 * Construye la estrategia concreta a partir del identificador `algorithm` y sus
 * parámetros. El ranking estadístico recibe todas las demás como candidatas.
 */
final class StrategyFactory
{
    /**
     * Catálogo legible para el wizard de creación (algorithm => metadata).
     *
     * @return array<int, array{algorithm: string, name: string, side: string, description: string}>
     */
    public static function catalog(): array
    {
        return [
            ['algorithm' => 'mean_reversion_long', 'name' => 'Reversión a la media (long)', 'side' => 'long', 'description' => 'Compra tras una caída excesiva esperando rebote (z-score bajo).'],
            ['algorithm' => 'mean_reversion_short', 'name' => 'Reversión a la media (short)', 'side' => 'short', 'description' => 'Short cuando el precio se aleja demasiado por encima de su media (z-score alto).'],
            ['algorithm' => 'volatility_breakout_long', 'name' => 'Ruptura de volatilidad (long)', 'side' => 'long', 'description' => 'Compra cuando rompe su máximo reciente con volumen fuerte.'],
            ['algorithm' => 'pump_exhaustion_short', 'name' => 'Agotamiento de pump (short)', 'side' => 'short', 'description' => 'Short cuando una moneda sube demasiado rápido y muestra agotamiento.'],
            ['algorithm' => 'momentum_breakdown_short', 'name' => 'Quiebre de momentum (short)', 'side' => 'short', 'description' => 'Short cuando pierde estructura tras un impulso alcista (confirmación bajista).'],
            ['algorithm' => 'liquidity_shock', 'name' => 'Choque de liquidez', 'side' => 'both', 'description' => 'Long/short por cambios bruscos en el imbalance del order book.'],
            ['algorithm' => 'statistical_ranking', 'name' => 'Ranking estadístico', 'side' => 'both', 'description' => 'Ejecuta todas las estrategias y prioriza la mejor señal por score.'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function algorithms(): array
    {
        return array_map(static fn (array $c): string => $c['algorithm'], self::catalog());
    }

    public static function isValid(string $algorithm): bool
    {
        return in_array($algorithm, self::algorithms(), true);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function make(string $algorithm, array $params): TradingStrategy
    {
        return match ($algorithm) {
            'mean_reversion_long' => new MeanReversionLongStrategy($params),
            'mean_reversion_short' => new MeanReversionShortStrategy($params),
            'volatility_breakout_long' => new VolatilityBreakoutLongStrategy($params),
            'pump_exhaustion_short' => new PumpExhaustionShortStrategy($params),
            'momentum_breakdown_short' => new MomentumBreakdownShortStrategy($params),
            'liquidity_shock' => new LiquidityShockStrategy($params),
            'statistical_ranking' => new StatisticalOpportunityRankingStrategy($params, [
                new MeanReversionLongStrategy($params),
                new MeanReversionShortStrategy($params),
                new VolatilityBreakoutLongStrategy($params),
                new PumpExhaustionShortStrategy($params),
                new MomentumBreakdownShortStrategy($params),
                new LiquidityShockStrategy($params),
            ]),
            default => new MeanReversionLongStrategy($params),
        };
    }
}
