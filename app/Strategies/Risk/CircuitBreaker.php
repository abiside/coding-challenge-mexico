<?php

declare(strict_types=1);

namespace App\Strategies\Risk;

/**
 * Circuit breaker del módulo (doc sección 7): pausa la apertura de nuevas
 * posiciones cuando las condiciones se degradan (racha de pérdidas, drawdown
 * diario, feed stale). Las salidas (TP/SL/timeout) NUNCA se pausan.
 */
final class CircuitBreaker
{
    private bool $tripped = false;

    private ?string $reason = null;

    private int $trippedAtMs = 0;

    public function __construct(
        private readonly int $maxLossStreak = 5,
        private readonly float $maxDailyDrawdownUsdt = 1000.0,
        private readonly int $cooldownMs = 300000,
    ) {
    }

    /**
     * Evalúa el estado del breaker según la cartera y la salud del feed.
     */
    public function evaluate(PortfolioState $portfolio, bool $feedStale, int $nowMs): void
    {
        // Auto-reset tras el cooldown si las condiciones mejoraron.
        if ($this->tripped && ($nowMs - $this->trippedAtMs) > $this->cooldownMs) {
            $this->reset();
        }

        if ($this->maxLossStreak > 0 && $portfolio->lossStreak >= $this->maxLossStreak) {
            $this->trip(sprintf('loss_streak %d', $portfolio->lossStreak), $nowMs);

            return;
        }

        if ($this->maxDailyDrawdownUsdt > 0.0 && $portfolio->dailyPnl <= -$this->maxDailyDrawdownUsdt) {
            $this->trip(sprintf('daily_drawdown %.2f', $portfolio->dailyPnl), $nowMs);

            return;
        }

        if ($feedStale) {
            $this->trip('feed_stale', $nowMs);
        }
    }

    public function isTripped(): bool
    {
        return $this->tripped;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    private function trip(string $reason, int $nowMs): void
    {
        if (! $this->tripped) {
            $this->trippedAtMs = $nowMs;
        }
        $this->tripped = true;
        $this->reason = $reason;
    }

    public function reset(): void
    {
        $this->tripped = false;
        $this->reason = null;
        $this->trippedAtMs = 0;
    }
}
