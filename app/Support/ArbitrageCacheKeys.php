<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Centraliza las claves de cache y nombres de canal del dashboard, scoped por
 * usuario, para que API, engine y broadcasting coincidan.
 */
final class ArbitrageCacheKeys
{
    public static function snapshotPrefix(int $userId): string
    {
        $base = (string) config('arbitrage.dashboard.snapshot_cache_prefix', 'arbitrage:snapshot');

        return sprintf('%s:u%d', $base, $userId);
    }

    public static function snapshot(int $userId, string $symbol): string
    {
        return self::snapshotPrefix($userId).':'.strtolower(str_replace('/', '-', $symbol));
    }

    /**
     * Canal privado de Reverb por usuario (sin el prefijo "private-", que añade
     * Echo/Broadcasting automáticamente).
     */
    public static function dashboardChannel(int $userId): string
    {
        return sprintf('arbitrage.user.%d', $userId);
    }

    /**
     * Clave de cache del último snapshot de métricas del engine (embudo de
     * descartes + decisiones) para que la pantalla Engine tenga estado inicial
     * vía REST antes de recibir el primer evento por websocket.
     */
    public static function engineMetrics(int $userId): string
    {
        return sprintf('arbitrage:engine_metrics:u%d', $userId);
    }
}
