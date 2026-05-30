<?php

declare(strict_types=1);

namespace App\Arbitrage\Engine;

/**
 * Tabla de trading fees (taker) por exchange, con fallback "default".
 * Las fees se expresan como fracción decimal (0.001 = 0.1%).
 */
final class FeeSchedule
{
    /**
     * @param  array<string, float>  $feesByExchange
     */
    public function __construct(
        private readonly array $feesByExchange,
        private readonly float $default = 0.001,
    ) {
    }

    public function for(string $exchange): float
    {
        return $this->feesByExchange[strtolower($exchange)] ?? $this->default;
    }
}
