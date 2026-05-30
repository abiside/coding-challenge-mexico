<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\WebSocket;

use Ratchet\Client\WebSocket as PawlWebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;

final class PawlWebSocketConnection implements WebSocketConnection
{
    public function __construct(private readonly PawlWebSocket $socket)
    {
    }

    public function send(string $payload): void
    {
        $this->socket->send($payload);
    }

    public function close(): void
    {
        $this->socket->close();
    }

    public function onMessage(callable $listener): void
    {
        $this->socket->on('message', static function (MessageInterface $message) use ($listener): void {
            $listener((string) $message);
        });
    }

    public function onClose(callable $listener): void
    {
        $this->socket->on('close', static function (?int $code = null, ?string $reason = null) use ($listener): void {
            $listener($code, $reason);
        });
    }

    public function onError(callable $listener): void
    {
        $this->socket->on('error', static function (\Throwable $error) use ($listener): void {
            $listener($error);
        });
    }
}
