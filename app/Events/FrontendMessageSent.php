<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FrontendMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $payload)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('frontend-messages')];
    }

    public function broadcastAs(): string
    {
        return 'frontend.message.sent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
