<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\DTO;

/**
 * Tipo de conversión que representa una arista del grafo.
 */
enum EdgeKind: string
{
    /** Compra spot (gasto QUOTE, recibo BASE) en un mismo exchange. */
    case TradeBuy = 'trade_buy';

    /** Venta spot (gasto BASE, recibo QUOTE) en un mismo exchange. */
    case TradeSell = 'trade_sell';

    /**
     * Equivalencia de inventario cross-exchange para el mismo activo:
     * modela que ya mantenemos saldo en ambos wallets, sin retiro real.
     */
    case Transfer = 'transfer';
}
