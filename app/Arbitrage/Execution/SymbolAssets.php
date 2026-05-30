<?php

declare(strict_types=1);

namespace App\Arbitrage\Execution;

use InvalidArgumentException;

/**
 * Descompone un símbolo normalizado "BASE/QUOTE" (p. ej. BTC/USDT) en sus
 * activos base y quote.
 */
final class SymbolAssets
{
    public function __construct(
        public readonly string $base,
        public readonly string $quote,
    ) {
    }

    public static function fromSymbol(string $symbol): self
    {
        $parts = explode('/', strtoupper(trim($symbol)));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException("Símbolo inválido: {$symbol}");
        }

        return new self($parts[0], $parts[1]);
    }
}
