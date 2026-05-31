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

    /** Último precio almacenado (o 0 si la ventana está vacía). */
    public function latest(): float
    {
        $last = count($this->times) - 1;

        return $last >= $this->head ? $this->prices[$last] : 0.0;
    }

    /**
     * Precio más reciente cuyo timestamp es <= $tsMs (búsqueda binaria sobre la
     * serie ordenada). Útil para calcular returns multi-ventana en O(log n).
     */
    public function closeAtOrBefore(int $tsMs): ?float
    {
        $lo = $this->head;
        $hi = count($this->times) - 1;
        if ($hi < $lo) {
            return null;
        }

        $found = null;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->times[$mid] <= $tsMs) {
                $found = $this->prices[$mid];
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $found;
    }

    /**
     * Retorno porcentual entre el precio actual y el de hace $lookbackMs, o null
     * si no hay cobertura suficiente.
     */
    public function returnPct(int $nowMs, int $lookbackMs): ?float
    {
        if ($this->count() < 2 || $this->coverageMs() < $lookbackMs) {
            return null;
        }

        $past = $this->closeAtOrBefore($nowMs - $lookbackMs);
        $current = $this->latest();
        if ($past === null || $past <= 0.0 || $current <= 0.0) {
            return null;
        }

        return (($current - $past) / $past) * 100.0;
    }

    /**
     * Máximo y mínimo de los últimos $lookbackMs (escaneo desde la primera
     * muestra dentro de la ventana). Devuelve [high, low] o null si está vacía.
     *
     * @return array{0: float, 1: float}|null
     */
    public function highLow(int $nowMs, int $lookbackMs): ?array
    {
        $total = count($this->times);
        if ($total - $this->head <= 0) {
            return null;
        }

        $cutoff = $nowMs - $lookbackMs;
        $high = -INF;
        $low = INF;
        for ($i = $this->head; $i < $total; $i++) {
            if ($this->times[$i] < $cutoff) {
                continue;
            }
            $price = $this->prices[$i];
            if ($price > $high) {
                $high = $price;
            }
            if ($price < $low) {
                $low = $price;
            }
        }

        if ($high === -INF) {
            return null;
        }

        return [$high, $low];
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
