<?php

declare(strict_types=1);

namespace App\Arbitrage\MeanReversion\Realtime;

use App\Arbitrage\MeanReversion\Contracts\SignalRecorderInterface;
use App\Arbitrage\MeanReversion\DTO\ProcessedSignal;
use App\Arbitrage\Risk\Decision;
use App\Events\MeanReversionSignalProcessed;
use App\Support\MeanReversionCacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Publica al panel cada señal accionada (ejecutada o rechazada) vía Reverb, con
 * throttle por símbolo, y mantiene una lista rodante de las últimas señales en
 * cache para que el dashboard tenga estado inicial vía REST. Los "ignore" (ruido
 * de cooldown/sin inventario) no se publican.
 */
final class BroadcastSignalRecorder implements SignalRecorderInterface
{
    /** @var array<string, int>  symbol => epoch ms del último broadcast */
    private array $lastBroadcastMs = [];

    public function __construct(
        private readonly Dispatcher $events,
        private readonly Cache $cache,
        private readonly int $userId,
        private readonly string $channelName,
        private readonly int $maxBroadcastsPerSecond,
        private readonly int $snapshotTtlSeconds,
        private readonly int $recentLimit,
    ) {
    }

    public function record(ProcessedSignal $processed): void
    {
        $decision = $processed->decision->decision;
        if ($decision === Decision::Ignore) {
            return;
        }

        $payload = [
            'decision' => $decision->value,
            'reasons' => $processed->decision->reasons,
            'signal' => $processed->candidate->toArray(),
            'simulation' => $processed->simulation?->toArray(),
            'published_at' => now()->toIso8601String(),
        ];

        $this->pushRecent($payload);

        if (! $this->shouldBroadcast($processed->candidate->symbol)) {
            return;
        }

        $this->events->dispatch(new MeanReversionSignalProcessed($payload, $this->channelName));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pushRecent(array $payload): void
    {
        $key = MeanReversionCacheKeys::recentSignals($this->userId);
        $recent = $this->cache->get($key);
        $recent = is_array($recent) ? $recent : [];

        array_unshift($recent, $payload);
        if (count($recent) > $this->recentLimit) {
            $recent = array_slice($recent, 0, $this->recentLimit);
        }

        $this->cache->put($key, $recent, $this->snapshotTtlSeconds * 20);
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
}
