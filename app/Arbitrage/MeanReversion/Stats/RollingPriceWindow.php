<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Stats;

/**
 * Ventana deslizante de precios para un símbolo, acotada por tiempo.
 *
 * Mantiene sumas corrientes (sum y sumSq) para calcular media, desviación
 * estándar y z-score en O(1). El downsampling (`minIntervalMs`) evita guardar
 * cada tick de 100ms; basta una muestra por segundo para una media de 1h. La
 * eviction usa un puntero `head` y compacta de forma amortizada para no pagar
 * array_shift O(n) en el hot path.
 */
final class RollingPriceWindow
{
    /** @var array<int, int> */
    private array $times = [];

    /** @var array<int, float> */
    private array $prices = [];

    private int $head = 0;

    private float $sum = 0.0;

    private float $sumSq = 0.0;

    private ?int $lastSampleMs = null;

    public function __construct(
        private readonly int $windowMs,
        private readonly int $minIntervalMs = 1000,
    ) {
    }

    public function add(int $tsMs, float $price): void
    {
        if ($price <= 0.0) {
            return;
        }

        // Downsampling: si llegó otra muestra demasiado pronto, solo evictamos.
        if ($this->lastSampleMs !== null && ($tsMs - $this->lastSampleMs) < $this->minIntervalMs) {
            $this->evict($tsMs);

            return;
        }

        $this->lastSampleMs = $tsMs;
        $this->times[] = $tsMs;
        $this->prices[] = $price;
        $this->sum += $price;
        $this->sumSq += $price * $price;

        $this->evict($tsMs);
    }

    public function count(): int
    {
        return count($this->times) - $this->head;
    }

    public function mean(): float
    {
        $count = $this->count();

        return $count > 0 ? $this->sum / $count : 0.0;
    }

    public function stddev(): float
    {
        $count = $this->count();
        if ($count < 2) {
            return 0.0;
        }

        $mean = $this->mean();
        // Var poblacional vía E[x^2] - E[x]^2; clamp por drift de punto flotante.
        $variance = ($this->sumSq / $count) - ($mean * $mean);

        return $variance > 0.0 ? sqrt($variance) : 0.0;
    }

    /** Volatilidad como coeficiente de variación en porcentaje (stddev/mean). */
    public function volatilityPct(): float
    {
        $mean = $this->mean();

        return $mean > 0.0 ? ($this->stddev() / $mean) * 100.0 : 0.0;
    }

    public function zScore(float $price): float
    {
        $stddev = $this->stddev();

        return $stddev > 1e-12 ? ($price - $this->mean()) / $stddev : 0.0;
    }

    /** Desviación del precio respecto a la media, en porcentaje. */
    public function pctDeviation(float $price): float
    {
        $mean = $this->mean();

        return $mean > 0.0 ? (($price - $mean) / $mean) * 100.0 : 0.0;
    }

    /** Span temporal cubierto por las muestras vigentes, en ms. */
    public function coverageMs(): int
    {
        if ($this->count() <= 0) {
            return 0;
        }

        return $this->times[count($this->times) - 1] - $this->times[$this->head];
    }

    private function evict(int $nowMs): void
    {
        $cutoff = $nowMs - $this->windowMs;
        $total = count($this->times);

        while ($this->head < $total && $this->times[$this->head] < $cutoff) {
            $price = $this->prices[$this->head];
            $this->sum -= $price;
            $this->sumSq -= $price * $price;
            $this->head++;
        }

        // Compactación amortizada cuando la cabeza muerta domina el array.
        if ($this->head > 1024 && ($this->head * 2) > $total) {
            $this->times = array_slice($this->times, $this->head);
            $this->prices = array_slice($this->prices, $this->head);
            $this->head = 0;
        }
    }
}
