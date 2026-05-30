<?php

declare(strict_types=1);

namespace App\Arbitrage\Triangular\Realtime;

use App\Arbitrage\Risk\RiskDecision;
use App\Arbitrage\Triangular\Contracts\CycleDashboardPublisherInterface;
use App\Arbitrage\Triangular\DTO\EvaluatedCycle;
use App\Arbitrage\Triangular\Execution\DTO\CycleSimulationResult;
use App\Events\ArbitrageCycleProcessed;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Publica decisiones de ciclos triangulares al dashboard vía Reverb, con
 * throttle por etiqueta de ciclo para no saturar el canal, y cachea el
 * último snapshot por ciclo para que el frontend tenga estado inicial REST.
 */
final class ReverbCycleDashboardPublisher implements CycleDashboardPublisherInterface
{
    /**
     * @var array<string, int>  cycle key => epoch ms del último broadcast
     */
    private array $lastBroadcastMs = [];

    public function __construct(
        private readonly Dispatcher $events,
        private readonly Cache $cache,
        private readonly string $channelName,
        private readonly int $maxBroadcastsPerSecond,
        private readonly string $snapshotCachePrefix,
        private readonly int $snapshotTtlSeconds,
        private readonly bool $privateChannel = false,
    ) {
    }

    public function publishCycleDecision(
        EvaluatedCycle $cycle,
        RiskDecision $decision,
        ?CycleSimulationResult $simulation = null,
    ): void {
        $payload = [
            'decision' => $decision->decision->value,
            'reasons' => $decision->reasons,
            'cycle' => $cycle->toArray(),
            'simulation' => $simulation?->toArray(),
            'published_at' => now()->toIso8601String(),
        ];

        $cycleKey = $cycle->candidate->key();

        $this->cache->put(
            $this->snapshotKey($cycleKey),
            $payload,
            $this->snapshotTtlSeconds,
        );

        if (! $this->shouldBroadcast($cycleKey)) {
            return;
        }

        $this->events->dispatch(new ArbitrageCycleProcessed($payload, $this->channelName, $this->privateChannel));
    }

    private function shouldBroadcast(string $cycleKey): bool
    {
        if ($this->maxBroadcastsPerSecond <= 0) {
            return true;
        }

        $nowMs = (int) (microtime(true) * 1000);
        $minIntervalMs = (int) (1000 / $this->maxBroadcastsPerSecond);
        $last = $this->lastBroadcastMs[$cycleKey] ?? 0;

        if ($nowMs - $last < $minIntervalMs) {
            return false;
        }

        $this->lastBroadcastMs[$cycleKey] = $nowMs;

        return true;
    }

    private function snapshotKey(string $cycleKey): string
    {
        return $this->snapshotCachePrefix.':cycle:'.md5($cycleKey);
    }
}
