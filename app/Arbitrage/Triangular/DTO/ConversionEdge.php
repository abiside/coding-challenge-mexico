<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\DTO;

use App\Arbitrage\MarketData\BookState;

/**
 * Arista dirigida del grafo de conversiones.
 *
 * Encapsula todo lo necesario para evaluar una conversión: nodo origen y
 * destino, tasa bruta (cantidad de `to` por unidad de `from` antes de fee),
 * fee como fracción, referencia al book (en trades) y metadatos del par.
 *
 * Para trades, la tasa bruta se calcula sobre el "best price" (best ask para
 * BUY, best bid para SELL); el calculador de liquidez luego recorre la
 * profundidad real para obtener un VWAP exacto.
 */
final class ConversionEdge
{
    public function __construct(
        public readonly AssetNode $from,
        public readonly AssetNode $to,
        public readonly EdgeKind $kind,
        public readonly float $grossRate,
        public readonly float $feeRate,
        public readonly ?BookState $book = null,
        public readonly ?string $symbol = null,
    ) {
    }

    /**
     * Tasa neta de la arista: `to` recibido por unidad de `from` gastado,
     * descontando el fee/costo. Un ciclo es rentable si el producto de las
     * tasas netas de sus aristas es > 1.
     */
    public function netRate(): float
    {
        return $this->grossRate * (1.0 - $this->feeRate);
    }

    public function isTrade(): bool
    {
        return $this->kind !== EdgeKind::Transfer;
    }
}
