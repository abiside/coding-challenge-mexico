<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Coinbase;

final class CoinbaseSymbolMapper
{
    /**
     * Convierte el símbolo normalizado (BTC/USDT) al formato Coinbase (BTC-USDT).
     */
    public static function toExchange(string $normalized): string
    {
        return strtoupper(str_replace('/', '-', $normalized));
    }

    /**
     * Convierte el product_id de Coinbase (BTC-USD) al formato normalizado (BTC/USD).
     */
    public static function normalize(string $productId): string
    {
        return strtoupper(str_replace('-', '/', $productId));
    }
}
