<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Stats;

/**
 * Mapa `symbol -> RollingPriceWindow`. Como el worker opera un solo exchange
 * (una billetera), basta indexar por símbolo normalizado.
 */
final class PriceWindowStore
{
    /** @var array<string, RollingPriceWindow> */
    private array $windows = [];

    public function __construct(
        private readonly int $windowMs,
        private readonly int $minIntervalMs = 1000,
    ) {
    }

    public function record(string $symbol, int $tsMs, float $price): RollingPriceWindow
    {
        $window = $this->windows[$symbol] ??= new RollingPriceWindow($this->windowMs, $this->minIntervalMs);
        $window->add($tsMs, $price);

        return $window;
    }

    public function get(string $symbol): ?RollingPriceWindow
    {
        return $this->windows[$symbol] ?? null;
    }
}
