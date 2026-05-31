<?php

declare(strict_types=1);

namespace App\Strategies\Realtime;

use App\Events\StrategySignalProcessed;
use App\Models\SimulatedPosition;
use App\Models\StrategySignal as StrategySignalModel;
use App\Strategies\DTO\StrategySignal;
use App\Support\StrategyCacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Capa de persistencia + tiempo real de una instancia de estrategia: persiste
 * señales ejecutadas y posiciones (abiertas/cerradas) en DB, mantiene una lista
 * rodante de señales recientes en cache para el feed REST inicial y publica al
 * canal privado del usuario (con throttle por símbolo).
 */
final class StrategyRecorder
{
    /** @var array<string, int> */
    private array $lastBroadcastMs = [];

    public function __construct(
        private readonly Cache $cache,
        private readonly Dispatcher $events,
        private readonly int $strategyId,
        private readonly int $userId,
        private readonly string $algorithm,
        private readonly string $channelName,
        private readonly int $maxBroadcastsPerSecond = 5,
        private readonly int $snapshotTtlSeconds = 30,
        private readonly int $recentLimit = 40,
        private readonly bool $persistSignals = true,
        private readonly bool $persistPositions = true,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Empuja una señal (detectada/aprobada/rechazada/ejecutada/cerrada) al feed
     * en vivo del panel.
     *
     * @param  array<string, mixed>  $payload
     */
    public function feed(array $payload, string $symbol): void
    {
        $payload['strategy_id'] = $this->strategyId;
        $payload['published_at'] = now()->toIso8601String();
        $this->pushRecent($payload);

        if (! $this->shouldBroadcast($symbol)) {
            return;
        }

        try {
            $this->events->dispatch(new StrategySignalProcessed($payload, $this->channelName));
        } catch (Throwable $e) {
            $this->logger?->warning('[strategies][feed] broadcast falló', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Persiste la señal ejecutada y la posición abierta. Devuelve el id de la
     * posición creada (o null).
     *
     * @param  array<string, mixed>  $position
     */
    public function persistOpen(array $position, StrategySignal $signal): ?int
    {
        if (! $this->persistPositions) {
            return null;
        }

        try {
            $signalId = null;
            if ($this->persistSignals) {
                $row = StrategySignalModel::create([
                    'strategy_id' => $this->strategyId,
                    'user_id' => $this->userId,
                    'algorithm' => $this->algorithm,
                    'symbol' => $signal->symbol,
                    'side' => $signal->side->value,
                    'confidence_score' => $signal->confidenceScore,
                    'entry_price' => $signal->entryPrice,
                    'suggested_size' => $position['size'] ?? 0.0,
                    'take_profit' => $signal->takeProfit,
                    'stop_loss' => $signal->stopLoss,
                    'max_holding_time' => $signal->maxHoldingSeconds,
                    'status' => 'executed',
                    'reasons' => $signal->reasons,
                    'risk_flags' => $signal->riskFlags,
                    'detected_at_ms' => $signal->createdAtMs,
                ]);
                $signalId = (int) $row->id;
            }

            $pos = SimulatedPosition::create([
                'strategy_id' => $this->strategyId,
                'user_id' => $this->userId,
                'strategy_signal_id' => $signalId,
                'algorithm' => $this->algorithm,
                'symbol' => $position['symbol'],
                'side' => $position['side'],
                'entry_price' => $position['entry_price'],
                'size' => $position['size'],
                'notional' => $position['notional'],
                'leverage' => $position['leverage'],
                'take_profit' => $position['take_profit'],
                'stop_loss' => $position['stop_loss'],
                'fees' => $position['fee_open'] ?? 0.0,
                'status' => SimulatedPosition::STATUS_OPEN,
                'open_reason' => $position['open_reason'] ?? null,
                'opened_at_ms' => $position['opened_at_ms'],
                'idempotency_key' => $position['key'],
            ]);

            return (int) $pos->id;
        } catch (Throwable $e) {
            $this->logger?->warning('[strategies][persist] open falló', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Actualiza la posición cerrada en DB (busca por idempotency_key).
     *
     * @param  array<string, mixed>  $close
     */
    public function persistClose(array $close): void
    {
        if (! $this->persistPositions) {
            return;
        }

        try {
            SimulatedPosition::where('idempotency_key', $close['key'])->update([
                'exit_price' => $close['exit_price'],
                'gross_pnl' => $close['gross_pnl'],
                'fees' => $close['fees'],
                'funding_fee' => $close['funding_fee'],
                'net_pnl' => $close['net_pnl'],
                'status' => $close['status'],
                'close_reason' => $close['close_reason'],
                'closed_at_ms' => $close['closed_at_ms'],
            ]);
        } catch (Throwable $e) {
            $this->logger?->warning('[strategies][persist] close falló', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pushRecent(array $payload): void
    {
        try {
            $key = StrategyCacheKeys::recentSignals($this->strategyId);
            $recent = $this->cache->get($key);
            $recent = is_array($recent) ? $recent : [];

            array_unshift($recent, $payload);
            if (count($recent) > $this->recentLimit) {
                $recent = array_slice($recent, 0, $this->recentLimit);
            }

            $this->cache->put($key, $recent, $this->snapshotTtlSeconds * 20);
        } catch (Throwable $e) {
            $this->logger?->warning('[strategies][feed] cache falló', ['error' => $e->getMessage()]);
        }
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
