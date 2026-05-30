<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\WebSocket;

use Ratchet\Client\Connector as PawlConnector;
use Ratchet\Client\WebSocket as PawlWebSocket;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

final class PawlWebSocketClient implements WebSocketClient
{
    private readonly PawlConnector $connector;

    public function __construct(LoopInterface $loop)
    {
        $this->connector = new PawlConnector($loop);
    }

    public function connect(string $url): PromiseInterface
    {
        return ($this->connector)($url)->then(
            static fn (PawlWebSocket $ws): WebSocketConnection => new PawlWebSocketConnection($ws)
        );
    }
}
