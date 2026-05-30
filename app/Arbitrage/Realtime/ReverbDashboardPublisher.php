<?php

declare(strict_types=1);

namespace App\Arbitrage\Realtime;

use App\Arbitrage\Contracts\DashboardPublisherInterface;
use App\Arbitrage\Engine\DTO\EvaluatedOpportunity;
use App\Arbitrage\Execution\DTO\SimulationResult;
use App\Arbitrage\Risk\RiskDecision;
use App\Events\ArbitrageOpportunityProcessed;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Publica decisiones del engine al dashboard vía Reverb, con throttle por
 * símbolo para no saturar el canal, y guarda el último snapshot por símbolo en
 * cache para que el frontend tenga estado inicial vía REST.
 */
final class ReverbDashboardPublisher implements DashboardPublisherInterface
{
    /**
     * @var array<string, int>  symbol => epoch ms del último broadcast
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

    public function publishDecision(
        EvaluatedOpportunity $opportunity,
        RiskDecision $decision,
        ?SimulationResult $simulation = null,
    ): void {
        $payload = [
            'decision' => $decision->decision->value,
            'reasons' => $decision->reasons,
            'opportunity' => $opportunity->toArray(),
            'simulation' => $simulation?->toArray(),
            'published_at' => now()->toIso8601String(),
        ];

        $symbol = $opportunity->symbol();

        // Snapshot siempre actualizado en cache para el primer render REST.
        $this->cache->put(
            $this->snapshotKey($symbol),
            $payload,
            $this->snapshotTtlSeconds,
        );

        if (! $this->shouldBroadcast($symbol)) {
            return;
        }

        $this->events->dispatch(new ArbitrageOpportunityProcessed($payload, $this->channelName, $this->privateChannel));
    }

    private function shouldBroadcast(string $symbol): bool
    {
        if ($this->maxBroadcastsPerSecond <= 0) {
            return true;
        }

        $nowMs = (int) (microtime(true) * 1000);
        $minIntervalMs = (int) (1000 / $this->maxBroadcastsPerSecond);
        $last = $this->lastBroadcastMs[$symbol] ?? 0;

        if ($nowMs - $last < $minIntervalMs) {
            return false;
        }

        $this->lastBroadcastMs[$symbol] = $nowMs;

        return true;
    }

    private function snapshotKey(string $symbol): string
    {
        return $this->snapshotCachePrefix.':'.strtolower(str_replace('/', '-', $symbol));
    }
}
