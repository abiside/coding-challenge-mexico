<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\DTO;

/**
 * Ciclo detectado por el scanner antes de evaluarse contra liquidez y wallet.
 *
 * Las aristas están ordenadas como se ejecutarían: la primera consume del
 * `startAsset` en `startExchange` y la última devuelve a ese mismo nodo.
 */
final class CycleCandidate
{
    /**
     * @param  array<int, ConversionEdge>  $edges
     */
    public function __construct(
        public readonly array $edges,
        public readonly float $netRateProduct,
        public readonly int $detectedAtMs,
    ) {
    }

    public function start(): AssetNode
    {
        return $this->edges[0]->from;
    }

    public function startAsset(): string
    {
        return $this->start()->asset;
    }

    public function startExchange(): string
    {
        return $this->start()->exchange;
    }

    public function length(): int
    {
        return count($this->edges);
    }

    /**
     * Spread bruto en bps del ciclo: (producto_rates_brutas - 1) en bps.
     */
    public function grossSpreadBps(): float
    {
        $product = 1.0;
        foreach ($this->edges as $edge) {
            $product *= $edge->grossRate;
        }

        return ($product - 1.0) * 10000.0;
    }

    /**
     * Descripción legible para logs/UI: USDT->BTC->ETH->USDT @ binance->kraken->binance.
     */
    public function label(): string
    {
        $assets = [$this->edges[0]->from->asset];
        $exchanges = [];
        foreach ($this->edges as $edge) {
            $assets[] = $edge->to->asset;
            $exchanges[] = $edge->from->exchange;
        }

        return implode('->', $assets).' @ '.implode('->', $exchanges);
    }

    /**
     * Clave estable de circuit breaker / idempotencia.
     */
    public function key(): string
    {
        $parts = [$this->edges[0]->from->key()];
        foreach ($this->edges as $edge) {
            $parts[] = $edge->kind->value.':'.$edge->to->key();
        }

        return implode('|', $parts);
    }
}
