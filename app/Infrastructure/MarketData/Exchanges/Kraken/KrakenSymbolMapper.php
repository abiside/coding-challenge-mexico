<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Kraken;

final class KrakenSymbolMapper
{
    /**
     * Kraken v2 usa el formato BTC/USD; nuestro formato normalizado coincide.
     */
    public static function toExchange(string $normalized): string
    {
        return strtoupper($normalized);
    }

    public static function normalize(string $rawSymbol): string
    {
        return strtoupper($rawSymbol);
    }
}
