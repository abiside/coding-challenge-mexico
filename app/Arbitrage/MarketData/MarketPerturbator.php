<?php

declare(strict_types=1);

namespace App\Arbitrage\MarketData;

use App\Domain\MarketData\DTO\OrderBookLevel;
use App\Domain\MarketData\DTO\OrderBookSnapshot;

/**
 * Modo simulación: desplaza sintéticamente los precios de un order book dentro
 * de un rango porcentual (`±maxDriftPct`).
 *
 * Cada snapshot recibe un factor multiplicativo determinista derivado de la
 * IDENTIDAD del snapshot (exchange + símbolo + timestamp + sequence) y de la
 * deriva configurada. Al ser determinista, dos engines con la misma config de
 * simulación (p. ej. champion y sus challengers) ven EXACTAMENTE el mismo book
 * efectivo para el mismo momento de mercado: la perturbación es compartida y la
 * comparación entre estrategias depende solo de sus parámetros, no de la suerte
 * del azar. Como el factor varía por exchange y por update, los books de
 * distintos exchanges siguen divergiendo y aparecen spreads cruzados
 * (`buy_ask < sell_bid`) lo bastante amplios para superar los costos.
 *
 * El escalado es uniforme sobre todos los niveles, así que se preserva el
 * orden interno del book (bids < asks) y la estructura de profundidad: solo
 * cambia el nivel de precio, no la forma.
 *
 * Es puramente para pruebas/demo: NO usar con datos de mercado reales en prod.
 */
final class MarketPerturbator
{
    public function __construct(
        private readonly float $maxDriftPct,
    ) {
    }

    /**
     * Construye un perturbador desde la config del engine, o null si el modo
     * simulación está apagado o la deriva configurada es 0.
     *
     * @param  array<string, mixed>  $config  config('arbitrage')
     */
    public static function fromConfig(array $config): ?self
    {
        $simulation = (array) ($config['simulation'] ?? []);
        $enabled = (bool) ($simulation['enabled'] ?? false);
        $maxDriftPct = (float) ($simulation['max_drift_pct'] ?? 0.0);

        if (! $enabled || $maxDriftPct <= 0.0) {
            return null;
        }

        return new self($maxDriftPct);
    }

    /**
     * Devuelve un nuevo snapshot con los precios desplazados. El snapshot
     * original es inmutable y no se modifica.
     */
    public function apply(OrderBookSnapshot $snapshot): OrderBookSnapshot
    {
        $factor = $this->factorFor($snapshot);

        return new OrderBookSnapshot(
            exchange: $snapshot->exchange,
            symbol: $snapshot->symbol,
            bids: $this->shiftLevels($snapshot->bids, $factor),
            asks: $this->shiftLevels($snapshot->asks, $factor),
            timestampMs: $snapshot->timestampMs,
            isSnapshot: $snapshot->isSnapshot,
            sequence: $snapshot->sequence,
        );
    }

    /**
     * @param  array<int, OrderBookLevel>  $levels
     * @return array<int, OrderBookLevel>
     */
    private function shiftLevels(array $levels, float $factor): array
    {
        $shifted = [];
        foreach ($levels as $level) {
            $price = (float) $level->price * $factor;
            $shifted[] = new OrderBookLevel(
                price: $this->formatPrice($price),
                size: $level->size,
            );
        }

        return $shifted;
    }

    /**
     * Factor multiplicativo determinista para un snapshot. Misma identidad de
     * mercado + misma deriva => mismo factor, sin importar qué engine lo invoque.
     */
    private function factorFor(OrderBookSnapshot $snapshot): float
    {
        $seed = implode('|', [
            $snapshot->exchange,
            $snapshot->symbol,
            $snapshot->timestampMs,
            $snapshot->sequence ?? 0,
            $this->maxDriftPct,
        ]);

        // Uniforme en [-1, 1] derivado del hash, escalado por la deriva máxima.
        $unit = (crc32($seed) / 4294967295.0) * 2.0 - 1.0;

        return 1.0 + $unit * ($this->maxDriftPct / 100.0);
    }

    private function formatPrice(float $price): string
    {
        return rtrim(rtrim(number_format(max($price, 0.0), 8, '.', ''), '0'), '.') ?: '0';
    }
}
