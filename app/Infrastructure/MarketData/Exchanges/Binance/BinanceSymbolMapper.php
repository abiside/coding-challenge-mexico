<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Binance;

final class BinanceSymbolMapper
{
    /**
     * Quote assets típicos en Binance (heurística para split).
     * El orden importa: primero los más largos para evitar colisiones.
     *
     * @var array<int, string>
     */
    private const QUOTES = [
        'USDT', 'BUSD', 'USDC', 'TUSD', 'FDUSD', 'DAI', 'USD', 'EUR', 'GBP',
        'BTC', 'ETH', 'BNB', 'TRY', 'JPY', 'BRL', 'MXN', 'ARS',
    ];

    /**
     * Convierte el símbolo normalizado (BTC/USDT) al formato Binance (btcusdt).
     */
    public static function toExchange(string $normalized): string
    {
        return strtolower(str_replace('/', '', $normalized));
    }

    /**
     * Convierte el símbolo Binance crudo (BTCUSDT) al formato normalizado (BTC/USDT).
     */
    public static function normalize(string $rawSymbol): string
    {
        $upper = strtoupper($rawSymbol);
        foreach (self::QUOTES as $quote) {
            if (str_ends_with($upper, $quote) && strlen($upper) > strlen($quote)) {
                $base = substr($upper, 0, -strlen($quote));

                return $base.'/'.$quote;
            }
        }

        return $upper;
    }
}
