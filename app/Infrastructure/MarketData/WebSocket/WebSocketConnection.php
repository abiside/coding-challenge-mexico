<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\WebSocket;

interface WebSocketConnection
{
    public function send(string $payload): void;

    public function close(): void;

    /**
     * @param  callable(string $message):void  $listener
     */
    public function onMessage(callable $listener): void;

    /**
     * @param  callable(?int $code, ?string $reason):void  $listener
     */
    public function onClose(callable $listener): void;

    /**
     * @param  callable(\Throwable $error):void  $listener
     */
    public function onError(callable $listener): void;
}
