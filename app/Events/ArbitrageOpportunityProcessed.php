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
 * Evento de dashboard: oportunidad ya procesada por el engine. El payload está
 * pre-calculado; el frontend solo lo renderiza.
 */
class ArbitrageOpportunityProcessed implements ShouldBroadcastNow
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
        return 'arbitrage.opportunity.processed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
