<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Bybit;

final class BybitSymbolMapper
{
    /**
     * @var array<int, string>
     */
    private const QUOTES = [
        'USDT', 'USDC', 'USD', 'BTC', 'ETH', 'EUR', 'GBP', 'TRY', 'JPY',
    ];

    public static function toExchange(string $normalized): string
    {
        return strtoupper(str_replace('/', '', $normalized));
    }

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
