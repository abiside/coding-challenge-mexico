<?php

declare(strict_types=1);

namespace App\Arbitrage\Realtime;

use App\Arbitrage\Contracts\DiscardRecorderInterface;
use App\Arbitrage\Risk\Decision;

/**
 * Acumula métricas operativas del engine en memoria para heartbeat/dashboard.
 */
final class MetricsAggregator implements DiscardRecorderInterface
{
    private int $snapshotsProcessed = 0;

    private int $candidatesDetected = 0;

    /**
     * Embudo de descartes: razón normalizada => conteo en la ventana actual.
     * Explica por qué no se dispara ninguna evaluación (spread no cruzado,
     * sin liquidez, sin volumen ejecutable, rechazos de risk manager, etc.).
     *
     * @var array<string, int>
     */
    private array $discards = [];

    /**
     * @var array<string, int>
     */
    private array $decisions = [
        'execute' => 0,
        'reject' => 0,
        'ignore' => 0,
    ];

    private float $realizedPnl = 0.0;

    private int $executions = 0;

    private float $executedVolume = 0.0;

    private float $marginSum = 0.0;

    private int $windowStartMs;

    public function __construct(?int $windowStartMs = null)
    {
        $this->windowStartMs = $windowStartMs ?? (int) (microtime(true) * 1000);
    }

    public function recordSnapshot(): void
    {
        $this->snapshotsProcessed++;
    }

    public function recordCandidate(): void
    {
        $this->candidatesDetected++;
    }

    public function recordDecision(Decision $decision): void
    {
        $this->decisions[$decision->value]++;
    }

    public function recordDiscard(string $reason): void
    {
        $this->discards[$reason] = ($this->discards[$reason] ?? 0) + 1;
    }

    /**
     * @return array<string, int>
     */
    public function discards(): array
    {
        arsort($this->discards);

        return $this->discards;
    }

    public function recordExecution(float $pnl, float $baseVolume = 0.0, float $margin = 0.0): void
    {
        $this->executions++;
        $this->realizedPnl += $pnl;
        $this->executedVolume += $baseVolume;
        $this->marginSum += $margin;
    }

    public function windowStartMs(): int
    {
        return $this->windowStartMs;
    }

    public function executedVolume(): float
    {
        return $this->executedVolume;
    }

    public function realizedPnl(): float
    {
        return $this->realizedPnl;
    }

    public function avgMargin(): float
    {
        return $this->executions > 0 ? $this->marginSum / $this->executions : 0.0;
    }

    /**
     * Vacía las métricas acumuladas y reinicia la ventana al momento actual.
     * Devuelve un snapshot inmutable de lo que se acumuló desde el último
     * reset, listo para persistir en strategy_evaluations.
     *
     * @return array<string, mixed>
     */
    public function drain(?int $windowEndMs = null): array
    {
        $endMs = $windowEndMs ?? (int) (microtime(true) * 1000);
        $snapshot = [
            'window_start_ms' => $this->windowStartMs,
            'window_end_ms' => $endMs,
            'snapshots' => $this->snapshotsProcessed,
            'candidates' => $this->candidatesDetected,
            'executions' => $this->executions,
            'rejects' => $this->decisions['reject'],
            'ignores' => $this->decisions['ignore'],
            'realized_pnl' => round($this->realizedPnl, 8),
            'executed_volume' => round($this->executedVolume, 12),
            'avg_margin' => round($this->avgMargin(), 10),
            'discards' => $this->discards(),
        ];

        $this->snapshotsProcessed = 0;
        $this->candidatesDetected = 0;
        $this->decisions = ['execute' => 0, 'reject' => 0, 'ignore' => 0];
        $this->discards = [];
        $this->realizedPnl = 0.0;
        $this->executions = 0;
        $this->executedVolume = 0.0;
        $this->marginSum = 0.0;
        $this->windowStartMs = $endMs;

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'snapshots_processed' => $this->snapshotsProcessed,
            'candidates_detected' => $this->candidatesDetected,
            'decisions' => $this->decisions,
            'discards' => $this->discards(),
            'executions' => $this->executions,
            'realized_pnl' => round($this->realizedPnl, 8),
            'executed_volume' => round($this->executedVolume, 12),
            'avg_margin' => round($this->avgMargin(), 10),
        ];
    }
}
