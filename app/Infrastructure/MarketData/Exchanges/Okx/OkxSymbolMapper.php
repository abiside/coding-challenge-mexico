<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Exchanges\Okx;

final class OkxSymbolMapper
{
    public static function toExchange(string $normalized): string
    {
        return strtoupper(str_replace('/', '-', $normalized));
    }

    public static function normalize(string $rawSymbol): string
    {
        return strtoupper(str_replace('-', '/', $rawSymbol));
    }
}
