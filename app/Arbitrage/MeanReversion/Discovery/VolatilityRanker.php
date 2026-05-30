<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Discovery;

use App\Arbitrage\MeanReversion\Stats\RollingPriceWindow;
use App\Infrastructure\MarketData\Exchanges\Binance\BinanceSymbolMapper;

/**
 * Mantiene una ventana de 1h del last-price por símbolo (alimentada por el
 * stream !miniTicker@arr) y rankea las monedas por volatilidad (coeficiente de
 * variación). Es la fuente del "top-N más movido en la última hora" que decide
 * qué streams de profundidad abrir.
 *
 * Solo considera pares contra la quote configurada (USDT) y, opcionalmente,
 * excluye tokens apalancados (UP/DOWN/BULL/BEAR).
 */
final class VolatilityRanker
{
    /** @var array<string, RollingPriceWindow> */
    private array $windows = [];

    private readonly string $quoteSuffix;

    public function __construct(
        private readonly int $windowMs,
        private readonly float $minVolatilityPct,
        private readonly int $minSamples,
        string $quote = 'USDT',
        private readonly bool $excludeLeveraged = true,
        private readonly int $sampleIntervalMs = 1000,
    ) {
        $this->quoteSuffix = '/'.strtoupper($quote);
    }

    /**
     * Ingesta el payload de !miniTicker@arr (array de tickers). Cada entrada
     * trae `s` (símbolo crudo) y `c` (last price).
     *
     * @param  array<int, mixed>  $tickers
     */
    public function ingest(array $tickers, int $nowMs): void
    {
        foreach ($tickers as $ticker) {
            if (! is_array($ticker)) {
                continue;
            }

            $rawSymbol = (string) ($ticker['s'] ?? '');
            $lastPrice = (float) ($ticker['c'] ?? 0.0);
            if ($rawSymbol === '' || $lastPrice <= 0.0) {
                continue;
            }

            $symbol = BinanceSymbolMapper::normalize($rawSymbol);
            if (! $this->isTradable($symbol)) {
                continue;
            }

            $window = $this->windows[$symbol] ??= new RollingPriceWindow($this->windowMs, $this->sampleIntervalMs);
            $window->add($nowMs, $lastPrice);
        }
    }

    /**
     * Devuelve los símbolos más volátiles (desc), filtrados por warmup y
     * volatilidad mínima.
     *
     * @return array<int, string>
     */
    public function topN(int $n): array
    {
        $scored = [];
        foreach ($this->windows as $symbol => $window) {
            if ($window->count() < $this->minSamples) {
                continue;
            }
            $volatility = $window->volatilityPct();
            if ($volatility < $this->minVolatilityPct) {
                continue;
            }
            $scored[$symbol] = $volatility;
        }

        arsort($scored);

        return array_slice(array_keys($scored), 0, max(0, $n));
    }

    public function volatilityFor(string $symbol): float
    {
        return isset($this->windows[$symbol]) ? $this->windows[$symbol]->volatilityPct() : 0.0;
    }

    private function isTradable(string $symbol): bool
    {
        if (! str_ends_with($symbol, $this->quoteSuffix)) {
            return false;
        }

        if ($this->excludeLeveraged) {
            $base = substr($symbol, 0, -strlen($this->quoteSuffix));
            foreach (['UP', 'DOWN', 'BULL', 'BEAR'] as $suffix) {
                if (str_ends_with($base, $suffix)) {
                    return false;
                }
            }
        }

        return true;
    }
}
