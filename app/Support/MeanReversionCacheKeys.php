<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Claves de cache y nombre de canal del panel de mean-reversion, scoped POR
 * USUARIO: cada quien prueba el modo con su propia billetera/posiciones, así
 * que API, worker y broadcasting comparten estas claves por user id.
 */
final class MeanReversionCacheKeys
{
    /** Canal PRIVADO de Reverb por usuario (sin el prefijo "private-"). */
    public static function channel(int $userId): string
    {
        return sprintf('meanrev.user.%d', $userId);
    }

    /** Último snapshot de métricas + wallet + posiciones (heartbeat). */
    public static function metrics(int $userId): string
    {
        return sprintf('meanrev:metrics:u%d', $userId);
    }

    /** Lista rodante de las últimas señales accionadas (feed inicial REST). */
    public static function recentSignals(int $userId): string
    {
        return sprintf('meanrev:recent_signals:u%d', $userId);
    }
}
