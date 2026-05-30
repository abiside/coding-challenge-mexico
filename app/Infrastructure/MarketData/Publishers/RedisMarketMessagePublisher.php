<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Publishers;

use App\Domain\MarketData\Contracts\MarketMessagePublisher;
use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publica los DTOs normalizados en canales de Redis y guarda el último estado
 * en cache key/value para lectura rápida desde la app.
 */
final class RedisMarketMessagePublisher implements MarketMessagePublisher
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly LoggerInterface $logger,
        private readonly string $connection = 'default',
        private readonly string $channelPrefix = 'market',
        private readonly int $latestStateTtlSeconds = 300,
    ) {
    }

    public function publishTick(MarketTick $tick): void
    {
        $payload = json_encode($tick->toArray(), JSON_THROW_ON_ERROR);
        $channel = sprintf(
            '%s:ticker:%s:%s',
            $this->channelPrefix,
            $tick->exchange,
            $this->safeSymbol($tick->symbol)
        );
        $this->dispatch($channel, $payload);
    }

    public function publishOrderBook(OrderBookSnapshot $snapshot): void
    {
        $payload = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);
        $channel = sprintf(
            '%s:orderbook:%s:%s',
            $this->channelPrefix,
            $snapshot->exchange,
            $this->safeSymbol($snapshot->symbol)
        );
        $this->dispatch($channel, $payload);
    }

    private function dispatch(string $channel, string $payload): void
    {
        try {
            $connection = $this->redis->connection($this->connection);
            $connection->publish($channel, $payload);
            $connection->setex($channel.':latest', $this->latestStateTtlSeconds, $payload);
        } catch (Throwable $e) {
            $this->logger->warning('[market-feed] error publicando en redis', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function safeSymbol(string $symbol): string
    {
        return strtolower(str_replace('/', '-', $symbol));
    }
}
