<?php

declare(strict_types=1);

namespace App\Strategies\Engine;

/**
 * Contadores en memoria de una instancia de estrategia: embudo de señales,
 * posiciones, win rate, P&L realizado, racha de pérdidas y P&L del día (para el
 * circuit breaker y el dashboard).
 */
final class StrategyMetrics
{
    private int $snapshots = 0;

    private int $signalsDetected = 0;

    private int $signalsApproved = 0;

    private int $signalsRejected = 0;

    private int $positionsOpened = 0;

    private int $positionsClosed = 0;

    private int $wins = 0;

    private int $losses = 0;

    private float $realizedPnl = 0.0;

    private int $lossStreak = 0;

    private float $dailyPnl = 0.0;

    private string $dailyKey;

    public function __construct(float $realizedPnl = 0.0)
    {
        $this->realizedPnl = $realizedPnl;
        $this->dailyKey = date('Y-m-d');
    }

    public function recordSnapshot(): void
    {
        $this->snapshots++;
    }

    public function recordDetected(): void
    {
        $this->signalsDetected++;
    }

    public function recordApproved(): void
    {
        $this->signalsApproved++;
    }

    public function recordRejected(): void
    {
        $this->signalsRejected++;
    }

    public function recordOpen(): void
    {
        $this->positionsOpened++;
    }

    public function recordClose(float $netPnl): void
    {
        $this->positionsClosed++;
        $this->realizedPnl += $netPnl;
        $this->rollDay();
        $this->dailyPnl += $netPnl;

        if ($netPnl >= 0.0) {
            $this->wins++;
            $this->lossStreak = 0;
        } else {
            $this->losses++;
            $this->lossStreak++;
        }
    }

    public function realizedPnl(): float
    {
        return $this->realizedPnl;
    }

    public function lossStreak(): int
    {
        return $this->lossStreak;
    }

    public function dailyPnl(): float
    {
        $this->rollDay();

        return $this->dailyPnl;
    }

    public function reset(): void
    {
        $this->snapshots = 0;
        $this->signalsDetected = 0;
        $this->signalsApproved = 0;
        $this->signalsRejected = 0;
        $this->positionsOpened = 0;
        $this->positionsClosed = 0;
        $this->wins = 0;
        $this->losses = 0;
        $this->realizedPnl = 0.0;
        $this->lossStreak = 0;
        $this->dailyPnl = 0.0;
        $this->dailyKey = date('Y-m-d');
    }

    private function rollDay(): void
    {
        $today = date('Y-m-d');
        if ($today !== $this->dailyKey) {
            $this->dailyKey = $today;
            $this->dailyPnl = 0.0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $closed = $this->positionsClosed;
        $winRate = $closed > 0 ? $this->wins / $closed : 0.0;

        return [
            'snapshots_processed' => $this->snapshots,
            'signals_detected' => $this->signalsDetected,
            'signals_approved' => $this->signalsApproved,
            'signals_rejected' => $this->signalsRejected,
            'positions_opened' => $this->positionsOpened,
            'positions_closed' => $this->positionsClosed,
            'wins' => $this->wins,
            'losses' => $this->losses,
            'win_rate' => round($winRate, 4),
            'realized_pnl' => round($this->realizedPnl, 4),
            'loss_streak' => $this->lossStreak,
            'daily_pnl' => round($this->dailyPnl(), 4),
        ];
    }
}
