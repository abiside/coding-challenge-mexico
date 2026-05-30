<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\DTO;

/**
 * Nodo del grafo de conversiones: un activo en un exchange concreto.
 *
 * Los ciclos de arbitraje triangular se modelan como caminos cerrados entre
 * nodos `(exchange, asset)`. La igualdad se compara por la clave normalizada
 * `exchange:asset` (lowercase exchange, uppercase asset).
 */
final class AssetNode
{
    public readonly string $exchange;

    public readonly string $asset;

    public function __construct(string $exchange, string $asset)
    {
        $this->exchange = strtolower(trim($exchange));
        $this->asset = strtoupper(trim($asset));
    }

    public function key(): string
    {
        return $this->exchange.':'.$this->asset;
    }

    public function equals(AssetNode $other): bool
    {
        return $this->exchange === $other->exchange && $this->asset === $other->asset;
    }
}
