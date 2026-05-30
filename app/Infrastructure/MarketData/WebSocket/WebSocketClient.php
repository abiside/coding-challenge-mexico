<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\WebSocket;

use React\Promise\PromiseInterface;

interface WebSocketClient
{
    /**
     * @return PromiseInterface<WebSocketConnection>
     */
    public function connect(string $url): PromiseInterface;
}
