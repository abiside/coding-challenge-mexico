<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de dashboard: snapshot de métricas operativas del engine (embudo de
 * descartes, decisiones, snapshots/candidatos procesados). Se emite en cada
 * heartbeat para que la pantalla Engine muestre en vivo por qué se descartan
 * las comparativas. El payload está pre-calculado; el frontend solo lo pinta.
 */
class ArbitrageEngineMetrics implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public string $channelName,
        public bool $private = false,
    ) {
    }

    public function broadcastOn(): array
    {
        return [$this->private ? new PrivateChannel($this->channelName) : new Channel($this->channelName)];
    }

    public function broadcastAs(): string
    {
        return 'arbitrage.engine.metrics';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
