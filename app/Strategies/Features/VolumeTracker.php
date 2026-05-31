<?php

declare(strict_types=1);

namespace App\Strategies\Features;

use App\Infrastructure\MarketData\Exchanges\Binance\BinanceSymbolMapper;

/**
 * Rastrea volumen y actividad por símbolo a partir de velas 1m (@kline_1m).
 * Es un recurso COMPARTIDO entre todas las instancias de estrategia (como el
 * VolatilityRanker), porque el volumen de mercado no depende del usuario.
 *
 * Calcula:
 *  - volume_spike: volumen quote de la vela en curso / promedio de las últimas N
 *    velas cerradas.
 *  - trades_per_min: número de trades de la última vela cerrada.
 *  - buy_ratio: proporción de volumen comprador (taker buy) en la vela en curso.
 */
final class VolumeTracker
{
    /** @var array<string, array<int, float>>  symbol => [quote volumes cerrados] */
    private array $history = [];

    /** @var array<string, array{q: float, n: int, buy: float, vol: float}> */
    private array $current = [];

    /** @var array<string, int>  symbol => trades de la última vela cerrada */
    private array $lastClosedTrades = [];

    public function __construct(
        private readonly string $quote = 'USDT',
        private readonly int $historySize = 30,
    ) {
    }

    /**
     * Ingiere un evento de vela (payload `data` del stream @kline_1m de Binance).
     *
     * @param  array<string, mixed>  $data
     */
    public function ingest(array $data): void
    {
        $k = $data['k'] ?? null;
        if (! is_array($k)) {
            return;
        }

        $rawSymbol = (string) ($data['s'] ?? $k['s'] ?? '');
        if ($rawSymbol === '') {
            return;
        }
        $symbol = BinanceSymbolMapper::normalize(strtoupper($rawSymbol));

        $quoteVol = (float) ($k['q'] ?? 0.0);
        $baseVol = (float) ($k['v'] ?? 0.0);
        $trades = (int) ($k['n'] ?? 0);
        $takerBuyQuote = (float) ($k['Q'] ?? 0.0);
        $closed = (bool) ($k['x'] ?? false);

        $this->current[$symbol] = [
            'q' => $quoteVol,
            'n' => $trades,
            'buy' => $takerBuyQuote,
            'vol' => $baseVol,
        ];

        if ($closed) {
            $hist = $this->history[$symbol] ?? [];
            $hist[] = $quoteVol;
            if (count($hist) > $this->historySize) {
                $hist = array_slice($hist, -$this->historySize);
            }
            $this->history[$symbol] = $hist;
            $this->lastClosedTrades[$symbol] = $trades;
        }
    }

    /**
     * Ratio del volumen en curso contra el promedio de las velas cerradas
     * recientes. 1.0 = volumen normal; > 2.0 = spike. 0.0 si no hay historia.
     */
    public function volumeSpike(string $symbol): float
    {
        $hist = $this->history[$symbol] ?? [];
        if ($hist === []) {
            return 0.0;
        }

        $avg = array_sum($hist) / count($hist);
        if ($avg <= 0.0) {
            return 0.0;
        }

        $current = $this->current[$symbol]['q'] ?? 0.0;

        return $current / $avg;
    }

    public function tradesPerMin(string $symbol): float
    {
        return (float) ($this->lastClosedTrades[$symbol] ?? ($this->current[$symbol]['n'] ?? 0));
    }

    /** Proporción de volumen comprador (taker buy) en la vela en curso [0..1]. */
    public function buyRatio(string $symbol): float
    {
        $cur = $this->current[$symbol] ?? null;
        if ($cur === null || $cur['q'] <= 0.0) {
            return 0.5;
        }

        return max(0.0, min(1.0, $cur['buy'] / $cur['q']));
    }
}
