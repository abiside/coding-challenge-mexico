<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Claves de cache y canal de broadcasting del módulo de Estrategias, scoped por
 * INSTANCIA (strategy id): cada instancia tiene su propia billetera, posiciones
 * y panel. API, worker y broadcasting comparten estas claves por strategy id.
 */
final class StrategyCacheKeys
{
    /** Canal PRIVADO de Reverb por usuario (sin el prefijo "private-"). */
    public static function channel(int $userId): string
    {
        return sprintf('strategies.user.%d', $userId);
    }

    /** Último snapshot de métricas + wallet + posiciones de una instancia. */
    public static function metrics(int $strategyId): string
    {
        return sprintf('strategies:metrics:s%d', $strategyId);
    }

    /** Lista rodante de señales recientes de una instancia (feed REST inicial). */
    public static function recentSignals(int $strategyId): string
    {
        return sprintf('strategies:recent_signals:s%d', $strategyId);
    }

    /** Bandera efímera para que el worker reinicie el ejercicio de una instancia. */
    public static function resetRequest(int $strategyId): string
    {
        return sprintf('strategies:reset_request:s%d', $strategyId);
    }

    /** Snapshot agregado de features de mercado para el AI Supervisor. */
    public static function marketSnapshot(int $userId): string
    {
        return sprintf('strategies:market_snapshot:u%d', $userId);
    }
}
